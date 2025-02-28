<?php

/**
 * Tracking Background Jobs
 *
 * @author    Siquo
 * @copyright 2014 Greenhost
 * @package   backjob
 * @license   New BSD License
 *
 *
 *
 * Copyright (c) 2014, Greenhost All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 *
 * 3. Neither the name of Greenhost nor the names of its contributors may be
 *    used to endorse or promote products derived from this software without
 *    specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */
class EBackJob extends CApplicationComponent {

    public const STATUS_STARTED = 0;
    public const STATUS_INPROGRESS = 1;
    public const STATUS_COMPLETED = 2;
    public const STATUS_FAILED = 3;

    /**
     * Name of the database connection component in this Yii application
     *
     * @var string
     */
    public $databaseComponent = 'db';

    /**
     * Name of the cache component in this the Yii application
     *
     * @var string
     */
    public $cacheComponent = 'cache';

    /**
     * Should we use the cache?
     *
     * @var bool
     */
    public $useCache = true;

    /**
     * Should we use the database?
     *
     * @var bool
     */
    public $useDb = true;

    /**
     * Database table name to be used
     *
     * @var string
     */
    public $tableName = 'e_background_job';

    /**
     * Whether to check that the database exists, and create it if it does not.
     * Set it to false in production!
     *
     * @var bool
     */
    public $checkAndCreateTable = false;

    /**
     * Cache ID Prefix so we don't interfere with other cache-items
     *
     * @var string
     */
    public $cachePrefix = "EBackJobPrefix-";

    /**
     * User agent used in the background request
     *
     * @var string
     */
    public $userAgent = 'Mozilla/5.0 Firefox/3.6.12';

    /**
     * Number of seconds after which an error-timeout occurs.
     *
     * @var int
     */
    public $errorTimeout = 120;

    /**
     * Number of days we keep a backlog of database entries of succesfully
     * completed requests. Set to 0 to disable cleanup entirely
     *
     * @var int
     */
    public $backlogDays = 30;

    /**
     * Number of days we keep a backlog of database entries of all requests. Set
     * to 0 to disable cleanup entirely
     *
     * @var int
     */
    public $allBacklogDays = 60;

    /**
     * If we're inside a jobrequest, this is the current ID
     *
     * @var int
     */
    private $currentJobId;

    /**
     * Unique key for your application
     *
     * @var string
     */
    public $key = 'sjs&sk&F89fksL*987sdKf';

    /**
     * Connection to the database
     *
     * @var CDbConnection
     */
    private $databaseConnection;

    /**
     * Cache storage mechanism
     *
     * @var ICache
     */
    private $cacheStorage;

    /**
     * Initialize properties.
     */
    public function init() {
        if ($this->useDb && $this->checkAndCreateTable) {
            if (!$this->getDatabase()->schema->getTable($this->tableName)) {
                $this->createTable();
            }
        }

        // Are we in a request made by this class?
        if ($this->isInternalRequest()) {
            set_time_limit($this->errorTimeout + 5);
            $this->currentJobId = $_REQUEST['_e_back_job_id'];
            if ($this->isMonitorRequest()) {
                // Call the background worker endpoint in another request:
                $this->monitor();
            } else {
                // We are the background worker endpoint
                $this->currentJobId = $_REQUEST['_e_back_job_id'];
                Yii::app()->onBeginRequest = [$this, 'startRequest'];
                Yii::app()->onEndRequest = [$this, 'endRequest'];
            }
        }

        parent::init();
    }

    /**
     * Callback function at the start of a background request
     *
     * @param CEvent $event
     */
    public function startRequest($event) {
        ignore_user_abort(true);
        // Turn off web route for logging
        if (isset(Yii::app()->log->routes['cweb'])) {
            Yii::app()->log->routes['cweb']->enabled = false;
        }
        $this->update(['progress' => 0]);
        ob_start();
    }

    /**
     * Callback function at the end of a background request
     *
     * @param CEvent $event
     */
    public function endRequest($event) {
        $content = ob_get_clean();
        if ($error = Yii::app()->errorHandler->error) {
            $this->fail($content . var_export($error['message'], true));
        } else {
            $this->finish(['status_text' => $content]);
        }
    }

