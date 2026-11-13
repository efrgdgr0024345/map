<?php
declare(strict_types=1);
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/nextstop-nearby-services-'.bin2hex(random_bytes(6));mkdir($tmp.'/src',0700,true);mkdir($tmp.'/var',0700,true);
copy($root.'/bootstrap.php',$tmp.'/bootstrap.php');foreach(glob($root.'/src/*.php') as $f)copy($f,$tmp.'/src/'.basename($f));require $tmp.'/bootstrap.php';
use App\Transit;
$checks=0;function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$message);$checks++;echo "PASS: $message\n";}
try{
 $db=new PDO('sqlite:'.APP_VAR.'/transit.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec('CREATE TABLE meta(key TEXT,value TEXT);CREATE TABLE stops(id TEXT PRIMARY KEY,name TEXT,lat REAL,lon REAL);CREATE TABLE routes(id TEXT PRIMARY KEY,name TEXT,type INTEGER);
 CREATE TABLE trips(id TEXT PRIMARY KEY,route_id TEXT,service_id TEXT,headsign TEXT,shape_id TEXT,start INTEGER,end INTEGER);
 CREATE TABLE stop_times(trip_id TEXT,stop_id TEXT,seq INTEGER,arrival INTEGER,departure INTEGER,pickup INTEGER,dropoff INTEGER,PRIMARY KEY(trip_id,seq));
 CREATE TABLE calendar(service_id TEXT,mon INTEGER,tue INTEGER,wed INTEGER,thu INTEGER,fri INTEGER,sat INTEGER,sun INTEGER,start TEXT,end TEXT);
 CREATE TABLE exceptions(service_id TEXT,date TEXT,type INTEGER);CREATE TABLE shapes(id TEXT,seq INTEGER,lat REAL,lon REAL,PRIMARY KEY(id,seq));');
 $db->exec("INSERT INTO meta VALUES('end_date','20991231'),('imported_at','fixture');INSERT INTO calendar VALUES('daily',1,1,1,1,1,1,1,'20000101','20991231');
 INSERT INTO routes VALUES('B','915',3),('T','Rail',2),('F','Ferry',4),('B2','915',3);");
 $base=strtotime(date('Y-m-d'));$now=$base+12*3600;$s=12*3600;
 $stopInsert=$db->prepare('INSERT INTO stops VALUES(?,?,?,?)');
 foreach([['BE','Bus east',-31.95,115.85],['BW','Bus west',-31.9504,115.85],['TN','North platform',-31.952,115.85],['TS','South platform',-31.9524,115.85],['FE','East wharf',-31.954,115.85],['FW','West wharf',-31.9541,115.85],['FAR','Outside radius',-31.955,115.85],['ORIGIN','Origin',-31.97,115.86],['E','East destination',-31.98,115.88],['W','West destination',-31.98,115.82]] as $row)$stopInsert->execute($row);
 $add=function(string $id,string $route,string $stop,string $end,string $head,int $offset=0,int $pickup=0)use($db,$s){
  $db->prepare('INSERT INTO trips VALUES(?,?,?,?,?,?,?)')->execute([$id,$route,'daily',$head,$id,$s-300+$offset,$s+900+$offset]);
  $q=$db->prepare('INSERT INTO stop_times VALUES(?,?,?,?,?,?,?)');foreach([['ORIGIN',$s-300+$offset],[$stop,$s+120+$offset],[$end,$s+900+$offset]] as $i=>$row)$q->execute([$id,$row[0],($i+1)*10,$row[1],$row[1],$i===1?$pickup:0,0]);
  $q=$db->prepare('INSERT INTO shapes VALUES(?,?,?,?)');foreach([[-31.97,115.86],[-31.96,115.855],[-31.95,115.85],[-31.98,$end==='E'?115.88:115.82]] as $i=>$p)$q->execute([$id,$i,$p[0],$p[1]]);
 };
 foreach([['bus-east','B','BE','E','East'],['bus-west','B','BW','W','West'],['train-north','T','TN','E','North'],['train-south','T','TS','W','South'],['ferry-east','F','FE','E','East'],['ferry-west','F','FW','W','West']] as $r){$add(...$r);$add($r[0].'-later',$r[1],$r[2],$r[3],$r[4],600);}
 $add('bus-other-operator','B2','BE','E','East',900);$add('no-pickup','B','BE','E','East',-100,1);$add('out-of-radius','B','FAR','W','Outside',0);
 $transit=new Transit();$p=['lat'=>-31.95,'lon'=>115.85];$data=$transit->nearbyBus($p,$now);
 check($data['radius']===500,'search radius is 500 metres');check(count($data['services'])===6,'one next service per route destination, including both directions of all three modes');
 $ids=array_column($data['services'],'tripId');foreach(['bus-east','bus-west','train-north','train-south','ferry-east','ferry-west'] as $id)check(in_array($id,$ids,true),'next direction is included: '.$id);
 check(!in_array('bus-other-operator',$ids,true),'duplicate public line and destination across operators are grouped');
 check(!in_array('no-pickup',$ids,true)&&!in_array('out-of-radius',$ids,true),'no-pickup and outside-radius services are excluded');
 check(count(array_filter($data['services'],static fn($d)=>$d['mode']==='ferry'&&$d['stop']['distance']>400))===2,'ferry wharves beyond 100 metres and within 500 metres are included');
 $opposite=array_values(array_filter($data['services'],static fn($d)=>$d['tripId']==='train-south'))[0];
 $selected=$transit->nearbyBus($p,$now,'',$opposite['key']);
 check($selected['departure']['tripId']==='train-south'&&$selected['vehicle']['tripId']==='train-south'&&$selected['stop']['id']==='TS','choosing the opposite train direction changes its stop and highlighted vehicle');
 check($selected['geometry'][0]===[-31.97,115.86]&&end($selected['geometry'])===[-31.98,115.82],'selected direction supplies its full verified trip geometry');
 $fresh=new Transit();$far=$fresh->nearbyBus(['lat'=>-32.1,'lon'=>116.2],$now);
 check($far['services']===[]&&$far['stop']===null,'no nearby stop gives an empty response');
 check(count($transit->nearbyBus($p,$now,'','made-up-key')['services'])===6,'an unknown direction key cannot request an unrelated service');
 echo "\n$checks nearby service/direction checks passed. Synthetic timetable only.\n";
}finally{unset($db,$transit,$fresh);foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $e){if($e->isDir())rmdir($e->getPathname());else unlink($e->getPathname());}rmdir($tmp);}
