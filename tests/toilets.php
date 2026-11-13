<?php
declare(strict_types=1);
$project=dirname(__DIR__);$tmp=sys_get_temp_dir().'/nextstop-toilets-'.bin2hex(random_bytes(6));
mkdir($tmp.'/src',0700,true);mkdir($tmp.'/var',0700,true);
copy($project.'/bootstrap.php',$tmp.'/bootstrap.php');
foreach (['Toilets','Payphones'] as $class)copy($project.'/src/'.$class.'.php',$tmp.'/src/'.$class.'.php');
require $tmp.'/bootstrap.php';define('TOILETS_TESTING',true);
use App\Toilets;
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo "PASS: $label\n";}
function rejects(callable $f,string $label):void{try{$f();}catch(Throwable){check(true,$label);return;}check(false,$label);}
$raw=['ID'=>123,'T'=>'<b>Test toilet</b>','LA'=>-31.95,'LO'=>115.86,'A1'=>'1 Test St','L'=>'Perth','FM'=>16+1024+32768+1099511627776+2147483648,'O'=>'OPEN: Mon-Fri 8am-6pm','C'=>false];
try{
 $n=Toilets::normalize($raw);check($n['name']==='Test toilet'&&$n['hours']==='Mon-Fri 8am-6pm','normalizer strips markup and preserves source schedule without claiming open now');
 check(in_array('Changing Places',$n['amenities'],true)&&in_array('Payment required',$n['amenities'],true),'64-bit amenities retain adult change and fees above the JavaScript bitwise range');
 check(in_array('Accessible toilets',$n['amenities'],true)&&in_array('Key required',$n['amenities'],true),'access and key requirements are included');
 check(Toilets::normalize(array_merge($raw,['O'=>'']))['hours']==='Hours not supplied','unknown hours never become 24-hour access');
 check(Toilets::normalize(array_merge($raw,['C'=>true]))['listedClosed'],'source closure flag is retained');
 check(Toilets::normalize(array_merge($raw,['LA'=>200]))===null,'invalid coordinates are rejected');
 $calls=0;$params=[];
 $fetch=static function($kind,$arg)use(&$calls,&$params,$raw){$calls++;$params=$arg;return ['Ct'=>1,'Facilitys'=>[$raw],'Msg'=>null];};
 $bbox=[115.85,-31.96,115.87,-31.94];$d=Toilets::inBounds($bbox,$fetch);
 check(count($d['toilets'])===1&&!$d['stale']&&$calls===1,'live provider result is returned and saved with fetch time');
 check($params['openNow']==='false'&&$params['enforcePrefs']==='false','source query includes closed and restricted facilities rather than hiding their status');
 check(Toilets::inBounds($bbox,$fetch)['updatedAt']===$d['updatedAt']&&$calls===1,'repeat requests reuse the two-minute cache');
 check(Toilets::inBounds([120,-31.96,121,-31.94],$fetch)['toilets']===[]&&$calls===1,'outside coverage does not call the provider');
 $files=glob(APP_VAR.'/toilets/area-*.php');$cache=$files[0];$guard="<?php http_response_code(404); exit; ?>\n";
 $record=json_decode(substr(file_get_contents($cache),strlen($guard)),true);$record['fetchedAt']=time()-121;file_put_contents($cache,$guard.json_encode($record));
 $failed=0;$fail=static function()use(&$failed){$failed++;throw new RuntimeException('fixture outage');};
 $stale=Toilets::inBounds($bbox,$fail);check($stale['stale']&&count($stale['toilets'])===1,'provider outage retains saved data and labels it stale');
 Toilets::inBounds($bbox,$fail);check($failed===1,'failed provider requests use a retry backoff');
 foreach(glob(APP_VAR.'/toilets/*-status.php') as $status)unlink($status);
 $changed=static fn()=>['Ct'=>1,'Facilitys'=>[array_merge($raw,['O'=>'CLOSED: 9am-4pm','C'=>true])],'Msg'=>null];
 $updated=Toilets::inBounds($bbox,$changed);
 check(!$updated['stale']&&$updated['toilets'][0]['hours']==='9am-4pm'&&$updated['toilets'][0]['listedClosed'],'expired cache refreshes changed hours and closure state from the provider');
 check(str_starts_with(file_get_contents($cache),$guard)&&((fileperms($cache)&0777)===0600),'public-data cache is PHP guarded and owner-only');
 $detailCalls=0;$details=static function($kind,$id)use(&$detailCalls,$raw){$detailCalls++;return ['Info'=>$raw,'Extras'=>['AdNt'=>'By entrance','OhNt'=>'Public holidays vary','AceNt'=>'Ask staff for key','PT'=>'Test Council','PP2'=>'08 1234 5678']];};
 $detail=Toilets::detail('123',$details);check(count($detail['toilet']['notes'])===3&&$detail['toilet']['operator']==='Test Council','detail API adds published location, hours, access notes and operator');
 Toilets::detail('123',$details);check($detailCalls===1,'detail requests use the same short cache');
 rejects(static fn()=>Toilets::detail('../etc/passwd',$details),'facility input cannot select arbitrary URLs or paths');
 rejects(static fn()=>Toilets::detail('456',$details),'a mismatched provider facility ID is rejected');
 rejects(static fn()=>Toilets::inBounds([116,-32,115,-31],$fetch),'reversed bounding boxes are rejected');
 $link=APP_VAR.'/toilets/facility-789.php';file_put_contents($tmp.'/sentinel','unchanged');symlink($tmp.'/sentinel',$link);
 rejects(static fn()=>Toilets::detail('789',static fn()=>['Info'=>array_merge($raw,['ID'=>789]),'Extras'=>[]]),'cache symlink destinations are refused');
 check(file_get_contents($tmp.'/sentinel')==='unchanged','unsafe cache destination leaves outside files unchanged');
 echo "\n$checks toilet API/cache checks passed. Disclosed fixtures; no provider calls.\n";
}finally{
 $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($entries as $e){if($e->isDir()&&!$e->isLink())rmdir($e->getPathname());else unlink($e->getPathname());}rmdir($tmp);
}
