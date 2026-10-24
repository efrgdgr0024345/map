<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Demo;
use App\Geo;
use App\StreetJourneys;
use App\Transit;

$checks=0;
function sjCheck(bool $condition,string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: '.$message); }
    $checks++; echo 'PASS: '.$message.PHP_EOL;
}
function sjProvider(array $a,array $b,int $duration): array
{
    return ['geometry'=>[[$a['lat'],$a['lon']],[$a['lat']+0.0003,($a['lon']+$b['lon'])/2],[$b['lat'],$b['lon']]],
        'duration'=>$duration,'distance'=>$duration*1.25,'routing'=>'street','routingSource'=>'Synthetic pedestrian fixture',
        'approximate'=>false,'accessConnectors'=>[]];
}
function sjRide(string $id,array $a,array $b,int $departure,int $arrival): array
{
    return ['mode'=>'bus','tripId'=>$id,'routeId'=>'fixture','routeName'=>'Fixture', 'from'=>$a,'to'=>$b,
        'departure'=>date(DATE_ATOM,$departure),'arrival'=>date(DATE_ATOM,$arrival),'geometry'=>[[$a['lat'],$a['lon']],[$b['lat'],$b['lon']]]];
}
function sjJourney(array $legs): array
{
    return ['id'=>hash('sha256',json_encode($legs)),'departure'=>$legs[0]['departure'],'arrival'=>$legs[count($legs)-1]['arrival'],'legs'=>$legs];
}
function sjResponse(array $journeys): array { return ['mode'=>'timetable','journeys'=>$journeys,'message'=>'Initial estimate']; }
function sjService(callable $callback): object
{
    return new class($callback) {
        public int $calls=0;
        public function __construct(private $callback) {}
        public function plan(array $a,array $b,int $now,array $overrides=[]): array
        {
            $this->calls++; return ($this->callback)($a,$b,$now,$overrides,$this->calls);
        }
    };
}
function sjTransitFixture(string $path,int $now): Transit
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
    $db->prepare('INSERT INTO meta VALUES (?,?)')->execute(['end_date',date('Ymd',$now+7*86400)]);
    $db->exec("INSERT INTO stops VALUES ('A','Boarding stop',-31.95,115.85),('B','Arrival stop',-31.95,115.90);
        INSERT INTO routes VALUES ('R','Fixture bus',3);
        INSERT INTO shapes VALUES ('AB',10,-31.95,115.85),('AB',20,-31.95,115.90);");
    $db->prepare('INSERT INTO calendar VALUES (?,1,1,1,1,1,1,1,?,?)')->execute(['DAILY',date('Ymd',$now-86400),date('Ymd',$now+7*86400)]);
    $day=strtotime(date('Y-m-d',$now));
    foreach (['early'=>[300,900],'late'=>[900,1500],'following'=>[1200,1800]] as $id=>$times) {
        [$dep,$arr]=[$now-$day+$times[0],$now-$day+$times[1]];
        $db->prepare('INSERT INTO trips VALUES (?,\'R\',\'DAILY\',\'Fixture destination\',\'AB\',?,?)')->execute([$id,$dep,$arr]);
        $db->prepare('INSERT INTO stop_times VALUES (?,\'A\',10,?,?,0,0)')->execute([$id,$dep,$dep]);
        $db->prepare('INSERT INTO stop_times VALUES (?,\'B\',20,?,?,0,0)')->execute([$id,$arr,$arr]);
        $db->prepare('INSERT INTO connections VALUES (?,10,\'A\',\'B\',?,?,0,0)')->execute([$id,$dep,$arr]);
    }
    $db=null;
    return new Transit($path);
}

