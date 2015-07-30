<?php

/**
 * Tracking Background Jobs
 *
 * @author Siquo
 * @copyright 2014 Greenhost
 * @package backjob
 * @version 0.45
 * @license New BSD License
 *
 *
 *
 * Copyright (c) 2014, Greenhost
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice, this
 * list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation and/or
 * other materials provided with the distribution.
 *
 * 3. Neither the name of Greenhost nor the names of its contributors may be
 * used to endorse or promote products derived from this software without specific
 * prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
 * INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF
 * LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE
 * OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED
 * OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 */
class EBackJob extends CApplicationComponent {

	const STATUS_STARTED = 0;
	const STATUS_INPROGRESS = 1;
	const STATUS_COMPLETED = 2;
	const STATUS_FAILED = 3;

	/**
	 * Database connection
	 * @var string
	 */
	public $db = 'db';

	/**
	 * Cache to be used
	 * @var string
	 */
	public $ch = 'cache';

	/**
	 * Should we use the cache?
	 * @var boolean
	 */
	public $useCache = true;

	/**
	 * Should we use the database?
	 * @var boolean
	 */
	public $useDb = true;

	/**
	 * Database table name to be used
	 * @var string
	 */
	public $tableName = 'e_background_job';

	/**
	 * Check if he database exists, and create it if it doesn't? (Set to false in production!)
	 * @var boolean
	 */
	public $checkAndCreateTable = false;

	/**
	 * Cache ID Prefix so we don't interfere with other cache-items
	 * @var string
	 */
	public $cachePrefix = "EBackJobPrefix-";

	/**
	 * User agent used in the background request
	 * @var string
	 */
	public $userAgent = 'Mozilla/5.0 Firefox/3.6.12';

	/**
	 * Number of seconds after which an error-timeout occurs.
	 * @var integer
	 */
	public $errorTimeout = 120;

	/**
	 * Number of days we keep a backlog of database entries of succesfully completed requests. Set to 0 to disable cleanup entirely
	 * @var integer
	 */
	public $backlogDays = 30;

	/**
	 * Number of days we keep a backlog of database entries of all requests. Set to 0 to disable cleanup entirely
	 * @var integer
	 */
	public $allBacklogDays = 60;

	/**
	 * If we're inside a jobrequest, this is the current ID
	 * @var integer
	 */
	public $currentJobId;
	
	/**
	 * Unique key for your application
	 * @var string 
	 */
	public $key = 'sjs&sk&F89fksL*987sdKf';
	
	private $_db;
	private $_ch;

	/**
	 * Initialize properties.
	 */
	public function init() {
		if ($this->checkAndCreateTable && $this->useDb && !$this->database->schema->getTable($this->tableName))
			$this->createTable();


		// We're in a background request? Register events
		if ($this->isInternalRequest()) {
			set_time_limit($this->errorTimeout + 5);
			if ($this->isMonitorRequest()) {
				$this->currentJobId = $_GET['_e_back_job_monitor_id'];
				$this->monitor();
			} else {
				$this->currentJobId = $_REQUEST['_e_back_job_id'];
				Yii::app()->onBeginRequest = array($this, 'startRequest');
				Yii::app()->onEndRequest = array($this, 'endRequest');
			}
		}

		parent::init();
	}

	/**
	 * Callback function at the start of a background request
	 * @param CEvent $event
	 */
	public function startRequest($event) {
		ignore_user_abort(true);
		// Turn off web route for logging
		if (isset(Yii::app()->log->routes['cweb']))
			Yii::app()->log->routes['cweb']->enabled = false;
		$this->update(array('progress' => 0));
		ob_start();
	}

	/**
	 * Callback function at the end of a background request
	 * @param CEvent $event
	 */
	public function endRequest($event) {
		$content = ob_get_clean();
		if ($error = Yii::app()->errorHandler->error) {
			$this->fail(array(
				'status_text' => $content . var_export($error['message'], true)
			));
		} else {
			$this->finish(array(
				'status_text' => $content
			));
		}
	}

