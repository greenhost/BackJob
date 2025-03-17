# Background jobs for Yii 1.x

BackJob can start an action in the background (by using a request), runs it
either as the current user or as anonymous, and monitors its progress.

For running jobs at remote locations, fire-and-forget jobs, interval/cron jobs,
please look at the [runactions](http://www.yiiframework.com/extension/runactions
"runactions") extension.

## Requirements

Works with Yii-1.1.14 and up, running on PHP 8.0 or higher. Needs to be able to
use fsockopen and fwrite to itself.

**Important:** If you want to run background jobs as the current user (which is
the default option), you must use some kind of non-blocking session storage,
such as CDbHttpSession. Also make sure that user is authorised to access that
action.


## Installation

### Composer install

Backjob is not in the standard composer repository. It is however possible to
use composer to manage the extension. Add the following lines to your
composer.json file:

~~~json
"repositories": [
    {
        "url": "https://github.com/greenhost/BackJob",
        "type": "vcs"
    }
],
"require": {
    "greenhost/backjob": "^0.64"
},
~~~


## Configuration:

~~~php
Yii::setPathOfAlias('composer', dirname(__FILE__) . '/../../../vendor');

// Yes, it needs preloads, but it's not resource-heavy (promise!)
'preload' => [
    'background'
],


'components' => [
    'background' => [
        'class' => 'composer.greenhost.backjob.EBackjob',

        // All other configuration options are optional:

        'checkAndCreateTable' => true,  // creates table if it doesn't exist
        'key' => 'sjs&sk&F89fksL*987sdKf' // Random string used to salt the hash used for background-thread-authentication. Optional to change, but you really should.
        'useDb' => true,    // Use a database table
        'useCache' => true, // Use the cache
        'databaseComponent' => 'db',    // Database component name to use
        'cacheComponent' => 'cache', // Cache component name to use
        'tableName' => 'e_background_job', // Name of DB table used
        'cachePrefix' => 'EBackJobPrefix-',  // Prefix used in the cache
        'errorTimeout' => 60, // Nr of seconds after which ALL requests time out, measured from the last update.
        'userAgent' => 'Mozilla/5.0 Firefox/3.6.12', // Useragent used for the background request
        'backlogDays' => 30, // Number of days successfully completed request-entries are retained in the database
        'allBacklogDays' => 60, // Number of days ALL entries (including failed) are retained in the database
    ],
],
~~~

## Usage

It is possible to start any controllers' action that is reachable through a url,
but recommended to have dedicated actions to be run in the background. You will
have to make your own progress reports, otherwise progress will just jump from 0
to 100.

Starting a background job only requires a valid route to that controller/action.

~~~php
$jobId = Yii::app()->background->start('site/longJob');
// Or, with parameters:
$jobWithParams = Yii::app()->background->start([
    'site/paramJob',
    'id' => $id, 
    'param2' => true
]);
~~~

Then you will probably want to use a time-intervaled ajax request to get the
status of a specific job. This returns an array of the form:

~~~php
$status = Yii::app()->background->getStatus($jobId);
//This returns an array that looks something like this:
[
    'progress'      => 20, //percentage (integer 0-100) of completeness
    'status'        => EBackJob::STATUS_INPROGRESS, // (integer 0-4)
    'status_text'   => 'The complete output of the request, so far',
];
~~~

During the background job, the action that actually runs the job itself can
update its progress both by echoing and setting the progress counter:

~~~php
echo "Starting 1\n";
Yii::app()->background->update(20);
do_long_function1();
echo "Processing 2\n";
Yii::app()->background->update(60);
if(!do_long_function2()){
    echo "Error occurred!";
    Yii::app()->background->fail(); // this also ends the application immediately!
}
echo "Finishing 3\n";
Yii::app()->background->update(90);
do_last_function3();
echo "Done\n";
~~~

If you don't want a list or log of echoed text, but replace it, you can use the
update function like this, but make sure that you also finish manually.

~~~php
Yii::app()->background->update(60, 'Chugging along now');
Yii::app()->background->finish('And done');
~~~

## POST and other methods

Any HTTP1.1 method can be used (POST, PUT, DELETE, CHICKEN), and it can send
both "GET" data in the url and url-encoded data in the body (sorry, no
multipart/file support, you can bypass that by passing temporary filenames).
These can be set in the request array using the keys `backjobMethod` and
`backjobPostdata` respectively. Yes, this means that you can't use your own
fields called `backjobMethod` and `backjobPostdata`, but this was the best way
to add this while maintaining backwards compatibility.

Example: To make a POST call with `$\_POST` values for `id` and `name` and a
`$\_GET` value for `page` do:

~~~php
Yii::app()->background->start([
    'test/testbackground',
    'page' => 1,
    'backjobMethod' => 'POST',
    'backjobPostdata' => ['id' => 5, 'name' => 'Name']
]);
~~~

## Complete example

~~~php
class testController extends Controller {

    public function actionProgressMonitor() {
        $jobId = Yii::app()->background->start(['test/testbackground']);
        $this->render('progress'); // empty file, or containing:
        echo "Progress: <pre id='test'></pre>";
        $url = $this->createUrl('test/getStatus', ['id' => $jobId]);
        $loadFunction = "function(){ $('#test').load('$url'); }";
        echo CHtml::script("$(function(){ setInterval($loadFunction, 1000); });");
    }