$temporary=sys_get_temp_dir().'/nextstop-street-'.bin2hex(random_bytes(6));
mkdir($temporary,0700,true);
try {
    $now=strtotime(date('Y-m-d').' 12:00:00');
    $home=['name'=>'Home','lat'=>-31.95,'lon'=>115.849];
    $a=['id'=>'A','name'=>'Board','lat'=>-31.95,'lon'=>115.85];
    $b=['id'=>'B','name'=>'Alight','lat'=>-31.95,'lon'=>115.90];
    $goal=['name'=>'Destination','lat'=>-31.95,'lon'=>115.901];
    $service=sjTransitFixture($temporary.'/transit.sqlite',$now);
    $initial=$service->plan($home,$goal,$now);
    $firstRide=array_values(array_filter($initial['journeys'][0]['legs'],static fn($leg)=>$leg['mode']!=='walk'))[0];
    sjCheck($firstRide['tripId']==='early','real timetable initially finds the bus feasible under its rough access estimate');
    $providerCalls=[];
    $provider=static function(array $from,array $to) use (&$providerCalls,$a): array {
        $key=Geo::walkingKey($from,$to); $providerCalls[$key]=($providerCalls[$key] ?? 0)+1;
        return sjProvider($from,$to,Geo::distance($to,$a)<1 ? 480 : 180);
    };
    $result=StreetJourneys::plan($service,$home,$goal,$now,$provider);
    $journey=$result['journeys'][0];
    $rides=array_values(array_filter($journey['legs'],static fn($leg)=>$leg['mode']!=='walk'));
    sjCheck(count($result['journeys'])===2 && $rides[0]['tripId']==='late','longer street access removes the missed bus and replans to a later feasible service');
    sjCheck($result['walkRouting']==='street' && $journey['walkRouting']==='street','only street-verified results are labelled street walking');
    sjCheck(strtotime($journey['leaveAt'])===$now+300 && $journey['walkToBoardSeconds']===480 && $journey['boardingBufferSeconds']===120,'leave-by uses actual walking duration plus a two-minute boarding allowance');
    sjCheck(strtotime($journey['boardingAt'])===$now+900,'boarding time remains the selected scheduled departure');
    sjCheck(strtotime($journey['arrival'])===$now+1680 && $journey['duration']===1680 && $journey['walkMinutes']===11,'street destination walking updates final arrival, total journey duration and walking minutes');
    $walks=array_values(array_filter($journey['legs'],static fn($leg)=>$leg['mode']==='walk'));
    sjCheck(count($walks[0]['geometry'])===3 && $walks[0]['routingSource']==='Synthetic pedestrian fixture' && $walks[0]['approximate']===false,'walk legs receive actual provider geometry and source rather than the original chord');
    sjCheck(count($providerCalls)===2 && max($providerCalls)===1,'shared endpoint walks are looked up once across alternatives and replanning rounds');
    sjCheck(strtotime($walks[0]['arrival'])+120<=strtotime($rides[0]['departure']),'the offered boarding remains physically feasible after road walking and its buffer');

    $calls=0;
    $failure=StreetJourneys::plan($service,$home,$goal,$now,static function() use (&$calls): array { $calls++; throw new RuntimeException('Private provider request must not appear in the response'); });
    sjCheck($failure['journeys']===[] && $failure['walkRouting']==='unavailable','a failed walking provider produces no fabricated road route or leave time');
    sjCheck(!str_contains(json_encode($failure),'Private provider request'),'raw provider errors and personal requests are not echoed to the client');
    sjCheck($calls===2,'a shared failed walking link is not retried for every bus alternative');

    $empty=sjService(static fn()=>['mode'=>'timetable','journeys'=>[],'message'=>'No timetable service']);
    $unused=0;
    $emptyResult=StreetJourneys::plan($empty,$home,$goal,$now,static function() use (&$unused): array { $unused++; return []; });
    sjCheck($unused===0 && $emptyResult['journeys']===[] && $emptyResult['message']==='No timetable service','no timetable journey does not trigger unnecessary external walking requests');

    $same=sjService(static fn()=>sjResponse([sjJourney([Demo::walk($a,$a,$now,$now),sjRide('at-stop',$a,$b,$now+30,$now+600)])]));
    $sameResult=StreetJourneys::plan($same,$a,$b,$now,static function(): array { throw new RuntimeException('A zero-length walk must not route'); });
    sjCheck(count($sameResult['journeys'])===1 && count($sameResult['journeys'][0]['legs'])===1,'coincident zero-length walks are omitted without routing');
    sjCheck($sameResult['journeys'][0]['walkToBoardSeconds']===0 && strtotime($sameResult['journeys'][0]['leaveAt'])===$now+30,'already being at the stop adds no fictitious walk or house buffer');

    $x=['id'=>'X','lat'=>-31.95,'lon'=>115.899];
    $transfer=sjService(static fn()=>sjResponse([
        sjJourney([sjRide('first',$a,$b,$now+120,$now+600),Demo::walk($b,$x,$now+600,$now+660),sjRide('missed-transfer',$x,$goal,$now+900,$now+1200)]),
        sjJourney([sjRide('first',$a,$b,$now+120,$now+600),Demo::walk($b,$x,$now+600,$now+660),sjRide('safe-transfer',$x,$goal,$now+1200,$now+1500)]),
    ]));
    $transfers=StreetJourneys::plan($transfer,$a,$goal,$now,static fn($from,$to)=>sjProvider($from,$to,300));
    $transferRides=array_values(array_filter($transfers['journeys'][0]['legs'],static fn($leg)=>$leg['mode']!=='walk'));
    sjCheck(count($transfers['journeys'])===1 && $transferRides[1]['tripId']==='safe-transfer','longer pedestrian transfers reject a missed connection while preserving a feasible alternative');
    sjCheck($transfers['journeys'][0]['transfers']===1 && $transfers['journeys'][0]['walkMinutes']===5,'verified transfer counts and walk minutes remain consistent');

    $walkOnly=sjService(static fn()=>sjResponse([sjJourney([Demo::walk($home,$a,$now,$now+100)])]));
    $walking=StreetJourneys::plan($walkOnly,$home,$a,$now,static fn($from,$to)=>sjProvider($from,$to,180));
    sjCheck($walking['journeys'][0]['leaveAt']===null && $walking['journeys'][0]['boardingAt']===null && $walking['journeys'][0]['walkToBoardSeconds']===0,'a walking-only journey does not invent a transit boarding or leave deadline');
    sjCheck(strtotime($walking['journeys'][0]['arrival'])===$now+180,'walking-only arrival uses the verified pedestrian route');
    $invalid=StreetJourneys::plan($walkOnly,$home,$a,$now,static fn()=>['routing'=>'street','duration'=>60,'distance'=>75,'geometry'=>[[NAN,115.8],[-31.95,115.85]]]);
    sjCheck($invalid['journeys']===[],'invalid provider geometry cannot be labelled a street route');

    $many=[];
    for ($choice=0;$choice<2;$choice++) {
        $legs=[]; $point=$home;
        for ($i=0;$i<6;$i++) {
            $next=['lat'=>-31.95+$choice*0.001,'lon'=>115.85+$i*0.001];
            $legs[]=Demo::walk($point,$next,$now+$i*30,$now+($i+1)*30); $point=$next;
        }
        $many[]=sjJourney($legs);
    }
    $manyService=sjService(static fn()=>sjResponse($many)); $manyCalls=0;
    $bounded=StreetJourneys::plan($manyService,$home,$goal,$now,static function($from,$to) use (&$manyCalls): array { $manyCalls++; return sjProvider($from,$to,30); });
    sjCheck($manyCalls===8 && count($bounded['journeys'])===1 && $bounded['walkingLimited']===true,'lookup cap preserves an already verified candidate and omits unverified alternatives');

    $clock=0.0; $timedCalls=0;
    $timed=StreetJourneys::plan($manyService,$home,$goal,$now,static function($from,$to) use (&$clock,&$timedCalls): array {
        $timedCalls++; $clock+=6.0; return sjProvider($from,$to,30);
    },static function() use (&$clock): float { return $clock; });
    sjCheck($timedCalls===2 && $clock<=15 && $timed['journeys']===[],'time budget reserves the provider timeout before starting another network request');

    $slowClock=0.0;
    $departing=sjService(static fn()=>sjResponse([
        sjJourney([sjRide('just-departing',$a,$b,$now+4,$now+600),Demo::walk($b,$goal,$now+600,$now+660)]),
        sjJourney([sjRide('still-boardable',$a,$b,$now+30,$now+660),Demo::walk($b,$goal,$now+660,$now+720)]),
    ]));
    $afterLookup=StreetJourneys::plan($departing,$a,$goal,$now,static function($from,$to) use (&$slowClock): array {
        $slowClock+=6.0; return sjProvider($from,$to,60);
    },static function() use (&$slowClock): float { return $slowClock; });
    sjCheck(count($afterLookup['journeys'])===1 && $afterLookup['journeys'][0]['legs'][0]['tripId']==='still-boardable',
        'a service that departed during a slow directions lookup is not offered afterwards');
    sjCheck(strtotime($afterLookup['journeys'][0]['departure'])===$now+6,
        'planning time advances with bounded provider work rather than freezing at request start');

    $changing=sjService(static function($from,$to,$at,$overrides,$round): array {
        $end=['lat'=>-31.95,'lon'=>115.85+$round*0.001];
        return sjResponse([sjJourney([Demo::walk($from,$end,$at,$at+60)])]);
    });
    StreetJourneys::plan($changing,$home,$goal,$now,static fn($from,$to)=>sjProvider($from,$to,90));
    sjCheck($changing->calls===3,'planning rounds are bounded even when new candidate walks keep appearing');

    $demoCalls=0;
    $demo=StreetJourneys::plan(new Demo(),$home,$goal,$now,static function() use (&$demoCalls): array { $demoCalls++; return []; });
    sjCheck($demoCalls===0 && $demo['walkRouting']==='demo' && $demo['journeys'][0]['leaveAt']===null,'demo journeys stay illustrative and never claim verified street routing or a real leave time');
    echo PHP_EOL.$checks.' street-journey checks passed. Pedestrian fixtures only; no public service calls.'.PHP_EOL;
} finally {
    foreach (glob($temporary.'/*') as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($temporary);
}
