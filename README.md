BackJob can start an action in the background (by using a request), runs it either as the current user or as anonymous, and monitors its progress.

For running jobs at remote locations, fire-and-forget jobs, interval/cron jobs, please look at the [runactions](http://www.yiiframework.com/extension/runactions "runactions") extension.

##Requirements

Works with Yii-1.1.14 and up. Needs to be able to use fsockopen and fwrite to itself.

**Important:** If you want to run background jobs as the current user (which is the default option), you must use some kind of non-blocking session storage, such as CDbHttpSession. Also make sure that user is authorised to access that action.


##Installation
Put the source from the zip archive in `protected/extensions` or from [https://github.com/greenhost/BackJob](https://github.com/greenhost/BackJob "Backjob Github Repo") in `protected/extensions/backjob`.

Configuration:
~~~
[php]
// Yes, it needs preloads, but it's not resource-heavy (promise!)
'preload' => array(
	'background'
),


'components' => array(
	'background' => array(
		'class' => 'ext.backjob.EBackJob',

		// All other configuration options are optional:

		'checkAndCreateTable' => true,  // creates table if it doesn't exist
		'key' => 'sjs&sk&F89fksL*987sdKf' // Random string used to salt the hash used for background-thread-authentication. Optional to change, but you really should.
		'useDb' => true,    // Use a database table
		'useCache' => true, // Use the cache
		'db' => 'db',    // Database component name to use
		'ch' => 'cache', // Cache component name to use
		'tableName' => 'e_background_job', // Name of DB table used
		'cachePrefix' => 'EBackJobPrefix-',  // Prefix used in the cache
		'errorTimeout' => 60, // Nr of seconds after which ALL requests time out, measured from the last update.
		'userAgent' => 'Mozilla/5.0 Firefox/3.6.12', // Useragent used for the background request
		'backlogDays' => 30, // Number of days successfully completed request-entries are retained in the database
		'allBacklogDays' => 60, // Number of days ALL entries (including failed) are retained in the database
	),
),
~~~

##Usage

It's possible to start any controllers' action that is reachable through a url, but recommended to have dedicated actions to be run in the background. You'll have to make your own progress reports, otherwise progress will just jump from 0 to 100.

Starting a background job only requires a valid route to that controller/action.
~~~
[php]
$jobId = Yii::app()->background->start('site/longJob');
// Or, with parameters:
$jobWithParams = Yii::app()->background->start(
	array(
		'site/paramJob', 
		'id'=>$id, 
		'param2'=>true
	)
);
~~~	

Then you'll probably want to use a time-intervaled ajax request to get the progress.
Getting the status of a specific job. This returns an array of the form:
~~~
[php]
$status = Yii::app()->background->getStatus($jobId);
//This returns an array that looks something like this:
array(
	'progress' => 20, //percentage (integer 0-100) of completeness
	'status' => EBackJob::STATUS_INPROGRESS, // (integer 0-4)
	'start_time' => '2013-11-18 14:11',
	'updated_time' => '2013-11-18 14:11',
	'end_time' => '2013-11-18 14:11',
	'status_text' => 'The complete output of the request, so far',
);
~~~

During the background job, the action that actually runs the job itself can update its progress both by echoing and setting the progress counter:
~~~
[php]
echo "Starting 1<br/>";
Yii::app()->background->update(20);
do_long_function1();
echo "Processing 2<br/>";
Yii::app()->background->update(60);
if(!do_long_function2()){
    echo "Error occurred!";
    Yii::app()->background->fail(); // this also ends the application immediately!
}
echo "Finishing 3<br/>";
Yii::app()->background->update(90);
do_last_function3();
echo "Done<br/>";
~~~

If you don't want a list or log of echoed text, but replace it, you can use the update function like this, but make sure that you also finish manually.
~~~
[php]
Yii::app()->background->update(array('progress'=>60,'status_text'=>'Chugging along now');
Yii::app()->background->finish(array('status_text'=>'And done');
~~~

##POST and other methods

Any HTTP1.1 method can be used (POST, PUT, DELETE, CHICKEN), and it can send both "GET" data in the url and url-encoded data in the body (sorry, no multipart/file support, you can bypass that by passing temporary filenames). These can be set in the request array using the keys `backjobMethod` and `backjobPostdata` respectively. Yes, this means that you can't use your own fields called `backjobMethod` and `backjobPostdata`, but this was the best way to add this while maintaining backwards compatibility.

Example: To make a POST call with `$\_POST` values for `id` and `name` and a `$\_GET` value for `page` do:
~~~
[php]
Yii::app()->background->start(array('test/testbackground', 'page'=>1, 'backjobMethod'=>'POST', 'backjobPostdata'=>array('id'=>5, 'name'=>'Name')));
~~~

##Complete example
~~~
[php]
class testController extends Controller {

	public function actionProgressMonitor(){
		$job = Yii::app()->background->start(array('test/testbackground'));
		$this->render('progress'); // empty file, or containing:
		echo "Progress: <div id='test'></div>";
		echo CHtml::script("$(function(){ setInterval(function(){ $('#test').load('".$this->createUrl('test/getStatus',array('id'=>$job))."');}, 1000);});");
	}
	public function actionGetStatus($id){
		echo json_encode(Yii::app()->background->getStatus($id));
		Yii::app()->end();
	}
	public function actionTestbackground(){
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

##Changelog
- 0.50 - Changed the way internal requests are recognised, they are now checked by parameters instead of from-headers, should work nicer with proxied servers, and be more resilient against spoofing. Change the `key` field in configuration!
- 0.45 - Added support for other HTTP methods and POST-data.
- 0.44 - The timeout-setting now also affects the php-timeout setting with `set_time_limit`, thanks to martijnjonkers.
- 0.43 - Added backlog-cleanup for the database, so that it won't fill up with completed requests. Keep in mind that there are two different time-scales, one for successfully finished jobs, and one for all jobs including failed ones. Setting these to 0 days will stop cleanup entirely, this might lead to an ever-expanding database! Thanks to Arno S for noticing this omission.
- 0.42 - Few bugfixes: creation of table, cache was unuseable, a typo
- 0.41 - Small bugfix
- 0.40 - Added monitoring thread that waits for the job to end, so requests that end prematurely still finish. Also, you can specify a number of seconds to wait until processing, and multiple requests with the exact same route will be merged together into one request.
- 0.33 - Https support, better self-recognition, added request field (update your database table!), added global timeout.
- 0.32 - Fails better.
- 0.31 - Initial component

