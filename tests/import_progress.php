<?php
declare(strict_types=1);

/** Offline counters only. The fixed HTTPS downloader is simulated; no provider is contacted. */
namespace App {
    final class ImportProgressDownloadFixture {
        public static string $zip='';
        public static string $url='';
        public static array $options=[];
        public static bool $knownSize=true;
    }
    function curl_init(string $url): object { ImportProgressDownloadFixture::$url=$url; return new \stdClass(); }
    function curl_setopt_array(object $handle,array $options): bool { ImportProgressDownloadFixture::$options=$options; return true; }
    function curl_exec(object $handle): bool {
        $body=file_get_contents(ImportProgressDownloadFixture::$zip);
        $options=ImportProgressDownloadFixture::$options;
        $total=ImportProgressDownloadFixture::$knownSize ? strlen($body) : 0;
        $received=0;
        foreach (str_split($body,4096) as $chunk) {
            if ($options[\CURLOPT_WRITEFUNCTION]($handle,$chunk)!==strlen($chunk)) { return false; }
            $received+=strlen($chunk);
            if ($options[\CURLOPT_XFERINFOFUNCTION]($handle,(float)$total,(float)$received,0.0,0.0)!==0) { return false; }
        }
        return true;
    }
    function curl_getinfo(object $handle,int $option): int { return 200; }
    function curl_error(object $handle): string { return ''; }
}

namespace {
    $project=dirname(__DIR__);
    $isolated=sys_get_temp_dir().'/perthmove-import-progress-'.bin2hex(random_bytes(6));
    mkdir($isolated.'/src',0700,true); mkdir($isolated.'/var',0700,true);
    copy($project.'/bootstrap.php',$isolated.'/bootstrap.php');
    foreach (glob($project.'/src/*.php') as $source) { copy($source,$isolated.'/src/'.basename($source)); }
    require $isolated.'/bootstrap.php';

    use App\GtfsImporter;
    use App\ImportProgressDownloadFixture;

    $checks=0;
    function ipCheck(bool $ok,string $message): void {
        global $checks;
        if (!$ok) { throw new \RuntimeException('FAIL: '.$message); }
        $checks++; echo 'PASS: '.$message.PHP_EOL;
    }
    function ipRemove(string $directory): void {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($directory);
    }
    function ipEvents(array $events,string $stage,string $key=''): array {
        return array_values(array_filter($events,static fn($event)=>$event['stage']===$stage && ($key==='' || $event['key']===$key)));
    }

