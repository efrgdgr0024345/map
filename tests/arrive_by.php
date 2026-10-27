<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Transit;
use App\StreetJourneys;
$path=sys_get_temp_dir().'/nextstop-arrival-'.bin2hex(random_bytes(6)).'.sqlite';$checks=0;
function abCheck(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label.PHP_EOL;}
try{
 $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec('CREATE TABLE meta(key TEXT PRIMARY KEY,value TEXT);CREATE TABLE stops(id TEXT PRIMARY KEY,name TEXT,lat REAL,lon REAL);CREATE TABLE routes(id TEXT PRIMARY KEY,name TEXT,type INTEGER);CREATE TABLE trips(id TEXT PRIMARY KEY,route_id TEXT,service_id TEXT,headsign TEXT,shape_id TEXT,start INTEGER,end INTEGER);CREATE TABLE stop_times(trip_id TEXT,stop_id TEXT,seq INTEGER,arrival INTEGER,departure INTEGER,pickup INTEGER,dropoff INTEGER,PRIMARY KEY(trip_id,seq));CREATE TABLE connections(trip_id TEXT,seq INTEGER,from_id TEXT,to_id TEXT,departure INTEGER,arrival INTEGER,pickup INTEGER,dropoff INTEGER);CREATE TABLE calendar(service_id TEXT PRIMARY KEY,mon INTEGER,tue INTEGER,wed INTEGER,thu INTEGER,fri INTEGER,sat INTEGER,sun INTEGER,start TEXT,end TEXT);CREATE TABLE exceptions(service_id TEXT,date TEXT,type INTEGER);CREATE TABLE shapes(id TEXT,seq INTEGER,lat REAL,lon REAL);CREATE TABLE transfers(from_stop_id TEXT,to_stop_id TEXT,transfer_type INTEGER,min_transfer_time INTEGER,from_route_id TEXT,to_route_id TEXT,from_trip_id TEXT,to_trip_id TEXT);');
 $now=strtotime(date('Y-m-d').' 10:00:00');$base=strtotime(date('Y-m-d',$now));
 $db->prepare('INSERT INTO meta VALUES (\'end_date\',?)')->execute([date('Ymd',$now+7*86400)]);
 $db->prepare('INSERT INTO calendar VALUES (\'D\',1,1,1,1,1,1,1,?,?)')->execute([date('Ymd',$now-86400),date('Ymd',$now+7*86400)]);
 $db->exec("INSERT INTO stops VALUES ('A','Home stop',-31.95,115.80),('B','Interchange',-31.95,115.88),('C','Appointment stop',-31.95,115.95);INSERT INTO routes VALUES ('BUS','10',3),('TRAIN','Fremantle Line',2);");
 $events=[['early','BUS','A','C',600,3000],['feeder','BUS','A','B',1200,1800],['train','TRAIN','B','C',2100,2700],['direct','BUS','A','C',1500,3600],['too-late','BUS','A','C',2400,3660]];
 foreach($events as [$id,$route,$a,$b,$dep,$arr]){$dep+=$now-$base;$arr+=$now-$base;$db->prepare('INSERT INTO trips VALUES (?,?,\'D\',\'Destination\',\'\',?,?)')->execute([$id,$route,$dep,$arr]);$db->prepare('INSERT INTO stop_times VALUES (?,?,10,?,?,0,0)')->execute([$id,$a,$dep,$dep]);$db->prepare('INSERT INTO stop_times VALUES (?,?,20,?,?,0,0)')->execute([$id,$b,$arr,$arr]);$db->prepare('INSERT INTO connections VALUES (?,10,?,?,?,?,0,0)')->execute([$id,$a,$b,$dep,$arr]);}
 $home=['lat'=>-31.9501,'lon'=>115.80];$goal=['lat'=>-31.9501,'lon'=>115.95];$deadline=$now+3660;
 $router=static function(array $a,array $b):array{$duration=isset($a['id'])?60:120;return ['routing'=>'street','duration'=>$duration,'distance'=>$duration*1.25,'geometry'=>[[$a['lat'],$a['lon']],[$b['lat'],$b['lon']]]];};
 $result=StreetJourneys::plan(new Transit($path),$home,$goal,$now,$router,null,$deadline);
 abCheck(count($result['journeys'])>0,'arrival-by search returns verified candidate journeys');
 $first=$result['journeys'][0];$rides=array_values(array_filter($first['legs'],fn($l)=>$l['mode']!=='walk'));
 abCheck($rides[0]['tripId']==='direct','latest feasible direct departure wins over an earlier arrival');
 abCheck(strtotime($first['arrival'])===$deadline,'final walking time fits the appointment deadline exactly');
 abCheck(strtotime($first['leaveAt'])===$now+1260,'leave time subtracts verified initial walking and boarding allowance');
 abCheck(!in_array('too-late',array_column($rides,'tripId'),true),'a service arriving too late is never offered');
 $elapsed=0.0;
 $futureRouter=static function(array $a,array $b)use(&$elapsed,$router):array{$elapsed+=2;return $router($a,$b);};
 $futureClock=static function()use(&$elapsed):float{return $elapsed;};
 $future=StreetJourneys::plan(new Transit($path),$home,$goal,$now,$futureRouter,$futureClock,null,3600);
 abCheck(strtotime($future['journeys'][0]['legs'][0]['departure'])===$now,'future not-before times do not drift forward while provider work runs');
 $longRouter=static function(array $a,array $b)use($router):array{$r=$router($a,$b);if(isset($a['id'])&&$b['lon']>115.94){$r['duration']=600;$r['distance']=750;}return $r;};
 $refined=StreetJourneys::plan(new Transit($path),$home,$goal,$now,$longRouter,null,$deadline);
 $rides=array_values(array_filter($refined['journeys'][0]['legs'],fn($l)=>$l['mode']!=='walk'));
 abCheck(array_column($rides,'tripId')===['feeder','train'],'a longer destination walk triggers an earlier feasible connection');
 abCheck(strtotime($refined['journeys'][0]['arrival'])<=$deadline,'refinement cannot retain a late-arriving appointment journey');
 $db->exec("INSERT INTO transfers VALUES ('B','B',3,NULL,'','','','')");
 $forbidden=StreetJourneys::plan(new Transit($path),$home,$goal,$now,$longRouter,null,$deadline);
 $rides=array_values(array_filter($forbidden['journeys'][0]['legs'],fn($l)=>$l['mode']!=='walk'));
 abCheck(array_column($rides,'tripId')===['early'],'reverse planning respects prohibited transfers');
 $db->exec("DELETE FROM transfers; INSERT INTO transfers VALUES ('B','B',2,600,'','','','')");
 $minimum=StreetJourneys::plan(new Transit($path),$home,$goal,$now,$longRouter,null,$deadline);
 $rides=array_values(array_filter($minimum['journeys'][0]['legs'],fn($l)=>$l['mode']!=='walk'));
 abCheck(array_column($rides,'tripId')===['early'],'reverse planning enforces the official minimum rather than only the boarding buffer');
 $db->exec("INSERT INTO trips VALUES ('night','BUS','D','Night destination','',90000,91200); INSERT INTO stop_times VALUES ('night','A',10,90000,90000,0,0),('night','C',20,91200,91200,0,0); INSERT INTO connections VALUES ('night',10,'A','C',90000,91200,0,0)");
 $night=StreetJourneys::plan(new Transit($path),$home,$goal,$base+3000,$router,null,$base+5400);
 $rides=array_values(array_filter($night['journeys'][0]['legs'],fn($l)=>$l['mode']!=='walk'));
 abCheck($rides[0]['tripId']==='night' && $rides[0]['serviceDate']===date('Ymd',$base-86400),'arrival-by search preserves the previous service day for after-midnight trips');
 abCheck(strtotime($night['journeys'][0]['arrival'])<=$base+5400,'after-midnight walking refinement still meets the actual local deadline');
 $empty=(new Transit($path))->planArrivingBy($home,$goal,$now+4000,$deadline);
 abCheck($empty['journeys']===[],'no departure is invented when the earliest start is after the deadline');
 abCheck(App\GoogleCalendar::dateTime('2026-10-27T12:00:00+08:00')===App\GoogleCalendar::dateTime('2026-10-27T04:00:00Z'),'planning clocks accept equivalent explicit timezones');
 $bad=false;try{App\GoogleCalendar::dateTime('2026-02-30T12:00:00+08:00');}catch(InvalidArgumentException){$bad=true;}abCheck($bad,'normalised invalid dates are rejected');
 echo $checks.' arrival-by checks passed. Synthetic timetable and walking fixtures.'.PHP_EOL;
}finally{$db=null;@unlink($path);}
