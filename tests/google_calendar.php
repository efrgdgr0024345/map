<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\GoogleCalendar;
$config=['clientId'=>'fixture.apps.googleusercontent.com','clientSecret'=>'fixture-secret-only','redirectUri'=>'https://example.test/bus/calendar.php'];
$checks=0;$calls=[];$inserted=[];$expires=false;$conflict=false;
function gcCheck(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label.PHP_EOL;}
function gcReject(callable $run,string $label):void{$rejected=false;try{$run();}catch(Throwable){$rejected=true;}gcCheck($rejected,$label);}
$eventStart=time()+7200;$start=date(DATE_ATOM,$eventStart);$end=date(DATE_ATOM,$eventStart+3600);
$transport=static function(string $method,string $url,array $headers,?string $body)use(&$calls,&$inserted,&$expires,&$conflict,$start,$end):array{
 $calls[]=[$method,$url,$headers,$body];
 if($url==='https://oauth2.googleapis.com/token')return ['status'=>200,'data'=>['access_token'=>'fixture-access','refresh_token'=>'fixture-refresh','expires_in'=>3600,'scope'=>GoogleCalendar::SCOPES]];
 if($url==='https://oauth2.googleapis.com/revoke')return ['status'=>200,'data'=>[]];
 if(str_contains($url,'calendars/primary/events'))return ['status'=>200,'data'=>['items'=>[['id'=>'A','summary'=>'Gym','location'=>'Fixture address','start'=>['dateTime'=>$start],'end'=>['dateTime'=>$end]],['id'=>'B','summary'=>'All day','start'=>['date'=>'2026-10-28'],'end'=>['date'=>'2026-10-29']],['id'=>'C','status'=>'cancelled']]]];
 if($url==='https://www.googleapis.com/calendar/v3/calendars')return ['status'=>200,'data'=>['id'=>'app-calendar']];
 if(str_contains($url,'calendars/app-calendar/events')){
  if($method==='GET'){$id=basename($url);return ['status'=>isset($inserted[$id])?200:404,'data'=>$inserted[$id]??[]];}
  $data=json_decode($body,true);$inserted[$data['id']]=$data;
  if($conflict){$conflict=false;return ['status'=>409,'data'=>[]];}
  return ['status'=>200,'data'=>['id'=>$data['id']]];
 }
 return ['status'=>500,'data'=>[]];
};
$google=new GoogleCalendar($config,$transport);$session=[];
gcCheck(!$google->connected($session),'fresh visitors have no implied Google connection');
$url=$google->begin($session);parse_str(parse_url($url,PHP_URL_QUERY),$params);
gcCheck(parse_url($url,PHP_URL_HOST)==='accounts.google.com'&&$params['scope']===GoogleCalendar::SCOPES,'connect requests only event reading and access to app-created calendars');
gcCheck($params['code_challenge_method']==='S256'&&strlen($params['state'])===64&&$params['code_challenge']===rtrim(strtr(base64_encode(hash('sha256',$session['oauth']['verifier'],true)),'+/','-_'),'='),'OAuth uses session-bound state and SHA256 PKCE');
$bad=$session;gcReject(function()use($google,&$bad){$google->callback($bad,'wrong','fixture-code');},'a mismatched OAuth state is rejected before token exchange');
gcCheck(count($calls)===0,'invalid callbacks never contact the token service');
$google->callback($session,$params['state'],'fixture-code');
gcCheck($google->connected($session)&&!isset($session['oauth']),'verified OAuth connects the session and consumes its pending state');
gcReject(function()use($google,&$session,$params){$google->callback($session,$params['state'],'fixture-code');},'the OAuth callback cannot be replayed');
$changed=new GoogleCalendar(array_merge($config,['clientSecret'=>'different-fixture-secret']),$transport);gcCheck(!$changed->connected($session),'changing owner OAuth credentials invalidates an old connection');
$count=count($calls);$events=$google->events($session,date('Y-m-d'));
gcCheck(count($events['events'])===2&&$events['events'][0]['title']==='Gym'&&$events['events'][1]['allDay'],'event listing preserves timed and all-day events and omits cancellations');
gcCheck(count($calls)===$count+1&&end($calls)[0]==='GET','reading events does not create calendars or travel events');
$before=count($calls);gcReject(function()use($google,&$session){$google->addTravel($session,[['title'=>'Invalid','start'=>'bad','end'=>'bad']]);},'malformed travel times are rejected');gcCheck(count($calls)===$before,'invalid travel requests make no calendar writes');
$block=['title'=>'Travel to Gym','start'=>$start,'end'=>$end,'details'=>'Bus 915 from the home stop'];
$added=$google->addTravel($session,[$block]);
gcCheck(count($added['added'])===1&&$session['google']['calendarId']==='app-calendar','confirmed travel creates and uses the app-owned Nextstop calendar');
$written=array_values($inserted)[0];gcCheck($written['reminders']['useDefault']===false&&array_column($written['reminders']['overrides'],'minutes')===[10,0],'travel reminders fire ten minutes before leaving and at departure');
gcCheck($written['start']['timeZone']==='Australia/Perth'&&$written['summary']==='Travel to Gym','calendar writes retain the reviewed title and explicit Perth timezone');
gcCheck(count(array_filter($calls,fn($c)=>$c[0]==='POST'&&str_contains($c[1],'calendars/primary')))===0,'existing primary appointments are never edited');
$before=count($calls);$google->addTravel($session,[$block]);gcCheck(count($calls)===$before,'retrying an already-added travel block does not duplicate it');
$conflict=true;$block['title']='Travel home';$retried=$google->addTravel($session,[$block]);gcCheck(count($retried['added'])===1,'a lost insert response can recover the deterministic event ID');
$session['google']['expires']=0;$google->events($session,date('Y-m-d'));gcCheck($session['google']['expires']>time(),'an expired access token refreshes on the server');
$google->disconnect($session);gcCheck(!$google->connected($session)&&!isset($session['calendarEvents'],$session['addedTravel']),'disconnect removes this visitor\'s tokens and event state');gcCheck(end($calls)[1]==='https://oauth2.googleapis.com/revoke','disconnect also requests Google token revocation');
foreach(['http://example.test/bus/calendar.php','https://user@example.test/bus/calendar.php','https://example.test/bus/calendar.php?next=evil','https://example.test/other.php'] as $badUrl)gcReject(fn()=>new GoogleCalendar(array_merge($config,['redirectUri'=>$badUrl]),$transport),'unsafe callback configuration is rejected');
echo $checks.' calendar checks passed. Simulated Google transport only; live OAuth requires owner credentials.'.PHP_EOL;