    try {
        $zipPath=$isolated.'/SECRET_LOCAL_ARCHIVE.zip';
        $archive=new \ZipArchive(); $archive->open($zipPath,\ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $stops="stop_id,stop_name,stop_lat,stop_lon\nA,SECRET_CSV_RECORD,-31.95,115.86\nB,Fixture destination,-31.9497,115.8603\n";
        for ($i=0;$i<2503;$i++) { $stops.='F'.$i.',Fixture stop,-31.951,115.861'."\n"; }
        $shapes="shape_id,shape_pt_lat,shape_pt_lon,shape_pt_sequence\n";
        for ($i=0;$i<3001;$i++) { $shapes.='S,'.(-31.95+$i/10_000_000).','.(115.86+$i/10_000_000).','.$i."\n"; }
        $files=[
            'stops.txt'=>$stops,
            'routes.txt'=>"route_id,route_short_name,route_type\nR,950,3\n",
            'trips.txt'=>"route_id,service_id,trip_id,shape_id\nR,DAILY,T,S\n",
            'stop_times.txt'=>"trip_id,arrival_time,departure_time,stop_id,stop_sequence\nT,12:00:00,12:00:00,A,1\nT,12:15:00,12:15:00,B,2\n",
            'calendar.txt'=>"service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\nDAILY,1,1,1,1,1,1,1,".date('Ymd',time()-86400).','.date('Ymd',time()+7*86400)."\n",
            'shapes.txt'=>$shapes,
        ];
        foreach ($files as $name=>$body) { $archive->addFromString($name,$body); }
        $archive->close();
        $events=[]; $times=[]; $pause=true;
        $observer=static function(array $event) use (&$events,&$times,&$pause): void {
            $events[]=$event; $times[]=hrtime(true)/1_000_000_000;
            // A deliberate slow observer makes the mid-file throttle measurable without a large fixture.
            if ($pause && $event['stage']==='import' && $event['key']==='stops' && $event['status']==='running' && $event['completed']===0) {
                $pause=false; usleep(1_050_000);
            }
        };
        $importer=new GtfsImporter();
        $result=$importer->import($zipPath,$observer);
        ipCheck($result['counts']['stops']===2505 && $result['counts']['shapes']===3001,'progress-enabled import publishes every fixture record');
        ipCheck(isset($result['routeCache']) && is_file(APP_VAR.'/routes.json.gz'),'progress preserves successful route-cache publication');
        $stages=array_column($events,'stage');
        $ordered=['archive','prepare','import','validate','connections','index','publish','route-cache','complete'];
        $last=-1;
        foreach ($ordered as $stage) {
            $first=array_search($stage,$stages,true);
            ipCheck($first!==false && $first>$last,'observer reports '.$stage.' after its preceding major phase');
            $last=$first;
        }
        ipCheck(!ipEvents($events,'download'),'a trusted local ZIP does not claim a network download');
        $rows=ipEvents($events,'import','stops');
        ipCheck($rows[0]['completed']===0 && $rows[0]['total']===null && $rows[0]['unit']==='rows','CSV progress starts with an unknown row total rather than an invented estimate');
        $lastRows=end($rows);
        ipCheck($lastRows['status']==='complete' && $lastRows['completed']===2505 && $lastRows['total']===2505 && $lastRows['percent']===100,'CSV completion reports the exact imported count');
        $middle=array_values(array_filter($rows,static fn($row)=>$row['status']==='running' && $row['completed']>0));
        ipCheck(count($middle)===1 && $middle[0]['completed']===1000,'same-file updates are throttled rather than emitted per row or chunk');
        ipCheck($middle[0]['bytesCompleted']>0 && $middle[0]['bytesTotal']===strlen($stops) && $middle[0]['percent']>0 && $middle[0]['percent']<100,'intermediate CSV percent comes from actual stream bytes');
        $metadata=ipEvents($events,'import','stop_details');
        ipCheck(end($metadata)['completed']===2505 && ipEvents($events,'import','route_styles')!==[],'repeated source CSV passes have distinct table progress identities');
        $optional=ipEvents($events,'import','transfers');
        ipCheck(end($optional)['status']==='skipped' && end($optional)['completed']===0,'absent optional files are explicitly skipped, not reported as imported');
        $connections=ipEvents($events,'connections','segments');
        ipCheck(end($connections)['completed']===1 && end($connections)['unit']==='connections','connection progress reports the actual linked segment count');
        $indexes=ipEvents($events,'index','indexes');
        ipCheck($indexes[0]['completed']===0 && end($indexes)['completed']===8 && end($indexes)['total']===8,'indexing identifies its eight real search-index operations');
        ipCheck(strpos(json_encode($events),'SECRET_')===false && strpos(json_encode($events),$isolated)===false,'observer messages contain no local archive path or CSV record contents');
        ipCheck(count($events)<80,'small imports have a bounded number of observer notifications');

        $before=count($events);
        $withoutObserver=$importer->import($zipPath);
        ipCheck(count($events)===$before && $withoutObserver['counts']===$result['counts'],'reusing the importer without an observer removes the previous callback');
        $throwing=$importer->import($zipPath,static function(array $event): void { throw new \RuntimeException('Closed progress output'); });
        ipCheck($throwing['counts']===$result['counts'] && is_file(APP_VAR.'/transit.sqlite'),'a throwing observer cannot fail or interrupt timetable publication');

        $hash=hash_file('sha256',APP_VAR.'/transit.sqlite');
        $bad=$isolated.'/bad.zip'; file_put_contents($bad,'not a ZIP');
        $failed=[]; $caught=false;
        try { $importer->import($bad,static function(array $event) use (&$failed): void { $failed[]=$event; }); }
        catch (\RuntimeException) { $caught=true; }
        ipCheck($caught && end($failed)['stage']==='failed' && end($failed)['status']==='failed','an import failure emits a terminal failure event and still throws its real error');
        ipCheck(!ipEvents($failed,'complete') && hash_file('sha256',APP_VAR.'/transit.sqlite')===$hash,'failed progress never reports completion or replaces the usable timetable');
        ipCheck(glob(APP_VAR.'/import-*.sqlite')===[],'failed imports remove their staging database');

        ImportProgressDownloadFixture::$zip=$zipPath;
        $download=[];
        $downloaded=$importer->import(null,static function(array $event) use (&$download): void { $download[]=$event; });
        $network=ipEvents($download,'download','gtfs'); $done=end($network);
        ipCheck($network[0]['completed']===0 && $network[0]['total']===null,'download begins without guessing a content length before the server reports it');
        ipCheck($done['status']==='complete' && $done['completed']===filesize($zipPath) && $done['total']===filesize($zipPath) && $done['percent']===100,'download completion uses actual received bytes and the simulated content length');
        ipCheck(ImportProgressDownloadFixture::$url===GtfsImporter::SOURCE && ImportProgressDownloadFixture::$options[CURLOPT_PROTOCOLS]===CURLPROTO_HTTPS && ImportProgressDownloadFixture::$options[CURLOPT_FOLLOWLOCATION]===false,'progress keeps the fixed verified HTTPS source and redirect restrictions');
        ipCheck(ImportProgressDownloadFixture::$options[CURLOPT_NOPROGRESS]===false && is_callable(ImportProgressDownloadFixture::$options[CURLOPT_XFERINFOFUNCTION]),'the cURL transfer callback supplies live byte progress');
        ipCheck($downloaded['counts']===$result['counts'] && glob(APP_VAR.'/gtfs-*')===[],'simulated remote imports preserve counts and remove the temporary ZIP');
        ImportProgressDownloadFixture::$knownSize=false;
        $unknown=[];
        $importer->import(null,static function(array $event) use (&$unknown): void { $unknown[]=$event; });
        $unknownDownload=ipEvents($unknown,'download','gtfs');
        ipCheck(end($unknownDownload)['completed']===filesize($zipPath) && end($unknownDownload)['percent']===100,'downloads without a content length still finish with measured bytes');
        echo PHP_EOL.$checks.' import progress checks passed. Synthetic records and simulated HTTPS only; no provider requests.'.PHP_EOL;
    } finally { ipRemove($isolated); }
}