	/**
	 * Returns current status of the background job as an array with keys
	 * ('progress','status_text','status')
	 * @param integer $jobId
	 * @return false|array The status of this job
	 */
	public function getStatus($jobId) {
		$ret = false;
		if ($jobId) {
			if ($this->useCache)
				$ret = $this->cache[$this->cachePrefix . $jobId];
			if (!$ret && $this->useDb) {
				$ret = $this->database->createCommand()
								->select('*')
								->from($this->tableName)
								->where('id=:id')->queryRow(true, array(':id' => $jobId));
				if ($ret && $this->useCache) { // Update the cache with all the data
					$this->cache[$this->cachePrefix . $jobId] = $ret;
				}
			}
		}
		if (!is_array($ret)) {
			$ret = array();
		}

		// also set defaults
		$ret = array_merge(array(
			'progress' => 0,
			'status' => self::STATUS_STARTED,
			'start_time' => date('Y-m-d H:i:s'),
			'updated_time' => date('Y-m-d H:i:s'),
			'status_text' => '',
			'request' => ''
		), $ret);

		// Check for a timeout error
		if ($jobId &&
				$ret['status'] < self::STATUS_COMPLETED &&
				(strtotime($ret['updated_time']) + ($this->errorTimeout)) < time()) {
			$this->fail(array("status_text" => $ret['status_text'] . "<br/><strong>Error: job timeout</strong>"), $jobId);
			$ret = $this->getStatus($jobId);
		}

		return $ret;
	}

	/**
	 * Start a new background job. Returns ID of that job.
	 * @param string|array $route Route to controller/action
	 * @param boolean Run job as the current user? (Default = true)
	 * @param integer $timedelay Seconds to postpone 
	 * @return integer Id of the new job
	 */
	public function start($route, $asCurrentUser = true, $timedelay = 0) {
		return $this->runMonitor($route, $asCurrentUser);
	}

	/**
	 * Update a job's status. Either provide a status array or a progress percentage
	 * @param array|integer $status
	 * @param integer $jobId
	 */
	public function update($status = array(), $jobId = false) {

		if (!$jobId)
			$jobId = $this->currentJobId;

		Yii::trace("Updating status for job: " . $jobId . " args: " . var_export($status, true), "application.EBackJob");
		if ($jobId) {
			if (!is_array($status)) {
				$status = array('progress' => $status);
			}
			$this->setStatus($jobId, array_merge(
							array(
				'updated_time' => date('Y-m-d H:i:s'),
				'status' => self::STATUS_INPROGRESS,
				'status_text' => ob_get_contents(),
							), $status
			));
		}
	}

	/**
	 * Update a job's status by incrementing the status by $amount
	 * @param array|integer $status
	 * @param integer $jobId
	 */
	public function updateIncrement($amount, $jobId = false) {
		if (!$jobId)
			$jobId = $this->currentJobId;
		$st = $this->getStatus($jobId);
		$this->update($amount + $st['progress']);
	}

	/**
	 * Finish a job (alias for "update as finished")
	 * @param integer $jobId
	 * @param array $status
	 */
	public function finish($status = array(), $jobId = false) {
		if (!$jobId)
			$jobId = $this->currentJobId;
		$job = $this->getStatus($jobId);
		if ($job['status'] < self::STATUS_COMPLETED) {
			$this->update(array_merge(
							array(
				'progress' => 100,
				'end_time' => date('Y-m-d H:i:s'),
				'status' => self::STATUS_COMPLETED,
							), $status
					), $jobId);
		}
		$this->cleanDb(); // cleanup of Old items		
	}

	/**
	 * Fail a job (alias for "update as finished with a fail status")
	 * @param integer $jobId
	 * @param array $status
	 */
	public function fail($status = array(), $jobId = false) {
		if (is_string($status))
			$status = array('status_text' => $status);
		if (!$jobId)
			$jobId = $this->currentJobId;
		$this->update(array_merge(
						array(
							'end_time' => date('Y-m-d H:i:s'),
							'status' => self::STATUS_FAILED,
						), $status
				), $jobId);
		Yii::app()->end();
	}

	/**
	 * Set status of a certain job
	 * @param integer $jobId
	 * @param array $status
	 */
	public function setStatus($jobId, $status) {
		if ($this->useCache)
			$this->setCacheStatus($jobId, $status);
		if ($this->useDb)
			$this->setDbStatus($jobId, $status);
	}

	/**
	 * Get database that was configured
	 * @return CDbConnection
	 */
	public function getDatabase() {
		if (!isset($this->_db)) {
			$db = $this->db;
			$this->_db = Yii::app()->$db;
		}
		return $this->_db;
	}

	/**
	 * Get Cache that was configured
	 * @return CCache
	 */

	public function getCache() {
		if (!isset($this->_ch)) {
			$cache = $this->ch;
			$this->_ch = Yii::app()->$cache;
		}
		return $this->_ch;
	}

