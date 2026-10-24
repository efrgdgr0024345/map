<?php
declare(strict_types=1);

/** Imported station groups and official transfer rules; no provider or production data is touched. */
$project=dirname(__DIR__);
$isolated=sys_get_temp_dir().'/perthmove-station-metadata-'.bin2hex(random_bytes(6));
mkdir($isolated.'/src',0700,true); mkdir($isolated.'/var',0700,true);
copy($project.'/bootstrap.php',$isolated.'/bootstrap.php');
foreach (glob($project.'/src/*.php') as $source) { copy($source,$isolated.'/src/'.basename($source)); }
require $isolated.'/bootstrap.php';

use App\GtfsImporter;

$checks=0;
function smCheck(bool $condition,string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: '.$message); }
    $checks++; echo 'PASS: '.$message.PHP_EOL;
}
function smCsv(array $header,array $rows): string
{
    $stream=fopen('php://temp','r+'); fputcsv($stream,$header,',','"','',"\n");
    foreach ($rows as $row) { fputcsv($stream,$row,',','"','',"\n"); }
    rewind($stream); $text=stream_get_contents($stream); fclose($stream); return $text;
}
function smZip(string $file,array $files): void
{
    $zip=new ZipArchive();
    if ($zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) { throw new RuntimeException('Could not write fixture ZIP.'); }
    foreach ($files as $name=>$text) { $zip->addFromString($name,$text); }
    $zip->close();
}
function smImport(array $files): array
{
    smZip(APP_ROOT.'/fixture.zip',$files); return (new GtfsImporter())->import(APP_ROOT.'/fixture.zip');
}
function smRejected(array $files,string $label): void
{
    $hash=hash_file('sha256',APP_VAR.'/transit.sqlite'); $rejected=false;
    try { smImport($files); } catch (RuntimeException) { $rejected=true; }
    smCheck($rejected && hash_file('sha256',APP_VAR.'/transit.sqlite')===$hash && glob(APP_VAR.'/import-*.sqlite')===[], $label.'; previous timetable is unchanged and staging is cleaned');
}
function smRemove(string $path): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

