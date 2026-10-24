<?php
declare(strict_types=1);

/** Transfer guidance uses isolated GTFS data and injected pedestrian paths, never production data or HTTP. */
$project=dirname(__DIR__);
$isolated=sys_get_temp_dir().'/perthmove-transfer-test-'.bin2hex(random_bytes(6));
mkdir($isolated.'/src',0700,true); mkdir($isolated.'/var',0700,true);
copy($project.'/bootstrap.php',$isolated.'/bootstrap.php');
foreach (glob($project.'/src/*.php') as $source) { copy($source,$isolated.'/src/'.basename($source)); }
require $isolated.'/bootstrap.php';

use App\TransferGuide;

$checks=0;
function tgCheck(bool $condition,string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: '.$message); }
    $checks++; echo 'PASS: '.$message.PHP_EOL;
}
function tgReject(callable $operation,string $message,string $type=InvalidArgumentException::class): void {
    $rejected=false; try { $operation(); } catch (Throwable $error) { $rejected=$error instanceof $type; }
    tgCheck($rejected,$message);
}
function tgContains(array $bounds,array $point): bool {
    return $bounds[0]<=$point[1] && $bounds[2]>=$point[1] && $bounds[1]<=$point[0] && $bounds[3]>=$point[0];
}
function tgCleanup(string $directory): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($directory);
}

