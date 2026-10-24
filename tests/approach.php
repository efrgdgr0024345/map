<?php
declare(strict_types=1);

/** Incoming vehicle path tests. The SQLite fixture is isolated in a temporary folder. */
$project=dirname(__DIR__);
$isolated=sys_get_temp_dir().'/perthmove-approach-test-'.bin2hex(random_bytes(6));
mkdir($isolated.'/src',0700,true);mkdir($isolated.'/var',0700,true);
copy($project.'/bootstrap.php',$isolated.'/bootstrap.php');
foreach(glob($project.'/src/*.php') as $source){copy($source,$isolated.'/src/'.basename($source));}
require $isolated.'/bootstrap.php';

use App\Geo;
use App\Transit;

$checks=0;
function approachCheck(bool $condition,string $message):void{
    global $checks;
    if(!$condition){throw new RuntimeException('FAIL: '.$message);}
    $checks++;echo 'PASS: '.$message.PHP_EOL;
}
function approachNear(array $point,array $expected,float $tolerance=0.000001):bool{
    return abs((float)$point[0]-(float)$expected[0])<$tolerance && abs((float)$point[1]-(float)$expected[1])<$tolerance;
}
function approachReject(callable $operation,string $message,string $type=InvalidArgumentException::class):void{
    $rejected=false;try{$operation();}catch(Throwable $error){$rejected=$error instanceof $type;}
    approachCheck($rejected,$message);
}
function approachCleanup(string $directory):void{
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){
        if($file->isDir()){rmdir($file->getPathname());}else{unlink($file->getPathname());}
    }
    rmdir($directory);
}