    /**
     * Returns current status of the background job as an array with keys
     * ('progress','status_text','status') If the job does not exist yet, the
     * default values are returned.
     *
     * @param  int $jobId
     * @return array The status of this job
     */
    public function getStatus($jobId) {
        // Get job from either cache or DB
        $ret = $this->getExistingJob($jobId);

        if (!is_array($ret)) {
            $ret = [];
        }

        // also set defaults
        $ret = array_merge([
            'progress' => 0,
            'status' => self::STATUS_STARTED,
            'start_time' => date('Y-m-d H:i:s'),
            'updated_time' => date('Y-m-d H:i:s'),
            'status_text' => '',
            'request' => ''
        ], $ret);

        // Check for a timeout error
        $uncompleted = $ret['status'] < self::STATUS_COMPLETED;
        $lastUpdate = strtotime($ret['updated_time']);
        $timedOut = ($lastUpdate + $this->errorTimeout) < time();
        if ($jobId && $uncompleted && $timedOut) {
            $error = "<strong>Error: job timeout</strong>";
            $text = $ret['status_text'] .  "<br/>" . $error;
            $this->fail($text, $jobId);
            $ret = $this->getStatus($jobId);
        }

        return $ret;
    }

    /**
     * Returns a job from either the cache or the database. If the job is not in
     * the cache, but is in the database, it's added to the cache.
     *
     * @param  int $jobId the unique ID of the job
     * @return array|false the job if it's found, otherwise false.
     */
    public function getExistingJob($jobId) {
        if (!$jobId) {
            return false;
        }
        $ret = false;
        if ($this->useCache) {
            $ret = $this->getCache()->get($this->cachePrefix . $jobId);
        }
        if (!$ret && $this->useDb) {
            $ret = $this->getDatabase()->createCommand()
                ->select('*')
                ->from($this->tableName)
                ->where('id=:id')
                ->queryRow(true, [':id' => $jobId]);
            if ($ret && $this->useCache) {
                // Update the cache with all the data
                $this->getCache()->set($this->cachePrefix . $jobId, $ret);
            }
        }
        return $ret;
    }

    /**
     * Starts a new background job and returns ID of that job.
     *
     * @param  string|array $request Route to controller/action
     * @param  bool Run job as the current user? (Default = true)
     * @param  int $timedelay Seconds to postpone
     * @return int Id of the new job
     */
    public function start($request, $asCurrentUser = true, $timedelay = 0) {
        if (!is_array($request)) {
            $request = [$request];
        }
        list($route, $params) = $this->requestToRoute($request);
        $status = ['request' => json_encode($request)];
        if ($timedelay > 0) {
            $status['start_time'] = date('Y-m-d H:i:s', time() + $timedelay);
        }
        $jobId = $this->createStatus($status);

        $params['_e_back_job_monitor'] = 'yes';
        $params['_e_back_job_id'] = $jobId;

        $return = $this->doRequest($route, $params, $asCurrentUser, true);

        if ($return !== true) {
            $this->fail(strval($return), $jobId);
        }
        return $jobId;
    }

    /**
     * Update a job's status
     *
     * @param array|int $status a status array or a progress percentage
     * @param int $jobId
     */
    public function update($status = [], $jobId = false) {
        if (!$jobId) {
            $jobId = $this->currentJobId;
        }
        if (!$jobId) {
            return;
        }

        $status = array_merge(
            [
                'updated_time'  => date('Y-m-d H:i:s'),
                'status'        => self::STATUS_INPROGRESS,
                'status_text'   => ob_get_contents(),
            ],
            $status
        );

        $args = var_export($status, true);
        $msg = "Updating status for job: $jobId args: $args";
        Yii::trace($msg, "application.EBackJob");

        if ($this->useCache) {
            $this->updateCache($jobId, $status);
        }
        if ($this->useDb) {
            $this->updateDatabase($jobId, $status);
        }
    }

    /**
     * Update a job's status by incrementing the progress by $amount
     *
     * @param int $amount
     * @param int $jobId
     */
    public function updateIncrement($amount, $jobId = false) {
        if (!$jobId) {
            $jobId = $this->currentJobId;
        }
        $oldStatus = $this->getStatus($jobId);
        $oldProgress = $oldStatus['progress'];
        $this->update(['progress' => $oldProgress + $amount]);
    }

    /**
     * Finish a job (alias for "update as finished")
     *
     * @param string|array $status
     * @param int $jobId
     */
    public function finish($status = [], $jobId = false) {
        if (is_string($status)) {
            $status = ['status_text' => $status];
        }
        if (!$jobId) {
            $jobId = $this->currentJobId;
        }
        $job = $this->getStatus($jobId);
        if ($job['status'] < self::STATUS_COMPLETED) {
            $status['progress'] = 100;
            $status['end_time'] = date('Y-m-d H:i:s');
            $status['status'] = self::STATUS_COMPLETED;
            $this->update($status, $jobId);
        }
        $this->cleanDb(); // cleanup of Old items
    }

    /**
     * Fail a job (alias for "update as finished with a fail status")
     *
     * @param string|array $status
     * @param int $jobId
     */
    public function fail($status = [], $jobId = false) {
        if (is_string($status)) {
            $status = ['status_text' => $status];
        }
        if (!$jobId) {
            $jobId = $this->currentJobId;
        }
        $status['end_time'] = date('Y-m-d H:i:s');
        $status['status'] = self::STATUS_FAILED;
        $this->update($status, $jobId);
        Yii::app()->end();
    }