	/**
	 * Perform status changes to cache
	 * @param integer $jobId
	 * @param array $status
	 */
	private function setCacheStatus($jobId, $status) {
		$a = $this->cache[$this->cachePrefix . $jobId];
		if (!$a)
			$a = array();
		$this->cache[$this->cachePrefix . $jobId] = array_merge($a, $status);
	}

	/**
	 * Perform status changes to database
	 * @param integer $jobId
	 * @param array $status
	 */
	private function setDbStatus($jobId, $status) {
		$this->database->createCommand()->update($this->tableName, $status, 'id=:id', array(':id' => $jobId));
	}

	/**
	 * Create a status, returns its ID
	 * @param array $status
	 * @return integer The new ID
	 */
	private function createStatus($status = array()) {
		$jobId = false;
		$status = array_merge($this->getStatus(false), $status);
		if ($this->useDb) {
			$this->database->createCommand()->insert($this->tableName, $status);
			$jobId = $this->database->lastInsertId;
		}
		if ($this->useCache) {
			if (!$jobId)
				$jobId = $this->getNewCacheId();
			$this->setCacheStatus($jobId, $status);
		}
		return $jobId;
	}

	/**
	 * Get a new unique cache id for a new job
	 * @return integer
	 */
	private function getNewCacheId() {
		$cid = $this->cachePrefix . 'maxid';
		if (!$this->cache[$cid])
			$this->cache[$cid] = 0;
		while ($this->cache[$this->cachePrefix . $this->cache[$cid]])
			$this->cache[$cid] = $this->cache[$cid] + 1;
		return $this->cache[$cid];
	}

	/**
	 * Create the table used for storing jobs DB-side.
	 */
	private function createTable() {
		$this->database->createCommand(
				$this->database->schema->createTable(
						$this->tableName, array(
							'id' => 'pk',
							'progress' => 'integer',
							'status' => 'integer',
							'start_time' => 'timestamp DEFAULT CURRENT_TIMESTAMP ',
							'updated_time' => 'timestamp',
							'end_time' => 'timestamp',
							'request' => 'text',
							'status_text' => 'text',
						)
				)
		)->execute();
	}

	/**
	 * The monitor thread. Starts a background request and reports on its progress or failure.
	 */
	protected function monitor() {
		$jobId = $this->currentJobId;
		$job = $this->getStatus($jobId);

		// If the start time is in the future, wait for that time (and re-check again)
		while (($job = $this->getStatus($jobId)) && strtotime($job['start_time']) > time()) {
			set_time_limit($this->errorTimeout + (strtotime($job['start_time']) - time()) + 5);
			sleep((strtotime($job['start_time']) - time()));
		}

		if ($job['request']) {
			$result = $this->runAction(json_decode($job['request'], true), $jobId);

			if ($result !== true) {
				$job = $this->getStatus($jobId);
				$this->fail(array(
					'status_text' => $job['status_text'] . '<br>' . $result
				));
			}
			$this->finish(); // make sure it's finished if it's not finished or failed already
		} else {
			$this->fail(array('status_text' => 'Error: Request not found' . $jobId . var_export($job, true)));
		}

		Yii::app()->end();
	}

	/**
	 * Start a new monitor and run it in the background
	 * @param string|array $request The request (as array or route-string)
	 * @param boolean Run job as the current user? (Default = true)
	 * @return string Job-ID: the job id through which the job can be monitored
	 */
	protected function runMonitor($request, $asCurrentUser = true) {
		if (!is_array($request)) {
			$request = array($request);
		}
		list($route, $params) = $this->requestToRoute($request);
		$jobId = $this->createStatus(array('request' => json_encode($request)));

		$params['_e_back_job_monitor_id'] = $jobId;
		$params['_e_back_job_id'] = $jobId;

		$return = $this->doRequest($route, $params, $asCurrentUser, true);

		if ($return !== true) {
			$this->fail(array(
				'status_text' => $return,
					), $jobId);
		}
		return $jobId;
	}

	/**
	 * Start a new job and run it in the foreground (this is run from within the monitor)
	 * @param string|array $request The request (as array or route-string)
	 * @param boolean Run job as the current user? (Default = true)
	 * @return string Job-ID: the job id through which the job can be monitored
	 */
	protected function runAction($request, $jobId) {
		list($route, $params) = $this->requestToRoute($request);
		$params['_e_back_job_id'] = $jobId;

		return($this->doRequest($route, $params));
	}