try {
    $midnight=strtotime(date('Y-m-d')); $today=date('Ymd',$midnight); $yesterday=date('Ymd',$midnight-86400);
    $database=APP_VAR.'/transit.sqlite';
    $db=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE meta(key TEXT PRIMARY KEY,value TEXT);
        CREATE TABLE routes(id TEXT PRIMARY KEY,name TEXT,type INTEGER);
        CREATE TABLE stops(id TEXT PRIMARY KEY,name TEXT,lat REAL,lon REAL);
        CREATE TABLE trips(id TEXT PRIMARY KEY,route_id TEXT,service_id TEXT,headsign TEXT,shape_id TEXT,start INTEGER,end INTEGER);
        CREATE TABLE stop_times(trip_id TEXT,stop_id TEXT,seq INTEGER,arrival INTEGER,departure INTEGER,pickup INTEGER,dropoff INTEGER,PRIMARY KEY(trip_id,seq));
        CREATE TABLE calendar(service_id TEXT PRIMARY KEY,mon INTEGER,tue INTEGER,wed INTEGER,thu INTEGER,fri INTEGER,sat INTEGER,sun INTEGER,start TEXT,end TEXT);
        CREATE TABLE exceptions(service_id TEXT,date TEXT,type INTEGER,PRIMARY KEY(service_id,date));');
    $insert=$db->prepare('INSERT INTO meta VALUES(?,?)');
    foreach (['start_date'=>date('Ymd',$midnight-2*86400),'end_date'=>date('Ymd',$midnight+7*86400),'imported_at'=>date(DATE_ATOM)] as $key=>$value) { $insert->execute([$key,$value]); }
    $db->exec("INSERT INTO routes VALUES('TRAIN','Fremantle Line',2),('BUS','950',3)");
    $insert=$db->prepare('INSERT INTO stops VALUES(?,?,?,?)');
    foreach ([['A','Perth Stn Platform 1',-31.95,115.86],['B','Perth Stn Platform 2',-31.9508,115.8607],
        ['COLOCATED','Perth Stn Stand 1',-31.95,115.86],['0','Zero stop identifier',-31.9501,115.86],
        ['NEAR','Nearby sign 2',-31.952,115.862],['OUTSIDE','Outside cluster',-31.9545,115.866],
        ['REMOTE','Distant station',-31.99,115.9]] as $row) { $insert->execute($row); }
    // A busy area exercises the cap, including numerous stops with exactly repeated display names.
    for ($i=0;$i<270;$i++) { $insert->execute(['NEAR-'.$i,'Perth Stn Stand 9',-31.95+($i%10)*0.00001,115.86+intdiv($i,10)*0.00001]); }
    $insert=$db->prepare('INSERT INTO calendar VALUES(?,?,?,?,?,?,?,?,?,?)');
    foreach (['DAILY','CANCELLED'] as $service) { $insert->execute([$service,1,1,1,1,1,1,1,date('Ymd',$midnight-2*86400),date('Ymd',$midnight+7*86400)]); }
    $insert=$db->prepare('INSERT INTO exceptions VALUES(?,?,?)');
    $insert->execute(['NIGHT',$yesterday,1]); $insert->execute(['CANCELLED',$today,2]);
    $trip=$db->prepare('INSERT INTO trips VALUES(?,?,?,?,?,?,?)');
    $time=$db->prepare('INSERT INTO stop_times VALUES(?,?,?,?,?,?,?)');
    $addTrip=static function(string $id,string $route,string $service,array $visits) use ($trip,$time): void {
        $trip->execute([$id,$route,$service,'Fixture destination','',$visits[0][1],end($visits)[1]]);
        foreach ($visits as $i=>[$stop,$clock,$pickup]) { $time->execute([$id,$stop,$i+1,$clock,$clock,$pickup,0]); }
    };
    $addTrip('VALID','TRAIN','DAILY',[['A',43200,0],['B',43800,0]]);
    $addTrip('LOOP','TRAIN','DAILY',[['A',43200,0],['B',43800,1],['A',44400,0],['B',45000,0]]);
    $addTrip('OTHER','BUS','DAILY',[['A',43200,0],['NEAR',43800,0]]);
    $addTrip('NO_PICKUP','TRAIN','DAILY',[['A',43200,0],['B',43800,1]]);
    $addTrip('CANCELLED','TRAIN','CANCELLED',[['A',43200,0],['B',43800,0]]);
    $addTrip('NIGHT','TRAIN','NIGHT',[['A',90000,0],['B',90600,0]]);
    $baselineHash=hash_file('sha256',$database);
    $calls=[];
    $geometry=[[-31.95,115.86],[-31.949,115.863],[-31.9508,115.8607]];
    $connector=['geometry'=>[[-31.9508,115.8607],[-31.95081,115.86071]],'distance'=>1.5,'duration'=>2,
        'routing'=>'unrouted_access','approximate'=>true,'message'=>'Short unverified access connection.'];
    $route=static function(array $from,array $to) use (&$calls,$geometry,$connector): array {
        $calls[]=[$from,$to];
        return ['geometry'=>$geometry,'duration'=>242.1,'distance'=>300.5,'routing'=>'street',
            'routingSource'=>'Synthetic verified pedestrian path','approximate'=>false,'accessConnectors'=>[$connector]];
    };
    $guide=static fn(string $a='A',string $b='B',?string $tripId=null,?string $date=null): array => TransferGuide::guide($a,$b,$tripId,$date,$database,$route);
    $result=$guide('A','B','VALID',$today);
    tgCheck($result['routing']==='street' && $result['pathAvailable'] && $result['geometry']===$geometry,'transfer preserves the supplied mapped pedestrian path and its bends');
    tgCheck($result['duration']===243 && $result['distance']===300.5 && $result['routingSource']==='Synthetic verified pedestrian path','walking duration rounds upward and retains measured distance and source');
    tgCheck($calls[0][0]['id']==='A' && $calls[0][1]['id']==='B' && $calls[0][1]['name']==='Perth Stn Platform 2','provider receives only trusted public GTFS endpoints including their cacheable IDs');
    tgCheck($result['from']['name']==='Perth Stn Platform 1' && $result['to']['name']==='Perth Stn Platform 2','guidance names the exact alighting and boarding platforms');
    tgCheck($result['station']['name']==='Perth Stn' && $result['station']['indoorMap']===false && $result['station']['kind']==='stop-area','station label groups platforms without claiming an indoor floor map');
    tgCheck($result['trip']['id']==='VALID' && $result['trip']['routeId']==='TRAIN' && $result['trip']['mode']==='train' && $result['trip']['serviceDate']===$today,'trip metadata comes from a service verified on the selected day');
    $stops=array_column($result['stops'],null,'id');
    tgCheck(count($stops)===250 && isset($stops['A'],$stops['B']) && $stops['B']['target'] && $stops['A']['origin'],'crowded stop areas are capped while preserving both endpoints and exact target');
    tgCheck(count(array_filter($result['stops'],static fn($stop)=>$stop['target']))===1 && !isset($stops['OUTSIDE'],$stops['REMOTE']),'target is unique and remote nearby-box candidates are filtered by actual distance');
    tgCheck(count(array_filter($result['stops'],static fn($stop)=>$stop['name']==='Perth Stn Stand 9'))>1,'repeated platform display names remain distinct stops by GTFS identifier');
    $bounds=$result['station']['bounds']; $contained=true;
    foreach ($result['stops'] as $stop) { $contained=$contained && tgContains($bounds,[$stop['lat'],$stop['lon']]); }
    foreach (array_merge($geometry,$connector['geometry']) as $point) { $contained=$contained && tgContains($bounds,$point); }
    tgCheck($contained,'station bounds include every displayed stop, pedestrian detour and access connector');
    tgCheck($result['accessConnectors']===[$connector],'unverified access gaps stay explicitly separate from the mapped route');
    tgCheck(!isset($guide()['trip']),'a station-only request does not invent a service');
    tgCheck($guide('A','B','LOOP',$today)['trip']['id']==='LOOP','repeated visits accept a trip that permits boarding on a later visit');
    tgCheck($guide('A','B','NIGHT',$yesterday)['trip']['serviceDate']===$yesterday,'over-24-hour GTFS trips retain their previous service date');
    tgReject(static fn()=>$guide('A','B','OTHER',$today),'an unrelated service cannot point the user to a platform it never serves');
    tgReject(static fn()=>$guide('A','B','NO_PICKUP',$today),'a drop-off-only platform visit cannot be presented as boarding');
    tgReject(static fn()=>$guide('A','B','CANCELLED',$today),'calendar cancellation is applied before station routing');
    tgReject(static fn()=>$guide('A','B','NIGHT',$today),'a valid trip on a different service date is rejected');
    tgReject(static fn()=>$guide('A','B','UNKNOWN',$today),'unknown services are rejected');
    $before=count($calls); $same=$guide('A','A'); $coincident=$guide('A','COLOCATED');
    tgCheck(count($calls)===$before && $same['routing']==='stationary' && $coincident['routing']==='stationary','same and colocated public stops need no external route request');
    tgCheck($same['geometry']===[[-31.95,115.86]] && $same['duration']===0 && $same['distance']===0.0 && $same['pathAvailable'],'stationary guidance is one anchored point with no fabricated walking leg');
    tgCheck(count(array_filter($same['stops'],static fn($stop)=>$stop['target'] && $stop['origin']))===1,'the same-stop case preserves a single target and origin marker');
    tgCheck($guide('0','0')['to']['id']==='0','numeric-looking public stop identifiers are valid without coercion');
    tgReject(static fn()=>$guide('A','REMOTE'),'transfers beyond 2 km are rejected before pedestrian network work');
    tgReject(static fn()=>$guide('','B'),'empty stop identifiers are rejected');
    tgReject(static fn()=>$guide('A','B'.chr(0)),'control characters are rejected');
    tgReject(static fn()=>$guide('A','MISSING'),'unknown public stops are rejected');
    tgReject(static fn()=>$guide('A','B',null,'20260230'),'normalized impossible service dates are rejected');
    tgReject(static fn()=>$guide('A','B',null,'not-a-date'),'malformed service dates are rejected');
    $failed=TransferGuide::guide('A','B',null,null,$database,static function(): array { throw new RuntimeException('SECRET provider error'); });
    tgCheck($failed['routing']==='unavailable' && !$failed['pathAvailable'] && $failed['geometry']===[] && $failed['distance']===null && $failed['duration']===null,'provider failure leaves no invented straight-line walking geometry or duration');
    tgCheck($failed['to']['id']==='B' && $failed['station']['bounds']!==[] && !str_contains(json_encode($failed),'SECRET'),'failed routing still shows station context without leaking provider internals');
    $invalid=TransferGuide::guide('A','B',null,null,$database,static fn(): array => ['geometry'=>[[-31.95,115.86],[-31.9508,115.8607]],'duration'=>100,'distance'=>100,'routing'=>'street','routingSource'=>'Fallback chord','approximate'=>true]);
    tgCheck($invalid['geometry']===[] && !$invalid['pathAvailable'],'an approximate chord cannot masquerade as a mapped pedestrian path');
    $invalid=TransferGuide::guide('A','B',null,null,$database,static fn(): array => ['geometry'=>[[NAN,115.86],[-31.9508,115.8607]],'duration'=>100,'distance'=>100,'routing'=>'street','routingSource'=>'Invalid']);
    tgCheck($invalid['geometry']===[] && !$invalid['pathAvailable'],'invalid path coordinates are rejected safely');
    tgCheck(hash_file('sha256',$database)===$baselineHash,'transfer requests leave the SQLite timetable byte-for-byte unchanged');
    tgCheck(count(glob(APP_VAR.'/*'))===1,'injected route tests persist no personal location or incidental cache files');
    tgReject(static fn()=>TransferGuide::guide('A','B',null,null,APP_VAR.'/missing.sqlite',$route),'missing timetable is rejected without creating a SQLite file',RuntimeException::class);
    tgCheck(!file_exists(APP_VAR.'/missing.sqlite'),'missing read-only database path is not created');
    $db->exec("UPDATE meta SET value='20000101' WHERE key='end_date'");
    tgReject(static fn()=>$guide(),'expired timetable cannot supply current transfer guidance',RuntimeException::class);
    $db=null;
    echo PHP_EOL.$checks.' transfer guidance checks passed. Synthetic schedules and paths only.'.PHP_EOL;
} finally { tgCleanup($isolated); }