    /**
     * Get database that was configured
     *
     * @return CDbConnection
     */
    public function getDatabase() {
        if (!isset($this->databaseConnection)) {
            $componentName = $this->databaseComponent;
            $this->databaseConnection = Yii::app()->$componentName;
        }
        return $this->databaseConnection;
    }

    /**
     * Get Cache that was configured
     *
     * @return ICache
     */

    public function getCache() {
        if (!isset($this->cacheStorage)) {
            $componentName = $this->cacheComponent;
            $this->cacheStorage = Yii::app()->$componentName;
        }
        return $this->cacheStorage;
    }

    /**
     * Puts the new status values into the cache storage
     *
     * @param int $jobId
     * @param array $status
     */
    private function updateCache($jobId, $status) {
        $cacheId = $this->cachePrefix . $jobId;
        $oldStatus = $this->getCache()->get($cacheId);
        if (!$oldStatus) {
            $oldStatus = [];
        }
        $this->getCache()->set($cacheId, array_merge($oldStatus, $status));
    }

    /**
     * Puts the new status values into the database
     *
     * @param int $jobId
     * @param array $status
     */
    private function setDbStatus($jobId, $status) {
        $this->getDatabase()->createCommand()->update(
            $this->tableName,
            $status,
            'id=:id',
            [':id' => $jobId]
        );
    }

    /**
     * Create a status, returns its ID
     *
     * @param  array $status
     * @return int The new ID
     */
    private function createStatus($status = []) {
        $jobId = false;
        $status = array_merge($this->getStatus(false), $status);
        if ($this->useDb) {
            $this->getDatabase()->createCommand()->insert($this->tableName, $status);
            $jobId = $this->getDatabase()->lastInsertId;
        }
        if ($this->useCache) {
            if (!$jobId) {
                $jobId = $this->getNewCacheId();
            }
            $this->updateCache($jobId, $status);
        }
        return $jobId;
    }

    /**
     * Get a new unique cache id for a new job
     *
     * @return int
     */
    private function getNewCacheId() {
        // The last used job Id is also stored in cache:
        $maxIdCacheId = $this->cachePrefix . 'maxid';
        // If it is absent in the cache, set it to zero:
        $this->getCache()->add($maxIdCacheId, 0);
        $jobId = $this->getCache()->get($maxIdCacheId);
        // Loop over all existing jobs to increase the maximum Id:
        while ($this->getCache()->get($this->cachePrefix . $jobId)) {
            $jobId += 1;
            $this->getCache()->set($maxIdCacheId, $jobId);
        }
        return $this->cachePrefix . $jobId;
    }

    /**
     * Create the table used for storing jobs DB-side.
     */
    private function createTable() {
        $this->getDatabase()->createCommand(
            $this->getDatabase()->schema->createTable(
                $this->tableName,
                [
                    'id' => 'pk',
                    'progress' => 'integer',
                    'status' => 'integer',
                    'start_time' => 'timestamp DEFAULT CURRENT_TIMESTAMP ',
                    'updated_time' => 'timestamp',
                    'end_time' => 'timestamp',
                    'request' => 'text',
                    'status_text' => 'text',
                ]
            )
        )->execute();
    }

    /**
     * The monitor thread. Starts a background request and reports on its
     * progress or failure.
     */
    protected function monitor() {
        $jobId = $this->currentJobId;
        $job = $this->getStatus($jobId);

        // If the start time is in the future, wait for that time (and re-check again)
        while (($job = $this->getStatus($jobId)) && strtotime($job['start_time']) > time()) {
            // Calculate how many seconds we should wait before starting:
            $waitTime = strtotime($job['start_time']) - time();
            set_time_limit($this->errorTimeout + $waitTime + 5);
            sleep($waitTime);
        }

        if ($job['request']) {
            $request = json_decode($job['request'], true);
            list($route, $params) = $this->requestToRoute($request);
            $params['_e_back_job_id'] = $jobId;
            $result = $this->doRequest($route, $params);

            if ($result !== true) {
                $job = $this->getStatus($jobId);
                $this->fail($job['status_text'] . '<br>' . $result);
            }
            // Make sure it's finished if it's not finished or failed already:
            $this->finish();
        } else {
            $data = var_export($job, true);
            $this->fail("Error: Request for job $jobId not found: $data");
        }

        Yii::app()->end();
    }