	/**
	 * Make a request to the specified route
	 * @param string $route Yii route to the action to run
	 * @param array $request Optional array of GET/POST parameters
	 * @param boolean Run job as the current user? (Default = true)
	 * @return boolean|string Returns either error message or true
	 */
	protected function doRequest($route, $request = array(), $asCurrentUser = true, $async = false) {
		$method = isset($request['backjobMethod']) ? $request['backjobMethod'] : 'GET';

		if ($this->isMonitorRequest()) {
			$postdata = file_get_contents("php://input");
			unset($request['backjobMethod']);
		} elseif (isset($request['backjobPostdata'])) {
			$postdata = http_build_query($request['backjobPostdata']);
			unset($request['backjobPostdata']);
		} else {
			$postdata = '';
		}
		
		$request['_e_back_job_check'] = md5($request['_e_back_job_id'] . $this->key);

		$uri = Yii::app()->createAbsoluteUrl($route, $request);
		$uri = '/' . preg_replace('/https?:\/\/(.)*?\//', '', $uri);

		$port = Yii::app()->request->serverPort;
		$host = Yii::app()->request->serverName;

		/* Overcome proxies, force 443 if system thinks it's 80 and still secure connected */
		if ((Yii::app()->request->isSecureConnection) && $port == 80) {
			$port = 443;
		}

		if (($fp = fsockopen(($port == 443 ? 'ssl://' : '') . $host, $port, $errno, $errstr, 1000)) == false) {
			return "Error $errno: $errstr";
		}
		// Come to the dark side! We have
		$cookies = '';
		if ($asCurrentUser)
			foreach (Yii::app()->request->cookies as $k => $v)
				$cookies .= urlencode($k) . '=' . urlencode($v) . '; ';

		$lf = "\r\n";
		//'Host: ' . $host . ($port ? ':' : '') . $port . $lf .
		$req = $method . ' ' . $uri . ' HTTP/1.1' . $lf .
				'Host: ' . $host . $lf .
				'User-Agent: ' . $this->userAgent . $lf .
				'Cache-Control: no-store, no-cache, must-revalidate' . $lf .
				'Cache-Control: post-check=0, pre-check=0' . $lf .
				'Pragma: no-cache' . $lf .
				($cookies ? 'Cookie: ' . $cookies . $lf : '');

		if ($postdata) {
			$req .= 'Content-Type: application/x-www-form-urlencoded' . $lf .
					'Content-Length: ' . strlen($postdata) . $lf .
					'Connection: Close' . $lf . $lf .
					$postdata;
		} else {
			$req .= 'Connection: Close' . $lf . $lf;
		}


		Yii::trace("Running background request: " . $req, "application.EBackJob");
		// Do the request
		fwrite($fp, $req);

		// Echo results if we're not async
		if (!$async)
			while (!feof($fp))
				echo fgets($fp, 128);

		// Close connection
		fclose($fp);
		return true;
	}

	/**
	 * We're an internal request if localhost made the request and we have the url-id for a job
	 * @return boolean
	 */
	public function isInternalRequest() {
		return (isset($_REQUEST['_e_back_job_id']) && 
				isset($_REQUEST['_e_back_job_check']) &&
				$_REQUEST['_e_back_job_check'] === md5($_REQUEST['_e_back_job_id'] . $this->key));
	}

	/**
	 * Check if we're a monitor-thread
	 * @return boolean
	 */
	private function isMonitorRequest() {
		return $this->isInternalRequest() && isset($_GET['_e_back_job_monitor_id']);
	}

	/**
	 * Transform a request to a route and a parameter array
	 * @param array|string $request
	 * @return array
	 */
	private function requestToRoute($request) {
		$params = array();
		if (is_array($request)) {
			$route = $request[0];
			$params = $request;
			unset($params[0]);
		} else {
			$route = $request;
			$request = array($route);
		}
		return array($route, $params);
	}

	/**
	 * Clear old database entries that have completed to limit the amount of backlog
	 */
	private function cleanDb() {
		if ($this->useDb && $this->backlogDays) {
			$this->database->createCommand()->delete($this->tableName, 'end_time < DATE_SUB(NOW(), INTERVAL :history DAY) AND status = :status', array(
				':history' => $this->backlogDays,
				':status' => self::STATUS_COMPLETED,
			));
		}
		if ($this->useDb && $this->allBacklogDays) {
			$this->database->createCommand()->delete($this->tableName, 'end_time < DATE_SUB(NOW(), INTERVAL :history DAY)', array(
				':history' => $this->backlogDays
			));
		}
	}

}