    public function actionGetStatus(int $id) {
        echo json_encode(Yii::app()->background->getStatus($id));
        Yii::app()->end();
    }

    public function actionTestbackground() {
        Yii::app()->background->update(1);
        echo "Job started.";
        sleep(3);
        Yii::app()->background->update(20);
        sleep(3);
        Yii::app()->background->update(40);
        echo "Job in progress.";
        sleep(3);
        Yii::app()->background->update(60);
        sleep(3);
        Yii::app()->background->update(80);
        sleep(3);
        echo "Job done.";
        Yii::app()->end();
    }
}
~~~

## How the background request mechanism works

The client session can call these methods:
- `start()` to request the execution of a new background job
- `getStatus()` to get the status of an existing background job
- `jobExists()` to check whether a background job exists

The worker session can call these methods:
- `update()` to set a progress percentage and store the current output buffer
  contents in the database.
- `incrementProgress()` same as `update()` but with an integer increment
- `finish()` and `fail()` although they would get called at the end of the
  request anyway, depending on wheter there was an error.

The call to `start()` will insert a new job status record and then do an
internal HTTP request to the route/endpoint that should do the work. It will
forward information from the client session (CSRF token, cookies, PHP session
Id, API token). After sending the internal request, the connection is closed
almost immediately, without waiting for the response. The newly created job Id
is returned.

The EBackJob component is preloaded, so the request that was just sent, will hit
the EBackJob initialization, which recognizes it as an internal BackJob request.
Instead of letting the Yii app handle the request, it will run the `monitor()`
function, which fetches the job status record to check if it should start right
away or wait a specified time. It reads the intended route and parameters from
the record and makes another internal HTTP request. After sending this second
request, it keeps the connection open and waits for the result. After that, the
job status is updated to be either completed or failed.

The second internal request also hits the EBackJob initialization, which
recognizes it as the background worker request. It registers two callbacks to
facilitate output buffering. It then lets the Yii app handle the request, which
should typically be a controller action written for background work.

## Changelog

- **0.64**
    - Require at least PHP 8.0
    - Use type declarations
    - Remove HTML from generated responses
- **0.63**
    - Replace getExistingJob() with jobExists() method
    - Make getDatabase() and getCache() private
    - Minor documentation corrections
- **0.62**
    - Reinstate update() with a different signature and remove updateProgress()
- **0.61.1**
    - Allow status updates without progress or text
- **0.61**
    - Deprecated update() in favour of updateProgress()
    - Reduced the public information given by getStatus()
    - Resolved ambiguity between job/status/fields
    - Renamed updateIncrement() to incrementProgress()
    - Changed the signature of finish() and fail()
    - Allowed any ICache to implement the cache store
    - Removed public setStatus()
    - Removed old instructions for installation as extension
- **0.60**
    - Added a method to check being a background worker request
    - Reduced code complexity by inlining some code
    - Reduced the visibilty of some internals
    - Explained how the background request mechanism is implemented
- **0.59**
    - Repair delayed job execution
- **0.58**
    - Fixed some markdown formatting in the documentation
    - Added a .gitignore file and PHP code formatting rules
    - Required at least PHP 7.2
    - Applied automated and manual code formatting changes
    - Declared visibility on class constants
- **0.57**
    - Changed space to tabs
- **0.56**
    - Add small delay before closing connection
- **0.55**
    - Fix typo in cookievalidation enabled code
- **0.54**
    - Fix php error
- **0.53**
    - Give backjob CSRF compatibility
- **0.52**
    - Update for support enableCookieValidation
    - Add composer file and update readme for composer instructions
    - Remove direct ApiIdentity dependency
    - Fix bug where running backjobs get deleted
    - Fix bug were unintended status records get created
- **0.51**
    - Converted the README to use the GFM style format.
- **0.50**
     - Changed the way internal requests are recognised, they are now checked by
       parameters instead of from-headers, should work nicer with proxied
       servers, and be more resilient against spoofing. Change the `key` field
       in configuration!
- **0.45**
    - Added support for other HTTP methods and POST-data.
- **0.44**
    - The timeout-setting now also affects the php-timeout setting with
      `set_time_limit`, thanks to martijnjonkers.
- **0.43**
    - Added backlog-cleanup for the database, so that it won't fill up with
      completed requests. Keep in mind that there are two different time-scales,
      one for successfully finished jobs, and one for all jobs including failed
      ones. Setting these to 0 days will stop cleanup entirely, this might lead
      to an ever-expanding database! Thanks to Arno S for noticing this
      omission.
- **0.42**
    - Few bugfixes: creation of table, cache was unuseable, a typo
- **0.41**
    - Small bugfix
- **0.40**
    - Added monitoring thread that waits for the job to end, so requests that
      end prematurely still finish.
    - Also, you can specify a number of seconds to wait until processing
    - Multiple requests with the exact same route will be merged together into
      one request.
- **0.33**
    - Https support, better self-recognition
    - Added request field (update your database table!)
    - Added global timeout
- **0.32**
    - Fails better
- **0.31**
    - Initial component