    /**
     * Make a request to the specified route
     *
     * @param  string $route Yii route to the action to run
     * @param  array $request Optional array of GET/POST parameters
     * @param  bool Run job as the current user? (Default = true)
     * @param  bool $async whether to return immediately and not wait for results
     * @return bool|string Returns either error message or true
     */
    private function doRequest($route, $request = [], $asCurrentUser = true, $async = false) {
        $method = $request['backjobMethod'] ?? 'GET';

        if (Yii::app()->request->enableCsrfValidation && $method == 'POST') {
            $tokenName = Yii::app()->request->csrfTokenName;
            $tokenValue = Yii::app()->request->getCsrfToken();
            $request['backjobPostdata'][$tokenName] = $tokenValue;
        }
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

        // Overcome proxies, force 443 if system thinks it's 80 and still secure
        // connected
        if ((Yii::app()->request->isSecureConnection) && $port == 80) {
            $port = 443;
        }

        $hostname = ($port == 443 ? 'ssl://' : '') . $host;
        $fp = fsockopen($hostname, $port, $errno, $errstr, 1000);
        if ($fp == false) {
            return "Error $errno: $errstr";
        }
        // Come to the dark side! We have
        $cookies = '';
        if ($asCurrentUser) {
            foreach (Yii::app()->request->cookies as $k => $v) {
                if (Yii::app()->request->enableCookieValidation) {
                    $v = Yii::app()->getSecurityManager()->hashData(serialize($v));
                }
                $cookies .= urlencode($k) . '=' . urlencode($v) . '; ';
            }
        }

        if (Yii::app()->request->enableCookieValidation) {
            $sessionId = Yii::app()->session->sessionID;
            $cookies .= urlencode('PHPSESSID') . '=' . urlencode($sessionId) . '; ';
        }

        // Also check if there's an authentication token that needs forwarding:
        $token = false;

        // Check for API token
        if (method_exists('ApiIdentity', 'getBearerToken')) {
            $token = ApiIdentity::getBearerToken(false);
        }

        $lf = "\r\n";
        //'Host: ' . $host . ($port ? ':' : '') . $port . $lf .
        $req = $method . ' ' . $uri . ' HTTP/1.1' . $lf .
                'Host: ' . $host . $lf .
                'User-Agent: ' . $this->userAgent . $lf .
                'Cache-Control: no-store, no-cache, must-revalidate' . $lf .
                'Cache-Control: post-check=0, pre-check=0' . $lf .
                'Pragma: no-cache' . $lf .
                ($cookies ? 'Cookie: ' . $cookies . $lf : '' ) .
                ($token ? 'Authorization: ' . $token . $lf : '');

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
        if (!$async) {
            while (!feof($fp)) {
                echo fgets($fp, 128);
            }
        }

        // Wait one second before closing connection. Some webservers do not
        // execute the request if the connection is terminated directly
        sleep(1);

        // Close connection
        fclose($fp);
        return true;
    }

    /**
     * Checks whether the request parameter contains the job Id and a matching
     * checksum, to make sure we are in an internal request made by this class.
     *
     * @return bool
     */
    private function isInternalRequest() {
        $jobId = $_REQUEST['_e_back_job_id'] ?? '';
        $hash = $_REQUEST['_e_back_job_check'] ?? '';
        return $hash === md5($jobId . $this->key);
    }

    /**
     * Check if we're a monitor-thread
     *
     * @return bool
     */
    private function isMonitorRequest() {
        return isset($_GET['_e_back_job_monitor']) && $this->isInternalRequest();
    }

    /**
     * Checks whether we are running in the background worker request.
     *
     * @return bool
     */
    public function isWorkerRequest() {
        return !isset($_GET['_e_back_job_monitor']) && $this->isInternalRequest();
    }

    /**
     * Transform a request to a route and a parameter array
     *
     * @param  array|string $request
     * @return array
     */
    private function requestToRoute($request) {
        $params = [];
        if (is_array($request)) {
            $route = $request[0];
            $params = $request;
            unset($params[0]);
        } else {
            $route = $request;
            $request = [$route];
        }
        return [$route, $params];
    }

    /**
     * Clear old database entries that have completed to limit the amount of
     * backlog
     */
    private function cleanDb() {
        $historyStart = 'DATE_SUB(NOW(), INTERVAL :history DAY)';
        if ($this->useDb && $this->backlogDays) {
            $this->getDatabase()->createCommand()->delete(
                $this->tableName,
                "end_time < $historyStart AND status = :status AND end_time != 0",
                [
                    ':history' => $this->backlogDays,
                    ':status' => self::STATUS_COMPLETED,
                ]
            );
        }
        if ($this->useDb && $this->allBacklogDays) {
            $this->getDatabase()->createCommand()->delete(
                $this->tableName,
                "end_time < $historyStart AND end_time != 0",
                [':history' => $this->backlogDays]
            );
        }
    }
}