try{
    $midnight=strtotime(date('Y-m-d'));$today=date('Ymd',$midnight);$yesterday=date('Ymd',$midnight-86400);
    $noon=$midnight+12*3600;
    $db=new PDO('sqlite:'.APP_VAR.'/transit.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE meta(key TEXT PRIMARY KEY,value TEXT);
        CREATE TABLE routes(id TEXT PRIMARY KEY,name TEXT,type INTEGER);
        CREATE TABLE route_styles(route_id TEXT PRIMARY KEY,color TEXT,text_color TEXT);
        CREATE TABLE stops(id TEXT PRIMARY KEY,name TEXT,lat REAL,lon REAL);
        CREATE TABLE trips(id TEXT PRIMARY KEY,route_id TEXT,service_id TEXT,headsign TEXT,shape_id TEXT,start INTEGER,end INTEGER);
        CREATE TABLE stop_times(trip_id TEXT,stop_id TEXT,seq INTEGER,arrival INTEGER,departure INTEGER,pickup INTEGER,dropoff INTEGER,PRIMARY KEY(trip_id,seq));
        CREATE TABLE calendar(service_id TEXT PRIMARY KEY,mon INTEGER,tue INTEGER,wed INTEGER,thu INTEGER,fri INTEGER,sat INTEGER,sun INTEGER,start TEXT,end TEXT);
        CREATE TABLE exceptions(service_id TEXT,date TEXT,type INTEGER,PRIMARY KEY(service_id,date));
        CREATE TABLE shapes(id TEXT,seq INTEGER,lat REAL,lon REAL,PRIMARY KEY(id,seq));');
    $insertMeta=$db->prepare('INSERT INTO meta VALUES(?,?)');
    foreach(['start_date'=>date('Ymd',$midnight-2*86400),'end_date'=>date('Ymd',$midnight+7*86400),'imported_at'=>date(DATE_ATOM)] as $key=>$value){$insertMeta->execute([$key,$value]);}
    $db->exec("INSERT INTO routes VALUES('R','Synthetic incoming bus',3);
        INSERT INTO route_styles VALUES('R','FFFFFF','FFFFFF');");
    $stopRows=[['A','Trip origin',-31.95,115.85],['B','Repeated boarding stop',-31.95,115.86],['C','Later boarding stop',-31.94,115.86],['D','Trip terminal',-31.94,115.87]];
    $insertStop=$db->prepare('INSERT INTO stops VALUES(?,?,?,?)');
    $points=[];
    foreach($stopRows as $row){$insertStop->execute($row);$points[$row[0]]=['id'=>$row[0],'lat'=>$row[2],'lon'=>$row[3]];}
    $calendar=$db->prepare('INSERT INTO calendar VALUES(?,?,?,?,?,?,?,?,?,?)');
    foreach(['123','CANCELLED'] as $service){$calendar->execute([$service,1,1,1,1,1,1,1,date('Ymd',$midnight-2*86400),date('Ymd',$midnight+7*86400)]);}
    $exception=$db->prepare('INSERT INTO exceptions VALUES(?,?,?)');$exception->execute(['NIGHT',$yesterday,1]);$exception->execute(['CANCELLED',$today,2]);
    $shapes=[
        'BENT'=>[[-31.95,115.85],[-31.96,115.855],[-31.95,115.86],[-31.94,115.86],[-31.94,115.87]],
        'LOOP'=>[[-31.95,115.85],[-31.95,115.86],[-31.94,115.86],[-31.95,115.86],[-31.94,115.87]],
    ];
    $shapeInsert=$db->prepare('INSERT INTO shapes VALUES(?,?,?,?)');
    foreach($shapes as $id=>$line){foreach($line as $i=>$point){$shapeInsert->execute([$id,($i+1)*10,$point[0],$point[1]]);}}
    $tripInsert=$db->prepare('INSERT INTO trips VALUES(?,?,?,?,?,?,?)');
    $timeInsert=$db->prepare('INSERT INTO stop_times VALUES(?,?,?,?,?,?,?)');
    $addTrip=static function(string $id,string $service,string $shape,array $events)use($tripInsert,$timeInsert):void{
        $tripInsert->execute([$id,'R',$service,'Fixture destination',$shape,$events[0][2],$events[count($events)-1][1]]);
        foreach($events as $i=>[$stop,$arrival,$departure]){$timeInsert->execute([$id,$stop,($i+1)*10,$arrival,$departure,0,0]);}
    };
    $addTrip('T','123','BENT',[['A',43140,43200],['B',43800,43920],['C',44400,44460],['D',45000,45000]]);
    $addTrip('LOOP','123','LOOP',[['A',43140,43200],['B',43500,43560],['C',43800,43860],['B',44100,44160],['D',44400,44400]]);
    $addTrip('NIGHT','NIGHT','BENT',[['A',90000,90000],['B',90600,90720],['C',91200,91200]]);
    $addTrip('CHORD','123','',[['A',43200,43200],['B',43800,43920]]);
    $addTrip('CANCELLED','CANCELLED','BENT',[['A',43200,43200],['B',43800,43920]]);
    $transit=new Transit();
    $incoming=$transit->approach('T','C',$noon+360,$today);
    $segment=Geo::slice($shapes['BENT'],$points['A'],$points['B']);
    $expectedPosition=Geo::point($segment,0.6);
    approachCheck($incoming['phase']==='approaching' && !$incoming['upcoming'] && $incoming['estimated']===true,'incoming service is labelled approaching and remains a timetable estimate');
    approachCheck(approachNear($incoming['geometry'][0],[$incoming['position']['lat'],$incoming['position']['lon']]) && approachNear($incoming['geometry'][0],$expectedPosition),'incoming path starts at the current interpolated vehicle position');
    approachCheck(!in_array($shapes['BENT'][0],$incoming['geometry'],true) && !in_array($shapes['BENT'][1],$incoming['geometry'],true),'incoming path omits the trip origin and shape vertices already behind the bus');
    approachCheck(in_array([$points['B']['lat'],$points['B']['lon']],$incoming['geometry'],true) && approachNear(end($incoming['geometry']),[$points['C']['lat'],$points['C']['lon']]),'remaining geometry follows intermediate stops and ends at the selected boarding stop');
    approachCheck($incoming['secondsToStop']===840 && strtotime($incoming['arrival'])===$noon+1200 && strtotime($incoming['departure'])===$noon+1260,'incoming ETA counts down to stop arrival instead of the later departure after dwell');
    approachCheck($incoming['routeColor']==='#FFFFFF' && $incoming['routeTextColor']==='#000000' && $incoming['serviceDate']===$today,'approach payload carries its service date and readable provider route colours');

    $atStop=$transit->approach('T','B',$noon+660,$today);
    approachCheck($atStop['phase']==='at-stop' && $atStop['secondsToStop']===0 && $atStop['geometry']===[[-31.95,115.86]],'boarding-stop dwell is represented by one anchored point rather than a flashing full incoming line');
    approachCheck(approachNear([$atStop['position']['lat'],$atStop['position']['lon']],[-31.95,115.86]),'dwelling vehicle position remains on the actual boarding stop');
    $atDeparture=$transit->approach('T','B',$noon+720,$today);
    approachCheck($atDeparture['phase']==='at-stop','a service remains at its boarding stop at the exact scheduled departure instant');
    $departed=$transit->approach('T','B',$noon+721,$today);
    approachCheck($departed['phase']==='departed' && $departed['geometry']===[] && $departed['secondsToStop']===0,'a departed boarding visit has no incoming route or negative ETA');
    $notStarted=$transit->approach('T','B',$noon-300,$today);
    approachCheck($notStarted['phase']==='not-started' && $notStarted['upcoming'] && approachNear($notStarted['geometry'][0],[-31.95,115.85]),'an upcoming trip waits at its first stop before the service starts');
    $duringPreviousDwell=$transit->approach('T','C',$noon+660,$today);
    approachCheck($duringPreviousDwell['phase']==='approaching' && approachNear($duringPreviousDwell['geometry'][0],[-31.95,115.86]),'incoming path stays anchored during a dwell at an earlier stop');
    $finished=$transit->approach('T','D',$noon+1860,$today);
    approachCheck($finished['phase']==='departed' && $finished['geometry']===[],'a completed trip cannot display a future incoming path');

    $firstVisit=date(DATE_ATOM,$noon+360);$secondVisit=date(DATE_ATOM,$noon+960);
    $loopDefault=$transit->approach('LOOP','B',$noon+480,$today);
    approachCheck($loopDefault['phase']==='approaching' && strtotime($loopDefault['departure'])===$noon+960 && $loopDefault['secondsToStop']===420,'repeated stop identifiers default to the upcoming visit instead of a departed earlier lap');
    $first=$transit->approach('LOOP','B',$noon+480,$today,$firstVisit);
    $second=$transit->approach('LOOP','B',$noon+480,$today,$secondVisit);
    approachCheck($first['phase']==='departed' && $first['geometry']===[] && $second['phase']==='approaching','explicit boarding timestamps distinguish a departed visit from the next visit to the same stop');
    approachCheck(approachNear(end($second['geometry']),[-31.95,115.86]) && $second['arrival']!==$first['arrival'],'second-visit approach uses its own arrival time and boarding endpoint');
    $sameInstant=gmdate('Y-m-d\TH:i:s\Z',$noon+960);
    approachCheck($transit->approach('LOOP','B',$noon+480,$today,$sameInstant)['departure']===$second['departure'],'equivalent ISO timezone offsets select the same physical boarding visit');
    $night=$transit->approach('NIGHT','B',$midnight+3900,$yesterday);
    approachCheck($night['phase']==='approaching' && $night['serviceDate']===$yesterday && $night['secondsToStop']===300 && strtotime($night['arrival'])===$midnight+4200,'after-midnight incoming service keeps yesterday calendar identity and today actual arrival');
    $nightDefault=$transit->approach('NIGHT','B',$midnight+3900);
    approachCheck($nightDefault['serviceDate']===$yesterday,'default service-day selection finds the current previous-day trip beyond 24:00');
    $chord=$transit->approach('CHORD','B',$noon+300,$today);
    approachCheck(approachNear($chord['geometry'][0],[-31.95,115.855]) && approachNear(end($chord['geometry']),[-31.95,115.86]),'a missing trip shape uses only the remaining stop chord without jumping back to the origin');

    $tail=Geo::tail($shapes['BENT'],0.6);
    approachCheck(approachNear($tail[0],Geo::point($shapes['BENT'],0.6)) && approachNear(end($tail),end($shapes['BENT'])),'Geo tail begins at its distance-based current point and preserves the true endpoint');
    approachCheck(!in_array($shapes['BENT'][0],$tail,true) && count($tail)<count($shapes['BENT'])+1,'Geo tail removes the already-travelled prefix');
    $bounded=Geo::tail($shapes['BENT'],2.0);
    approachCheck(count(array_filter($bounded,static fn($point)=>!approachNear($point,[-31.94,115.87])))===0,'Geo tail never extrapolates beyond the final shape endpoint');
    approachCheck(Geo::tail([],0.5)===[] && Geo::tail([[-31.95,115.85]],0.5)===[[-31.95,115.85]],'empty and one-point tails remain safe stationary geometry');
    $zero=Geo::tail([[-31.95,115.85],[-31.95,115.85]],0.5);
    approachCheck(count($zero)>=1 && is_finite($zero[0][0]) && is_finite($zero[0][1]),'zero-length tail geometry produces finite coordinates');

    approachReject(static fn()=>$transit->approach('','B',$noon,$today),'missing trip identifier is rejected');
    approachReject(static fn()=>$transit->approach('T','',$noon,$today),'missing boarding stop identifier is rejected');
    approachReject(static fn()=>$transit->approach('UNKNOWN','B',$noon,$today),'unknown trip identifiers are rejected');
    approachReject(static fn()=>$transit->approach('T','UNKNOWN',$noon,$today),'a stop not on the chosen service is rejected');
    approachReject(static fn()=>$transit->approach('T','B',$noon,'not-a-date'),'invalid service dates are rejected');
    approachReject(static fn()=>$transit->approach('CANCELLED','B',$noon,$today),'a cancelled service cannot produce an incoming approach');
    approachReject(static fn()=>$transit->approach('T','B',$noon,$today,'not-a-time'),'malformed boarding timestamps are rejected');
    approachReject(static fn()=>$transit->approach('T','B',$noon,$today,date('Y-m-d',$noon).'T12:12:00'),'boarding timestamps without an explicit timezone are rejected');
    approachReject(static fn()=>$transit->approach('T','C',$noon,$today,date(DATE_ATOM,$noon+720)),'a valid timestamp belonging to a different stop visit is rejected');

    // Regression probes: impossible clock normalization and repeated shape vertices.
    approachReject(static fn()=>$transit->approach('T','A',$noon-60,$today,date('Y-m-d',$noon).'T11:59:60+08:00'),'a normalized invalid clock cannot impersonate a real scheduled boarding timestamp');
    $afterLoop=$transit->approach('LOOP','D',$noon+1020,$today);
    approachCheck(approachNear([$afterLoop['position']['lat'],$afterLoop['position']['lon']],[-31.9475,115.8625]) && !in_array([-31.94,115.86],$afterLoop['geometry'],true),'after a repeated stop visit the incoming path follows the remaining forward leg without retracing the previous loop');
    $db->exec("UPDATE meta SET value='20000101' WHERE key='end_date'");
    approachReject(static fn()=>(new Transit())->approach('T','B',$noon,$today),'expired timetable cannot advertise a current approaching service',RuntimeException::class);
    $db=null;
    echo PHP_EOL.$checks.' approach and remaining-path checks passed. Synthetic schedules only.'.PHP_EOL;
}finally{approachCleanup($isolated);}
