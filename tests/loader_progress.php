<?php
declare(strict_types=1);

/** Progress/status fixtures only: no downloads, credentials or installed timetable. */
$project=dirname(__DIR__);
$isolated=sys_get_temp_dir().'/perth-loader-progress-'.bin2hex(random_bytes(6));
mkdir($isolated,0700,true);
copy($project.'/loader.php',$isolated.'/loader.php');
define('PM_LOADER_LIBRARY_ONLY',true);
require $isolated.'/loader.php';

$checks=0;
function lpCheck(bool $condition,string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: '.$message); }
    $checks++; echo 'PASS: '.$message.PHP_EOL;
}
function lpReject(callable $operation,string $message): void
{
    $rejected=false;
    try { $operation(); } catch (RuntimeException) { $rejected=true; }
    lpCheck($rejected,$message);
}

$lock=null;
try {
    $empty=pm_status();
    lpCheck($empty['running']===false && $empty['interrupted']===false && $empty['progress']===null,'an untouched single-loader deployment has no invented operation');
    lpCheck(!file_exists(PM_LOADER_PRIVATE),'read-only status does not create private storage or a setup token');
    pm_prepare_private();
    $lock=pm_lock();
    pm_progress_begin('update');
    $active=pm_status(); $first=$active['progress'];
    lpCheck($active['running'] && !$active['interrupted'] && $first['status']==='running','a real held flock identifies the active update');
    lpCheck(is_string($first['runId']) && strlen($first['runId'])>=16 && $first['action']==='update' && $first['pid']===getmypid(),'progress identifies this operation and its actual process');
    lpCheck(strtotime($first['startedAt'])!==false && strtotime($first['updatedAt'])!==false && $first['elapsedSeconds']>=0,'progress supplies usable timestamps and elapsed time');
    $path=PM_LOADER_PRIVATE.'/maintenance-progress.php';
    lpCheck(str_starts_with(file_get_contents($path),PM_LOADER_GUARD) && (fileperms($path)&0777)===0600,'saved progress has the private PHP guard and owner-only permissions');

    pm_progress_report(['stage'=>'import:stop_times','label'=>'Importing stop_times.txt','message'=>'Imported fixture stop visits.',
        'percent'=>55,'completed'=>125000,'total'=>200000,'unit'=>'rows','setup_token'=>'UNEXPECTED_FIXTURE_SECRET','password_hash'=>'UNEXPECTED_FIXTURE_HASH']);
    $reading=pm_status(); $current=$reading['progress'];
    lpCheck($current['runId']===$first['runId'] && $current['stage']==='import:stop_times' && $current['completed']===125000 && $current['total']===200000 && $current['unit']==='rows','live counters remain attached to the same operation');
    lpCheck(!str_contains(json_encode($reading),'UNEXPECTED_FIXTURE') && !isset($current['setup_token'],$current['password_hash']),'progress events cannot add credential fields to the status response');
    lpCheck($current['percent']===55.0 || $current['percent']===55,'stage progress is reported without changing its meaning');
    $before=file_get_contents($path);
    lpReject(static fn()=>pm_lock(),'a competing operation cannot acquire an active maintenance lock');
    lpCheck(file_get_contents($path)===$before,'a competing operation does not overwrite the active progress snapshot');

    pm_progress_report(['stage'=>'verify','label'=>'Checking readiness','message'=>'Fixture readiness check.','percent'=>100]);
    $verifying=pm_status()['progress'];
    lpCheck($verifying['status']==='running' && $verifying['percent']<100,'running verification cannot claim one hundred percent completion');
    lpCheck(!isset($verifying['completed'],$verifying['total'],$verifying['unit']),'counters from a previous stage do not mislabel a later task');
    pm_import_progress(['stage'=>'complete','label'=>'Timetable imported','message'=>'Fixture import finished.','status'=>'complete','percent'=>100]);
    $imported=pm_status()['progress'];
    lpCheck($imported['status']==='running' && $imported['percent']<100,'import completion alone cannot mark the complete installation ready');
    pm_progress_finish('failed','Fixture readiness was not verified.');
    flock($lock,LOCK_UN); fclose($lock); $lock=null;
    $failed=pm_status();
    lpCheck(!$failed['running'] && !$failed['interrupted'] && $failed['progress']['status']==='failed' && $failed['progress']['percent']<100,'an ordinary failed operation remains failed rather than successful or interrupted');
    lpCheck(isset($failed['progress']['finishedAt']) && $failed['progress']['message']==='Fixture readiness was not verified.','a reported failure retains its finish time and useful reason');

    $lock=pm_lock(); pm_progress_begin('refresh');
    $second=pm_status()['progress'];
    lpCheck($second['runId']!==$first['runId'] && $second['percent']===0 && !isset($second['finishedAt']),'a retry has a fresh identity without inheriting old completion');
    pm_progress_report(['stage'=>'index','label'=>'Building timetable indexes','message'=>'SQLite is indexing the fixture database.','percent'=>88]);
    flock($lock,LOCK_UN); fclose($lock); $lock=null;
    $saved=file_get_contents($path);
    $interrupted=pm_status();
    lpCheck(!$interrupted['running'] && $interrupted['interrupted'] && $interrupted['progress']['status']==='interrupted','a stopped process with an unfinished snapshot is identified as interrupted');
    lpCheck($interrupted['progress']['label']==='Building timetable indexes' && $interrupted['progress']['percent']<100,'an interrupted job keeps its last real stage without inventing success');
    lpCheck(file_get_contents($path)===$saved,'reading interruption status does not mutate the saved report');
    lpCheck(is_file(PM_LOADER_PRIVATE.'/loader.lock'),'a normal persistent lock file is not deleted to clear interruption');

    $lock=pm_lock(); pm_progress_begin('refresh'); pm_progress_finish('complete','Fixture readiness verified.');
    flock($lock,LOCK_UN); fclose($lock); $lock=null;
    $complete=pm_status();
    lpCheck(!$complete['running'] && !$complete['interrupted'] && $complete['progress']['status']==='complete' && $complete['progress']['percent']===100,'only a successfully finished operation reports complete at one hundred percent');
    $lock=pm_lock();
    $starting=pm_status();
    lpCheck($starting['running'] && !$starting['interrupted'] && ($starting['progress']===null || $starting['progress']['status']!=='complete'),'a newly held lock cannot present the previous completed job as the new operation');
    flock($lock,LOCK_UN); fclose($lock); $lock=null;

    // Simulate an externally written snapshot; status must whitelist its output too.
    $state=pm_read_private($path); $state['setup_token']='UNEXPECTED_FIXTURE_SECRET'; $state['password_hash']='UNEXPECTED_FIXTURE_HASH';
    pm_write_private($path,$state);
    lpCheck(!str_contains(json_encode(pm_status()),'UNEXPECTED_FIXTURE'),'saved report fields are whitelisted independently from reporter input');
    file_put_contents($path,PM_LOADER_GUARD.'{broken');
    lpCheck(pm_status()['progress']===null,'a corrupt saved report is unavailable rather than a fatal status response');

    $outside=$isolated.'/outside-fixture.php';
    file_put_contents($outside,PM_LOADER_GUARD.json_encode($state)); $outsideHash=hash_file('sha256',$outside);
    unlink($path); symlink($outside,$path);
    lpCheck(pm_status()['progress']===null,'a progress symlink cannot expose an outside report');
    pm_progress_begin('refresh');
    lpCheck(hash_file('sha256',$outside)===$outsideHash,'failed progress persistence cannot overwrite a symlink target or abort the operation');
    unlink($path);

    $loaderLock=PM_LOADER_PRIVATE.'/loader.lock';
    unlink($loaderLock); symlink($outside,$loaderLock);
    $unknown=pm_status();
    lpCheck(!empty($unknown['lockUnknown']) && !$unknown['interrupted'],'an unsafe lock path is unknown rather than falsely free and retryable');
    lpReject(static fn()=>pm_lock(),'maintenance refuses an unsafe lock symlink');
    lpCheck(hash_file('sha256',$outside)===$outsideHash,'status and lock refusal leave outside files unchanged');

    echo PHP_EOL.$checks.' loader progress/status checks passed. Fixtures only; no downloads or keys.'.PHP_EOL;
} finally {
    if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); }
    pm_remove_tree($isolated);
}