try {
    $header=['stop_id','stop_name','stop_lat','stop_lon','location_type','parent_station','stop_desc','platform_code','supported_modes'];
    $stops=[
        ['S','Example Station',-31.95,115.86,1,'','Official station area','','Bus,Rail'],
        ['A','Example Station Platform 2',-31.9501,115.8601,0,'S','Use signed station access','2','Rail'],
        ['B','Example Station Stand 4',-31.9502,115.8602,0,'S','Board at stand 4','4','Bus'],
        ['E','Example entrance',-31.9503,115.8603,2,'S','','',''],
        ['N','Example connecting node',-31.9504,115.8604,3,'S','','',''],
        ['Z','Example boarding area',-31.9501,115.8601,4,'A','','',''],
        ['T','Other Station',-31.96,115.87,1,'','','','Rail'],
        ['D','Separate stop',-31.97,115.88,'','','','','Bus'],
    ];
    $transferHeader=['from_stop_id','to_stop_id','transfer_type','min_transfer_time','from_route_id','to_route_id','from_trip_id','to_trip_id'];
    $transfers=[
        ['B','A',2,239,'','','',''],
        ['S','T',2,300,'','','',''],
        ['A','B',3,'','','','',''],
        ['A','B',0,'','RAIL','BUS','rail','bus'],
        ['A','A',1,'','','','',''],
        ['','',4,'','','','bus','rail'],
        ['','',5,'','','','rail','bus'],
    ];
    $start=date('Ymd',time()-86400); $end=date('Ymd',time()+7*86400);
    $files=[
        'stops.txt'=>smCsv($header,$stops),
        'routes.txt'=>smCsv(['route_id','route_short_name','route_type'],[['BUS','100',3],['RAIL','Example Line',2]]),
        'trips.txt'=>smCsv(['route_id','service_id','trip_id'],[['BUS','DAILY','bus'],['RAIL','DAILY','rail']]),
        'stop_times.txt'=>smCsv(['trip_id','arrival_time','departure_time','stop_id','stop_sequence'],[
            ['bus','12:00:00','12:00:00','B',1],['bus','12:20:00','12:20:00','D',2],
            ['rail','12:05:00','12:05:00','A',1],['rail','12:15:00','12:15:00','D',2],
        ]),
        'calendar.txt'=>smCsv(['service_id','monday','tuesday','wednesday','thursday','friday','saturday','sunday','start_date','end_date'],[['DAILY',1,1,1,1,1,1,1,$start,$end]]),
        'transfers.txt'=>smCsv($transferHeader,$transfers),
    ];
    $result=smImport($files);
    smCheck($result['counts']['stops']===8 && $result['counts']['stop_details']===8 && $result['counts']['transfers']===7,'station metadata and transfer rule counts are exposed with existing timetable counts');
    $db=new PDO('sqlite:'.APP_VAR.'/transit.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    smCheck(array_column($db->query('PRAGMA table_info(stops)')->fetchAll(),'name')===['id','name','lat','lon'],'the existing stops table stays compatible with older readers');
    $platform=$db->query("SELECT * FROM stop_details WHERE stop_id='A'")->fetch();
    smCheck($platform['parent_station']==='S' && $platform['location_type']===0 && $platform['description']==='Use signed station access' && $platform['platform_code']==='2' && $platform['supported_modes']==='Rail','exact parent, description, platform code and mode metadata survive import');
    smCheck($db->query("SELECT supported_modes FROM stop_details WHERE stop_id='S'")->fetchColumn()==='Bus,Rail','quoted multi-mode fields are preserved without splitting or guessing');
    smCheck($db->query("SELECT location_type FROM stop_details WHERE stop_id='D'")->fetchColumn()===0,'an empty optional location type defaults to a stop/platform');
    smCheck($db->query("SELECT parent_station FROM stop_details WHERE stop_id='Z'")->fetchColumn()==='A','boarding areas retain their real platform parent rather than being flattened to a station');
    $rule=$db->query("SELECT * FROM transfers WHERE from_stop_id='B' AND to_stop_id='A'")->fetch();
    smCheck($rule['transfer_type']===2 && $rule['min_transfer_time']===239,'official minimum transfer time is preserved exactly as seconds');
    smCheck($db->query("SELECT min_transfer_time FROM transfers WHERE from_stop_id='S' AND to_stop_id='T'")->fetchColumn()===300,'station-to-station rules retain parent station identifiers');
    smCheck($db->query("SELECT min_transfer_time FROM transfers WHERE transfer_type=3")->fetchColumn()===null,'forbidden transfer rules remain distinct and do not gain a fabricated minimum');
    $qualified=$db->query("SELECT * FROM transfers WHERE from_trip_id='rail' AND to_trip_id='bus' AND transfer_type=0")->fetch();
    smCheck($qualified['from_route_id']==='RAIL' && $qualified['to_route_id']==='BUS','route and trip qualifiers remain attached to their own rule');
    smCheck((int)$db->query("SELECT COUNT(*) FROM transfers WHERE transfer_type IN(4,5) AND from_stop_id='' AND to_stop_id=''")->fetchColumn()===2,'in-seat transfer types can retain their trip-only endpoints');
    $indexes=$db->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(PDO::FETCH_COLUMN);
    smCheck(count(array_intersect(['stop_details_parent','transfers_stops','transfers_reverse'],$indexes))===3,'station grouping and both transfer lookup directions are indexed');
    $db=null;

    $spaced=$files;
    $spaced['stops.txt']="\xEF\xBB\xBF".smCsv(array_map(static fn($column)=>' '.$column.' ',$header),$stops);
    $spaced['transfers.txt']=smCsv(array_map(static fn($column)=>' '.$column.' ',$transferHeader),$transfers);
    $spacedResult=smImport($spaced);
    smCheck($spacedResult['counts']['stop_details']===8 && $spacedResult['counts']['transfers']===7,'BOM and official whitespace-padded station and transfer headers are accepted');

    foreach ([['location_type','abc'],['location_type',5],['location_type',-1],['location_type','0.5'],['parent_station','MISSING'],['parent_station','A']] as [$column,$value]) {
        $bad=$files; $rows=$stops; $rows[1][array_search($column,$header,true)]=$value; $bad['stops.txt']=smCsv($header,$rows);
        smRejected($bad,'invalid station metadata '.$column.'='.json_encode($value).' is rejected');
    }
    $cycle=$files; $cycleStops=$stops; $cycleStops[0][5]='A'; $cycle['stops.txt']=smCsv($header,$cycleStops);
    smRejected($cycle,'a parent station cycle is rejected');
    foreach ([['transfer_type','x'],['transfer_type',6],['min_transfer_time',''],['min_transfer_time',-1],['min_transfer_time','2.5'],['min_transfer_time',(string)PHP_INT_MAX.'0'],['from_stop_id','MISSING'],['to_stop_id','MISSING'],['from_route_id','MISSING'],['from_trip_id','MISSING']] as [$column,$value]) {
        $bad=$files; $rows=$transfers; $rows[0][array_search($column,$transferHeader,true)]=$value; $bad['transfers.txt']=smCsv($transferHeader,$rows);
        smRejected($bad,'invalid transfer metadata '.$column.'='.json_encode($value).' is rejected');
    }
    $mismatch=$files; $mismatchRows=$transfers; $mismatchRows[3][4]='BUS'; $mismatch['transfers.txt']=smCsv($transferHeader,$mismatchRows);
    smRejected($mismatch,'inconsistent route and trip qualifiers are rejected');
    $missingEndpoint=$files; $endpointRows=$transfers; $endpointRows[0][0]=''; $missingEndpoint['transfers.txt']=smCsv($transferHeader,$endpointRows);
    smRejected($missingEndpoint,'a stop-based transfer without both endpoints is rejected');
    $missingTrip=$files; $tripRows=$transfers; $tripRows[5][6]=''; $missingTrip['transfers.txt']=smCsv($transferHeader,$tripRows);
    smRejected($missingTrip,'an in-seat transfer without both trip endpoints is rejected');
    $missingColumn=$files; $missingColumn['transfers.txt']=smCsv(['from_stop_id','to_stop_id'],[['B','A']]);
    smRejected($missingColumn,'a supplied transfer file missing its required type column is rejected');
    $truncated=$files; $truncated['transfers.txt']=smCsv($transferHeader,[['B','A',2]]);
    smRejected($truncated,'a truncated transfer CSV row is rejected');

    $minimal=$files; unset($minimal['transfers.txt']);
    $minimal['stops.txt']=smCsv(['stop_id','stop_name','stop_lat','stop_lon'],array_map(static fn($row)=>array_slice($row,0,4),$stops));
    $minimalResult=smImport($minimal);
    $db=new PDO('sqlite:'.APP_VAR.'/transit.sqlite',null,null,[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    smCheck($minimalResult['counts']['transfers']===0 && (int)$db->query('SELECT COUNT(*) FROM transfers')->fetchColumn()===0,'a feed without optional transfers.txt remains valid with an empty rule table');
    $defaults=$db->query("SELECT * FROM stop_details WHERE stop_id='A'")->fetch();
    smCheck($defaults===['stop_id'=>'A','parent_station'=>'','location_type'=>0,'description'=>'','platform_code'=>'','supported_modes'=>''],'a feed with only original stop fields receives explicit empty station metadata defaults');
    $db=null;

    $tripOnly=$files;
    $tripOnly['transfers.txt']=smCsv(['transfer_type','from_trip_id','to_trip_id'],[[4,'bus','rail'],[5,'rail','bus']]);
    $tripOnlyResult=smImport($tripOnly);
    smCheck($tripOnlyResult['counts']['transfers']===2,'trip-only in-seat rules need no irrelevant stop endpoint columns');

    $zero=$files; $zeroRows=$transfers; $zeroRows[0][3]='0000'; $zero['transfers.txt']=smCsv($transferHeader,$zeroRows);
    smImport($zero); $db=new PDO('sqlite:'.APP_VAR.'/transit.sqlite');
    smCheck($db->query("SELECT min_transfer_time FROM transfers WHERE from_stop_id='B' AND to_stop_id='A'")->fetchColumn()===0,'a supplied zero minimum is preserved without becoming null or a boarding allowance');
    $db=null;
    echo PHP_EOL.$checks.' station metadata import checks passed. Synthetic GTFS only; no provider requests or production database changes.'.PHP_EOL;
} finally { smRemove($isolated); }
