<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Geo;
use App\Demo;
use App\StreetJourneys;
use App\TransferRules;
use App\Transit;

$checks=0;
function trCheck(bool $condition,string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: '.$message); }
    $checks++; echo 'PASS: '.$message.PHP_EOL;
}
function trTables(PDO $db): void
{
    $db->exec('CREATE TABLE stop_details(stop_id TEXT PRIMARY KEY,parent_station TEXT,location_type INTEGER,description TEXT,platform_code TEXT,supported_modes TEXT);
        CREATE TABLE transfers(from_stop_id TEXT,to_stop_id TEXT,transfer_type INTEGER,min_transfer_time INTEGER,from_route_id TEXT,to_route_id TEXT,from_trip_id TEXT,to_trip_id TEXT);');
    $db->exec("INSERT INTO stop_details VALUES ('P','',1,'','',''),('B','P',0,'','',''),('X','P',0,'','','');");
}
function trRule(PDO $db,string $from,string $to,int $type,?int $minimum,array $context=[]): void
{
    $db->prepare('INSERT INTO transfers VALUES (?,?,?,?,?,?,?,?)')->execute([$from,$to,$type,$minimum,
        $context['fromRouteId'] ?? '',$context['toRouteId'] ?? '',$context['fromTripId'] ?? '',$context['toTripId'] ?? '']);
}
function trFixture(string $path,int $now,bool $sameStop=false,bool $twoFeeders=false): PDO
{
    $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE meta(key TEXT PRIMARY KEY,value TEXT);
        CREATE TABLE stops(id TEXT PRIMARY KEY,name TEXT,lat REAL,lon REAL);
        CREATE TABLE routes(id TEXT PRIMARY KEY,name TEXT,type INTEGER);
        CREATE TABLE trips(id TEXT PRIMARY KEY,route_id TEXT,service_id TEXT,headsign TEXT,shape_id TEXT,start INTEGER,end INTEGER);
        CREATE TABLE stop_times(trip_id TEXT,stop_id TEXT,seq INTEGER,arrival INTEGER,departure INTEGER,pickup INTEGER,dropoff INTEGER,PRIMARY KEY(trip_id,seq));
        CREATE TABLE connections(trip_id TEXT,seq INTEGER,from_id TEXT,to_id TEXT,departure INTEGER,arrival INTEGER,pickup INTEGER,dropoff INTEGER);
        CREATE TABLE calendar(service_id TEXT PRIMARY KEY,mon INTEGER,tue INTEGER,wed INTEGER,thu INTEGER,fri INTEGER,sat INTEGER,sun INTEGER,start TEXT,end TEXT);
        CREATE TABLE exceptions(service_id TEXT,date TEXT,type INTEGER,PRIMARY KEY(service_id,date));
        CREATE TABLE shapes(id TEXT,seq INTEGER,lat REAL,lon REAL,PRIMARY KEY(id,seq));');
    trTables($db);
    $db->prepare('INSERT INTO meta VALUES (?,?)')->execute(['end_date',date('Ymd',$now+7*86400)]);
    $db->exec("INSERT INTO stops VALUES ('A','Home stop',-31.95,115.85),('B','Bus stand',-31.95,115.90),('X','Platform 2',-31.95,115.9001),('P','Parent station',-31.95,115.9001),('C','Destination',-31.95,115.96);
        INSERT INTO routes VALUES ('FEED','Feeder bus',3),('TRAIN','Connecting train',2);");
    $db->prepare('INSERT INTO calendar VALUES (?,1,1,1,1,1,1,1,?,?)')->execute(['DAILY',date('Ymd',$now-86400),date('Ymd',$now+7*86400)]);
    $day=strtotime(date('Y-m-d',$now));
    $x=$sameStop ? 'B' : 'X';
    $trips=[['first','FEED','A','B',300,600],['short','TRAIN',$x,'C',780,1500],['later','TRAIN',$x,'C',960,1680]];
    if ($twoFeeders) { $trips[]=['second-feeder','FEED','A','B',330,630]; }
    foreach ($trips as [$id,$route,$from,$to,$dep,$arr]) {
        $dep+=$now-$day; $arr+=$now-$day;
        $db->prepare('INSERT INTO trips VALUES (?,?,\'DAILY\',\'Fixture destination\',\'\',?,?)')->execute([$id,$route,$dep,$arr]);
        $db->prepare('INSERT INTO stop_times VALUES (?,?,10,?,?,0,0)')->execute([$id,$from,$dep,$dep]);
        $db->prepare('INSERT INTO stop_times VALUES (?,?,20,?,?,0,0)')->execute([$id,$to,$arr,$arr]);
        $db->prepare('INSERT INTO connections VALUES (?,10,?,?,?,?,0,0)')->execute([$id,$from,$to,$dep,$arr]);
    }
    return $db;
}
function trRides(array $journey): array
{
    return array_values(array_map(static fn($leg)=>$leg['tripId'],array_filter($journey['legs'],static fn($leg)=>$leg['mode']!=='walk')));
}
function trPath(array $from,array $to,int $duration): array
{
    return ['geometry'=>[[$from['lat'],$from['lon']],[$from['lat']+.0001,($from['lon']+$to['lon'])/2],[$to['lat'],$to['lon']]],
        'duration'=>$duration,'distance'=>$duration*1.25,'routing'=>'street','routingSource'=>'Synthetic pedestrian fixture','approximate'=>false,'accessConnectors'=>[]];
}
function trRide(string $id,array $from,array $to,int $departure,int $arrival): array
{
    return ['mode'=>'bus','tripId'=>$id,'routeId'=>'fixture','routeName'=>'Fixture','from'=>$from,'to'=>$to,
        'departure'=>date(DATE_ATOM,$departure),'arrival'=>date(DATE_ATOM,$arrival)];
}
function trService(array $journeys): object
{
    return new class($journeys) {
        public function __construct(private array $journeys) {}
        public function plan(array $from,array $to,int $now,array $durations=[]): array
        {
            return ['mode'=>'timetable','journeys'=>$this->journeys];
        }
    };
}

$temporary=sys_get_temp_dir().'/nextstop-transfer-rules-'.bin2hex(random_bytes(6));
mkdir($temporary,0700,true);
try {
    $memory=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $legacy=new TransferRules($memory);
    trCheck(!$legacy->hasRules() && $legacy->between('B','X')['matched']===false && $legacy->between('B','X')['allowed'],'old databases without optional tables preserve normal transfer behaviour');
    trTables($memory); trRule($memory,'P','P',2,300);
    $rules=new TransferRules($memory); $rule=$rules->between('B','X');
    trCheck($rule['minimumSeconds']===300 && $rule['inherited'] && $rule['fromStopId']==='P','parent station minimum applies to child bus stands and platforms');
    trCheck($rule['source']==='Transperth GTFS transfers.txt' && $rule['allowed'],'official minimum has explicit provenance and permits a sufficiently long connection');
    trRule($memory,'B','X',2,180); $rules=new TransferRules($memory);
    trCheck($rules->between('B','X')['minimumSeconds']===180 && !$rules->between('B','X')['inherited'],'exact stop pair overrides a generic inherited station minimum');
    trCheck($rules->between('X','B')['minimumSeconds']===300,'a stop-specific directional exception does not overwrite the reverse transfer');
    trRule($memory,'P','P',2,360,['fromRouteId'=>'FEED','toRouteId'=>'TRAIN']); $rules=new TransferRules($memory);
    trCheck($rules->between('B','X',['fromRouteId'=>'FEED','toRouteId'=>'TRAIN'])['minimumSeconds']===360,'matching route-qualified rule overrides an unqualified stop rule');
    trCheck($rules->between('B','X',['fromRouteId'=>'OTHER','toRouteId'=>'TRAIN'])['minimumSeconds']===180,'route-qualified rules do not affect unrelated services');
    trRule($memory,'P','P',2,420,['fromTripId'=>'first']); $rules=new TransferRules($memory);
    trCheck($rules->between('B','X',['fromTripId'=>'first','fromRouteId'=>'FEED','toRouteId'=>'TRAIN'])['minimumSeconds']===420,'trip-qualified rule takes precedence over a route-qualified rule');
    trRule($memory,'B','X',1,null,['fromTripId'=>'first','toTripId'=>'short']); $rules=new TransferRules($memory);
    trCheck($rules->between('B','X',['fromTripId'=>'first','toTripId'=>'short','fromRouteId'=>'FEED','toRouteId'=>'TRAIN'])['type']===1,'two-trip timed-transfer rule is selected at its most specific scope');
    trCheck($rules->between('B','X')['minimumSeconds']===180 && $rules->hasQualifiedRules(),'missing trip context cannot accidentally apply a trip-specific exception');
    trRule($memory,'B','X',3,null); $rules=new TransferRules($memory);
    trCheck(!$rules->between('B','X')['allowed'],'an exact forbidden transfer overrides an inherited permitted station connection');
    trRule($memory,'B','X',2,180); $rules=new TransferRules($memory);
    trCheck(!$rules->between('B','X')['allowed'],'conflicting equally specific rows cannot silently allow a forbidden transfer');
    trRule($memory,'A','Z',4,null); trRule($memory,'Z','A',5,null); $rules=new TransferRules($memory);
    trCheck(!$rules->between('A','Z')['allowed'] && !$rules->between('Z','A')['allowed'],'unsupported vehicle interlining constraints do not promise a normal walking transfer');
    trRule($memory,'','',4,null,['fromTripId'=>'through-a','toTripId'=>'through-b']); $rules=new TransferRules($memory);
    trCheck(!$rules->between('B','X',['fromTripId'=>'through-a','toTripId'=>'through-b'])['allowed'],'trip-only interlining constraints with blank stop IDs cannot silently become ordinary walking transfers');
    trCheck($rules->between('unknown','missing')['matched']===false,'absent transfer pairs do not invent an official minimum');

    $now=strtotime(date('Y-m-d').' 12:00:00');
    $home=['name'=>'Home','lat'=>-31.95,'lon'=>115.8498];
    $a=['id'=>'A','name'=>'Home stop','lat'=>-31.95,'lon'=>115.85];
    $goal=['id'=>'C','name'=>'Destination','lat'=>-31.95,'lon'=>115.96];
    $path=$temporary.'/station.sqlite'; $db=trFixture($path,$now); trRule($db,'P','P',2,300); $db=null;
    $hash=hash_file('sha256',$path); $service=new Transit($path);
    $raw=$service->plan($a,$goal,$now);
    trCheck(trRides($raw['journeys'][0])===['first','later'],'planner rejects a physically short connection that violates the official station minimum');
    $verified=StreetJourneys::plan($service,$home,$goal,$now,static fn($from,$to)=>trPath($from,$to,isset($from['id'],$to['id']) ? 30 : 60));
    $journey=$verified['journeys'][0]; $rides=array_values(array_filter($journey['legs'],static fn($leg)=>$leg['mode']!=='walk'));
    $walks=array_values(array_filter($journey['legs'],static fn($leg)=>$leg['mode']==='walk'));
    trCheck(trRides($journey)===['first','later'] && $rides[1]['transferMinimumSeconds']===300,'street verification retains the official floor on the selected boarding leg');
    trCheck($walks[1]['duration']===30 && strtotime($walks[1]['arrival'])===$now+630,'official transfer waiting allowance does not inflate the actual walking path or time');
    trCheck(strtotime($journey['leaveAt'])===$now+120 && $journey['walkToBoardSeconds']===60 && $journey['boardingBufferSeconds']===120,'leave-home calculation remains first departure minus verified walk and the boarding allowance');
    trCheck(hash_file('sha256',$path)===$hash,'planning and street verification leave station metadata and timetable files unchanged');
    $longWalk=StreetJourneys::plan(new Transit($path),$a,$goal,$now,static fn($from,$to)=>trPath($from,$to,240));
    trCheck(trRides($longWalk['journeys'][0])===['first','later'],'walking time plus boarding buffer can exceed the official floor without adding it twice');
    $longWalkLegs=array_values(array_filter($longWalk['journeys'][0]['legs'],static fn($leg)=>$leg['mode']==='walk'));
    trCheck($longWalkLegs[0]['duration']===240 && strtotime($longWalkLegs[0]['arrival'])===$now+840,'a longer verified pedestrian path keeps its own duration instead of the transfer minimum');

    $samePath=$temporary.'/same.sqlite'; $db=trFixture($samePath,$now,true); trRule($db,'B','B',2,300); $db=null;
    $same=StreetJourneys::plan(new Transit($samePath),$a,$goal,$now,static fn($from,$to)=>trPath($from,$to,30));
    trCheck(trRides($same['journeys'][0])===['first','later'],'official minimum applies when two rides use the same stop with no explicit walking leg');
    trCheck(count($same['journeys'][0]['legs'])===2,'a same-stop transfer does not create fictitious walking geometry');

    $forbiddenPath=$temporary.'/forbidden.sqlite'; $db=trFixture($forbiddenPath,$now); trRule($db,'B','X',3,null); $db=null;
    trCheck((new Transit($forbiddenPath))->plan($a,$goal,$now)['journeys']===[],'planner never boards a service across an explicitly forbidden stop pair');

    $specificPath=$temporary.'/specific.sqlite'; $db=trFixture($specificPath,$now,false,true);
    trRule($db,'B','X',3,null,['fromTripId'=>'first','toTripId'=>'short']); $db=null;
    $specific=(new Transit($specificPath))->plan($a,$goal,$now);
    trCheck(trRides($specific['journeys'][0])===['second-feeder','short'],'a later allowed feeder is retained when an earlier feeder has a trip-specific forbidden connection');
    $db=new PDO('sqlite:'.$specificPath); $db->exec("UPDATE transfers SET transfer_type=2,min_transfer_time=300 WHERE from_trip_id='first'"); $db=null;
    $specific=(new Transit($specificPath))->plan($a,$goal,$now);
    trCheck(trRides($specific['journeys'][0])===['second-feeder','short'],'trip-specific longer minimum cannot hide another feeder that can catch the same connection');

    // Verify the final pedestrian pass independently of Transit's earlier filtering.
    $b=['id'=>'B','lat'=>-31.95,'lon'=>115.90]; $x=['id'=>'X','lat'=>-31.95,'lon'=>115.9001];
    $first=trRide('first',$a,$b,$now+300,$now+600);
    $tooSoon=trRide('too-soon',$x,$goal,$now+780,$now+1500)+['transferMinimumSeconds'=>300,'transferType'=>2];
    $safe=trRide('safe',$x,$goal,$now+960,$now+1680)+['transferMinimumSeconds'=>300,'transferType'=>2];
    $final=StreetJourneys::plan(trService([
        ['legs'=>[$first,Demo::walk($b,$x,$now+600,$now+630),$tooSoon]],
        ['legs'=>[$first,Demo::walk($b,$x,$now+600,$now+630),$safe]],
    ]),$a,$goal,$now,static fn($from,$to)=>trPath($from,$to,30));
    trCheck(count($final['journeys'])===1 && trRides($final['journeys'][0])===['first','safe'],'final walking verification independently rejects an insufficient official connection floor');
    $sameSoon=trRide('same-soon',$b,$goal,$now+780,$now+1500)+['transferMinimumSeconds'=>300,'transferType'=>2];
    $sameSafe=trRide('same-safe',$b,$goal,$now+960,$now+1680)+['transferMinimumSeconds'=>300,'transferType'=>2];
    $final=StreetJourneys::plan(trService([['legs'=>[$first,$sameSoon]],['legs'=>[$first,$sameSafe]]]),$a,$goal,$now);
    trCheck(count($final['journeys'])===1 && trRides($final['journeys'][0])===['first','same-safe'],'final verification enforces official minima on adjacent rides without an intervening walk');
    $forbidden=$sameSafe; $forbidden['transferType']=3;
    $final=StreetJourneys::plan(trService([['legs'=>[$first,$forbidden]]]),$a,$goal,$now);
    trCheck($final['journeys']===[],'final verification cannot pass a pre-labelled forbidden transfer');
    echo PHP_EOL.$checks.' official transfer-rule checks passed. Synthetic timetables and walking fixtures only.'.PHP_EOL;
} finally {
    foreach (glob($temporary.'/*') as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($temporary);
}
