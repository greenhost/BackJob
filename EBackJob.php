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
    public string $databaseComponent = 'db';

    /**
     * Name of the cache component in this the Yii application
     *
     * @var string
     */
    public string $cacheComponent = 'cache';

    /**
     * Should we use the cache?
     *
     * @var bool
     */
    public bool $useCache = true;

    /**
     * Should we use the database?
     *
     * @var bool
     */
    public bool $useDb = true;

    /**
     * Database table name to be used
     *
     * @var string
     */
    public string $tableName = 'e_background_job';

    /**
     * Whether to check that the database exists, and create it if it does not.
     * Set it to false in production!
     *
     * @var bool
     */
    public bool $checkAndCreateTable = false;

    /**
     * Cache ID Prefix so we don't interfere with other cache-items
     *
     * @var string
     */
    public string $cachePrefix = "EBackJobPrefix-";

    /**
     * User agent used in the background request
     *
     * @var string
     */
    public string $userAgent = 'Mozilla/5.0 Firefox/3.6.12';

    /**
     * Number of seconds after which an error-timeout occurs.
     *
     * @var int
     */
    public int $errorTimeout = 120;

    /**
     * Number of days we keep a backlog of database entries of succesfully
     * completed requests. Set to 0 to disable cleanup entirely
     *
     * @var int
     */
    public int $backlogDays = 30;

    /**
     * Number of days we keep a backlog of database entries of all requests. Set
     * to 0 to disable cleanup entirely
     *
     * @var int
     */
    public int $allBacklogDays = 60;

    /**
     * Unique key for your application
     *
     * @var string
     */
    public string $key = 'sjs&sk&F89fksL*987sdKf';

    /**
     * If we're inside a jobrequest, this is the current ID
     *
     * @var int|null
     */
    private ?int $currentJobId = null;

    /**
     * Connection to the database
     *
     * @var CDbConnection
     */
    private CDbConnection $databaseConnection;

    /**
     * Cache storage mechanism
     *
     * @var ICache
     */
    private ICache $cacheStorage;

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
            $this->currentJobId = intval($_REQUEST['_e_back_job_id']);
            if ($this->isMonitorRequest()) {
                // Call the background worker endpoint in another request:
                $this->monitor();
            } else {
                // We are the background worker endpoint
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
    public function startRequest(CEvent $event) {
        ignore_user_abort(true);
        // Turn off web route for logging
        if (isset(Yii::app()->log->routes['cweb'])) {
            Yii::app()->log->routes['cweb']->enabled = false;
        }
        $this->update(0);
        ob_start();
    }

    /**
     * Callback function at the end of a background request
     *
     * @param CEvent $event
     */
    public function endRequest(CEvent $event) {
        $content = ob_get_clean();
        if ($error = Yii::app()->errorHandler->error) {
            $this->fail($content . "\n" . json_encode($error['message']));
        } else {
            $this->finish($content);
        }
    }

    /**
     * Returns the current status of the background job and marks it as failed
     * if the execution has timed out.
     *
     * @param  int $jobId the unique Id of the job
     * @return array The progress, status and status_text of this job
     */
    public function getStatus(int $jobId): array {
        $job = $this->getCheckedJob($jobId);

        // Only make these fields publicly available:
        return [
            'progress'      => $job['progress'],
            'status'        => $job['status'],
            'status_text'   => $job['status_text'],
        ];
    }

    /**
     * Fetches the job from storage and marks it as failed if the execution has
     * timed out or returns default values if the job does not exist yet.
     *
     * @param  int $jobId the unique Id of the job
     * @return array the job
     */
    private function getCheckedJob(int $jobId): array {
        // Get job from either cache or DB
        $job = $this->getJob($jobId);

        if (!is_array($job)) {
            $job = [];
        }

        // also set defaults
        $job = array_merge([
            'progress' => 0,
            'status' => self::STATUS_STARTED,
            'start_time' => date('Y-m-d H:i:s'),
            'updated_time' => date('Y-m-d H:i:s'),
            'status_text' => '',
            'request' => ''
        ], $job);

        // Check for a timeout error
        $uncompleted = $job['status'] < self::STATUS_COMPLETED;
        $lastUpdate = strtotime($job['updated_time']);
        $timedOut = ($lastUpdate + $this->errorTimeout) < time();
        if ($jobId && $uncompleted && $timedOut) {
            $text = $job['status_text'] . "\nError: job timeout";
            $this->fail($text, $jobId); // Ends the script
        }

        return $job;
    }

    /**
     * Returns whether a job exists with the specified Id
     *
     * @param  int $jobId the unique Id of the job
     * @return bool whether a job exists with thee specified Id
     */
    public function jobExists(int $jobId): bool {
        return !is_null($this->getJob($jobId));
    }

    /**
     * Returns a job from either the cache or the database. If the job is not in
     * the cache, but is in the database, it's added to the cache.
     *
     * @param  int $jobId the unique Id of the job
     * @return array|null the job if it's found, otherwise NULL.
     */
    private function getJob(int $jobId): ?array {
        if (!$jobId) {
            return null;
        }

        if ($this->useCache) {
            $job = $this->getCache()->get($this->cachePrefix . $jobId);
            if (is_array($job)) {
                return $job;
            }
        }

        if ($this->useDb) {
            $job = $this->getDatabase()->createCommand()
                ->select('*')
                ->from($this->tableName)
                ->where('id=:id')
                ->queryRow(true, [':id' => $jobId]);
            if (!is_array($job)) {
                return null;
            }

            if ($this->useCache) {
                // Update the cache with all the data
                $this->getCache()->set($this->cachePrefix . $jobId, $job);
            }
            return $job;
        }

        return null;
    }

    /**
     * Starts a new background job and returns ID of that job.
     *
     * @param  string|array $request Route to controller/action
     * @param  bool $withUserCookies whether to send along all client cookies
     * @param  int $delay Seconds to postpone the start of the job
     * @return int Id of the new job
     * @throws RuntimeException if the job cannot be started
     */
    public function start(
        string|array $request,
        bool $withUserCookies = true,
        int $delay = 0
    ): int {
        if ($delay > $this->errorTimeout) {
            throw new RangeException('Job delay cannot exceed error timeout');
        }
        $jobId = $this->createJob($request, $delay);

        list($route, $params) = $this->requestToRoute($request);
        $params['_e_back_job_monitor'] = 'yes';
        $params['_e_back_job_id'] = $jobId;

        try {
            $async = true; // Do not wait for results, return immediately
            $this->doRequest($route, $params, $withUserCookies, $async);
        } catch (Throwable $e) {
            $this->fail($e->getMessage(), $jobId);
        }

        return $jobId;
    }

    /**
     * Update some or all fields of a job in storage
     *
     * @param array $fields the fields that must be updated
     * @param int|null $jobId the unique Id of the job
     */
    private function updateJob(array $fields = [], ?int $jobId = null) {
        if (is_null($jobId)) {
            $jobId = $this->currentJobId;
        }
        if (is_null($jobId)) {
            return;
        }

        $fields = array_merge(
            [
                'updated_time'  => date('Y-m-d H:i:s'),
                'status'        => self::STATUS_INPROGRESS,
                'status_text'   => ob_get_contents(),
            ],
            $fields
        );

        $msg = "Updating job $jobId with fields: " . json_encode($fields);
        Yii::trace($msg, "application.EBackJob");

        if ($this->useCache) {
            $this->updateCache($jobId, $fields);
        }
        if ($this->useDb) {
            $this->updateDatabase($jobId, $fields);
        }
    }

    /**
     * Resets the job's updated time and optionally changes the progress
     * percentage and/or status text
     *
     * @param int|float|null $progress percentage done or NULL to not change it
     * @param string|null $statusText message or NULL to use the buffer contents
     */
    public function update(
        int|float|null $progress = null,
        ?string $statusText = null
    ) {
        $fields = [];
        if (!is_null($progress)) {
            $progress = min(100, max(0, $progress));
            $fields['progress'] = intval(round($progress));
        }
        if (!is_null($statusText)) {
            $fields['status_text'] = $statusText;
        }
        $this->updateJob($fields);
    }

    /**
     * Resets the job's updated time, increments the progress percentage and
     * optionally changes the status text
     *
     * @param int $increment the desired percentage increment
     * @param string|null $statusText message or NULL to use the buffer contents
     */
    public function incrementProgress(
        int $increment,
        ?string $statusText = null
    ) {
        $job = $this->getCheckedJob($this->currentJobId);
        $this->update($job['progress'] + $increment, $statusText);
    }

    /**
     * Finish a job (alias for "update as finished")
     *
     * @param string|null $statusText
     * @param int|null $jobId the unique Id of the job
     */
    public function finish(?string $statusText = null, ?int $jobId = null) {
        if (is_null($jobId)) {
            $jobId = $this->currentJobId;
        }
        $job = $this->getCheckedJob($jobId);
        if ($job['status'] < self::STATUS_COMPLETED) {
            $fields = [
                'progress'  => 100,
                'end_time'  => date('Y-m-d H:i:s'),
                'status'    => self::STATUS_COMPLETED
            ];
            if (!is_null($statusText)) {
                $fields['status_text'] = $statusText;
            }
            $this->updateJob($fields, $jobId);
        }
        $this->cleanDb(); // cleanup of Old items
    }

    /**
     * Fail a job (alias for "update as finished with a fail status")
     *
     * @param string|null $statusText
     * @param int|null $jobId the unique Id of the job
     */
    public function fail(?string $statusText = null, ?int $jobId = false) {
        if (is_null($jobId)) {
            $jobId = $this->currentJobId;
        }
        $fields = [
            'end_time'  => date('Y-m-d H:i:s'),
            'status'    => self::STATUS_FAILED
        ];
        if (!is_null($statusText)) {
            $fields['status_text'] = $statusText;
        }
        $this->updateJob($fields, $jobId);
        Yii::app()->end();
    }

    /**
     * Get database that was configured
     *
     * @return CDbConnection
     */
    private function getDatabase(): CDbConnection {
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

    private function getCache(): ICache {
        if (!isset($this->cacheStorage)) {
            $componentName = $this->cacheComponent;
            $this->cacheStorage = Yii::app()->$componentName;
        }
        return $this->cacheStorage;
    }

    /**
     * Updates some or all fields of a job in the cache storage
     *
     * @param int $jobId the unique Id of the job
     * @param array $fields
     */
    private function updateCache(int $jobId, array $fields) {
        $cacheId = $this->cachePrefix . $jobId;
        $job = $this->getCache()->get($cacheId);
        if ($job) {
            $fields = array_merge($job, $fields);
        }
        $this->getCache()->set($cacheId, $fields);
    }

    /**
     * Updates some or all fields of a job in the database
     *
     * @param int $jobId the unique Id of the job
     * @param array $fields
     */
    private function updateDatabase(int $jobId, array $fields) {
        $this->getDatabase()->createCommand()->update(
            $this->tableName,
            $fields,
            'id=:id',
            [':id' => $jobId]
        );
    }

    /**
     * Creates a new job and stores it in the database and/or cache
     *
     * @param  string|array $request Route to controller/action
     * @param  int $delay Seconds to postpone the start of the job
     * @return int the newly created job Id
     */
    private function createJob($request, $delay = 0): int {
        $now    = time();
        $delay  = max(0, $delay);

        $job = [
            'progress'      => 0,
            'status'        => self::STATUS_STARTED,
            'start_time'    => date('Y-m-d H:i:s', $now + $delay),
            'updated_time'  => date('Y-m-d H:i:s', $now),
            'request'       => json_encode($request),
            'status_text'   => ''
        ];

        $jobId = null;
        if ($this->useDb) {
            $db = $this->getDatabase();
            $db->createCommand()->insert($this->tableName, $job);
            $insertId = $db->lastInsertId;
            $jobId = $insertId === false ? null : intval($insertId);
        }
        if ($this->useCache) {
            if (is_null($jobId)) {
                $jobId = $this->nextJobIdInCache();
            }
            $this->updateCache($jobId, $job);
        }
        return $jobId;
    }

    /**
     * Get the next Job Id that is still unused in the cache
     *
     * @return int
     */
    private function nextJobIdInCache(): int {
        // The last used job Id is also stored in cache:
        $maxIdCacheId = $this->cachePrefix . 'maxid';
        // If it is absent in the cache, set it to zero:
        $this->getCache()->add($maxIdCacheId, 0);
        $jobId = intval($this->getCache()->get($maxIdCacheId));
        // Loop over all existing jobs to increase the maximum Id:
        while ($this->getCache()->get($this->cachePrefix . $jobId)) {
            $jobId += 1;
            $this->getCache()->set($maxIdCacheId, $jobId);
        }
        return $jobId;
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
        $jobId  = $this->currentJobId;
        $job    = $this->getCheckedJob($jobId);
        $now    = time();
        $start  = strtotime($job['start_time']);

        if ($start > $now) {
            $waitTime = $start - $now;
            // Allow for the full timeout after waiting and some margin:
            set_time_limit($this->errorTimeout + $waitTime + 5);
            sleep($waitTime);
            // Reset the `updated_time` to allow for the full timeout:
            $this->updateJob(['updated_time' => date('Y-m-d H:i:s')], $jobId);
            // Fetch the updated values from storage:
            $job = $this->getJob($jobId);
        }

        if ($job['request']) {
            $request = json_decode($job['request'], true);
            list($route, $params) = $this->requestToRoute($request);
            $params['_e_back_job_id'] = $jobId;
            try {
                $this->doRequest($route, $params);
            } catch (Throwable $e) {
                $job = $this->getCheckedJob($jobId);
                $this->fail($job['status_text'] . "\n" . $e->getMessage());
            }
            // Make sure it's finished if it's not finished or failed already:
            $this->finish();
        } else {
            $data = json_encode($job);
            $this->fail("Error: Request for job $jobId not found: $data");
        }

        Yii::app()->end();
    }

    /**
     * Make a request to the specified route
     *
     * @param  string $route Yii route to the action to run
     * @param  array $params Optional array of GET/POST parameters
     * @param  bool $withUserCookies whether to send along all client cookies
     * @param  bool $async whether to return immediately & not wait for results
     * @throws RuntimeException if something went wrong
     */
    private function doRequest(
        string $route,
        array $params = [],
        bool $withUserCookies = true,
        bool $async = false
    ) {
        $method = $params['backjobMethod'] ?? 'GET';

        if (Yii::app()->request->enableCsrfValidation && $method == 'POST') {
            $tokenName = Yii::app()->request->csrfTokenName;
            $tokenValue = Yii::app()->request->getCsrfToken();
            $params['backjobPostdata'][$tokenName] = $tokenValue;
        }
        if ($this->isMonitorRequest()) {
            $postdata = file_get_contents("php://input");
            unset($params['backjobMethod']);
        } elseif (isset($params['backjobPostdata'])) {
            $postdata = http_build_query($params['backjobPostdata']);
            unset($params['backjobPostdata']);
        } else {
            $postdata = '';
        }

        $jobId = $params['_e_back_job_id'];
        $params['_e_back_job_check'] = md5($jobId . $this->key);

        $uri = Yii::app()->createAbsoluteUrl($route, $params);
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
        if ($fp === false) {
            throw new UnexpectedValueException("Error $errno: $errstr");
        }

        $cookie     = $this->buildCookie($withUserCookies);
        $request    = $this->buildRequest(
            $method,
            $uri,
            $host,
            $cookie,
            $postdata
        );

        $msg = "Running background request: $request";
        Yii::trace($msg, "application.EBackJob");
        // Do the request
        fwrite($fp, $request);

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
    }

    /**
     * Creates a cookie string containing the PHP session Id and optionally all
     * the client cookies.
     *
     * @param  bool $withUserCookies whether to include all client cookies
     * @return string the cookie string for an HTTP request
     */
    private function buildCookie(bool $withUserCookies): string {
        $cookie     = '';
        $validate   = Yii::app()->request->enableCookieValidation;

        if ($withUserCookies) {
            $security = Yii::app()->getSecurityManager();
            foreach (Yii::app()->request->cookies as $key => $value) {
                if ($validate) {
                    $value = $security->hashData(serialize($value));
                }
                $cookie .= urlencode($key) . '=' . urlencode($value) . '; ';
            }
        }

        if ($validate) {
            $sessionId = Yii::app()->session->sessionID;
            $cookie .= urlencode('PHPSESSID') . '='
                . urlencode($sessionId) . '; ';
        }

        return $cookie;
    }

    /**
     * Creates an HTTP request string
     *
     * @param  string $method
     * @param  string $uri
     * @param  string $host
     * @param  string $cookie
     * @param  string $postdata
     * @return string the HTTP request string
     */
    private function buildRequest(
        string $method,
        string $uri,
        string $host,
        string $cookie,
        string $postdata = ''
    ): string {
        $lines = [
            "$method $uri HTTP/1.1",
            "Host: $host",
            "User-Agent: $this->userAgent",
            'Cache-Control: no-store, no-cache, must-revalidate',
            'Cache-Control: post-check=0, pre-check=0',
            'Pragma: no-cache'
        ];

        if ($cookie) {
            $lines[] = "Cookie: $cookie";
        }

        // Check if there is an authentication token that needs forwarding:
        if (method_exists('ApiIdentity', 'getBearerToken')) {
            $lines[] = 'Authorization: ' . ApiIdentity::getBearerToken(false);
        }

        if ($postdata) {
            $lines[] = 'Content-Type: application/x-www-form-urlencoded';
            $lines[] = 'Content-Length: ' . strlen($postdata);
            $lines[] = 'Connection: Close';
            $lines[] = ''; // Blank line before POST data
            $lines[] = $postdata;
        } else {
            $lines[] = 'Connection: Close';
            $lines[] = ''; // End with blank line?
        }

        return implode("\r\n", $lines);
    }

    /**
     * Checks whether the request parameter contains the job Id and a matching
     * checksum, to make sure we are in an internal request made by this class.
     *
     * @return bool
     */
    private function isInternalRequest(): bool {
        $jobId = $_REQUEST['_e_back_job_id'] ?? '';
        $hash = $_REQUEST['_e_back_job_check'] ?? '';
        return $hash === md5($jobId . $this->key);
    }

    /**
     * Checks whether we are a monitor-thread.
     *
     * @return bool
     */
    private function isMonitorRequest(): bool {
        return isset($_GET['_e_back_job_monitor']) && $this->isInternalRequest();
    }

    /**
     * Checks whether we are running in the background worker request.
     *
     * @return bool
     */
    public function isWorkerRequest(): bool {
        return !isset($_GET['_e_back_job_monitor']) && $this->isInternalRequest();
    }

    /**
     * Transform a request to a route and a parameters array
     *
     * @param  array|string $request
     * @return array
     */
    private function requestToRoute(array|string $request): array {
        $params = [];
        if (is_array($request)) {
            $params = $request;
            $route  = array_shift($params);
        } else {
            $route = $request;
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
