<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\PoiCatalog;
$checks=0;function pcCheck(bool $v,string $label):void{global $checks;if(!$v)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label.PHP_EOL;}
function pcReject(array $value,string $label):void{$bad=false;try{PoiCatalog::validate($value);}catch(InvalidArgumentException){$bad=true;}pcCheck($bad,$label);}
$base=PoiCatalog::load('/tmp/nonexistent-poi-override');$places=$base['places'];
pcCheck(count($places)===100&&count(array_unique(array_column($places,'id')))===100,'bundled catalogue contains exactly one hundred distinct sourced places');
pcCheck($base['ranking']['method']==='editorial'&&$base['ranking']['visitorCounts']===null,'curation is never presented as measured visitor popularity');
pcCheck($base['updatePolicy']['mode']==='manual'&&!$base['updatePolicy']['automaticRefresh'],'catalogue update policy stays owner-controlled');
pcCheck(count(array_filter($places,fn($p)=>empty($p['sources'])))===0,'every place retains source provenance');
pcCheck(count(array_filter($places,fn($p)=>$p['whatsOn']['events']!==[]))===0,'no current events are invented in the initial catalogue');
pcCheck(count(array_filter($places,fn($p)=>isset($p['openingHours']['text'])&&!isset($p['openingHours']['asOf'])))===0,'populated opening hours retain their actual source date');
pcCheck(count(array_filter($places,fn($p)=>!array_key_exists('openingHours',$p)||!array_key_exists('contact',$p)||!array_key_exists('whatsOn',$p)||!array_key_exists('accessibility',$p)))===0,'hours, contact, events and accessibility have reserved structured fields');
$alias=PoiCatalog::search('Optus Stadium',$base);pcCheck(count($alias)>0&&$alias[0]['name']==='Perth Stadium','common aliases resolve the curated place');
$mint=PoiCatalog::search('Perth Mint',$base)[0];pcCheck($mint['requiresEntrance']&&$mint['poiId']==='wikidata-q917524','an area point requests an entrance instead of inventing a pedestrian access');
$oval=PoiCatalog::search('Fremantle Oval',$base)[0];pcCheck(!$oval['requiresEntrance']&&abs($oval['lon']-115.7499259)<1e-7,'the existing reviewed Parry Street access is reused for Fremantle Oval');
$local=App\Geocoder::search('Optus Stadium');pcCheck($local['localCatalog']&&$local['moreAddresses']&&$local['results'][0]['poiId']=== $alias[0]['poiId'],'address search uses the local catalogue without a Photon request and preserves the address-search option');
$fixture=$base;$fixture['places'][0]['display']['enabled']=false;pcCheck(PoiCatalog::search('Kings Park and Botanic Garden',$fixture)===[],'disabled places are excluded from local autocomplete');
$bad=$base;$bad['places'][]=$bad['places'][0];pcReject($bad,'duplicate POI IDs cannot be uploaded');
$bad=$base;$bad['places'][0]['lat']=-90;pcReject($bad,'out-of-region coordinates cannot be uploaded');
$bad=$base;$bad['places'][0]['navigation']=['lat'=>-31.95,'lon'=>0];pcReject($bad,'invalid navigation coordinates are rejected');
$bad=$base;$bad['places'][0]['aliases']='wrong';pcReject($bad,'invalid alias data is rejected');
$bad=$base;$bad['places'][0]['sources']='wrong';pcReject($bad,'malformed source metadata cannot break a published popup');
$bad=$base;$bad['places'][0]['display']['enabled']='false';pcReject($bad,'display toggles must be actual JSON booleans');
$bad=$base;$bad['places'][0]['contact']['website']='javascript:alert(1)';pcReject($bad,'executable website URLs cannot enter the catalogue');
$bad=$base;$bad['places'][0]['defaultStayMinutes']=0;pcReject($bad,'unusable default stay durations are rejected');
$bad=$base;$bad['places'][0]['whatsOn']['events']=[['title'=>'Bad clock','start'=>'2026-02-30T12:00:00+08:00','end'=>'2026-03-01T12:00:00+08:00']];pcReject($bad,'event date normalisation cannot create a false current event');
$folder=sys_get_temp_dir().'/nextstop-pois-'.bin2hex(random_bytes(6));mkdir($folder,0700);$override=$folder.'/perth-pois.json';
try{
 $custom=$base;$custom['revision']='owner-test';$custom['places'][0]['name']='Owner place';file_put_contents($override,json_encode($custom));
 $loaded=PoiCatalog::load($override);pcCheck($loaded['catalogOrigin']==='custom'&&$loaded['places'][0]['name']==='Owner place','a valid owner catalogue takes precedence over the bundled seed');
 file_put_contents($override,'{broken');pcCheck(PoiCatalog::load($override)['catalogOrigin']==='bundled','a damaged override falls back to the bundled catalogue');
 unlink($override);symlink(APP_ROOT.'/assets/perth-pois.json',$override);pcCheck(PoiCatalog::load($override)['catalogOrigin']==='bundled','a symlink is never followed as an uploaded catalogue');
}finally{if(file_exists($override)||is_link($override))unlink($override);rmdir($folder);}
echo $checks.' POI catalogue checks passed. Local source snapshots only; no live hours/events claim.'.PHP_EOL;
