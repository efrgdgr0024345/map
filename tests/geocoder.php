<?php
declare(strict_types=1);
namespace App {
    final class GeocodeFixture { public static string $body=''; public static int $calls=0; public static string $url=''; }
    function curl_init(string $url): object { GeocodeFixture::$calls++; GeocodeFixture::$url=$url; return new \stdClass(); }
    function curl_setopt_array(object $handle,array $options): bool { return true; }
    function curl_exec(object $handle): string { return GeocodeFixture::$body; }
    function curl_getinfo(object $handle,int $option): int { return 200; }
}
namespace {
    $project=dirname(__DIR__);
    $isolated=sys_get_temp_dir().'/nextstop-geocoder-test-'.bin2hex(random_bytes(6));
    mkdir($isolated,0700,true);
    define('APP_ROOT',$project); define('APP_VAR',$isolated);
    require $project.'/src/PlaceAccess.php'; require $project.'/src/Geocoder.php';
    use App\Geocoder;
    use App\PlaceAccess;
    use App\GeocodeFixture;
    $checks=0;
    function gcCheck(bool $ok,string $label): void { global $checks; if (!$ok) { throw new \RuntimeException('FAIL: '.$label); } $checks++;echo 'PASS: '.$label.PHP_EOL; }
    function gcFeature(array $p=[],array $coordinates=[115.7512715,-32.0561919]): array {
        return ['type'=>'Feature','properties'=>array_merge(['osm_type'=>'W','osm_id'=>29283043,'osm_key'=>'leisure','osm_value'=>'pitch','name'=>'Fremantle Oval','street'=>'Parry Street','city'=>'Fremantle','state'=>'Western Australia'],$p),'geometry'=>['type'=>'Point','coordinates'=>$coordinates]];
    }
    function gcParse(array $features,?string $catalog=null): array { return Geocoder::parseResponse(json_encode(['features'=>$features],JSON_THROW_ON_ERROR),$catalog); }
    function gcCatalog(array $data): string { global $isolated; $path=$isolated.'/catalog-'.bin2hex(random_bytes(4)).'.json';file_put_contents($path,json_encode($data,JSON_THROW_ON_ERROR));return $path; }
    function gcReject(callable $fn,string $label): void { $failed=false;try{$fn();}catch(\RuntimeException){$failed=true;}gcCheck($failed,$label); }
    try {
        $catalog=json_decode(file_get_contents($project.'/assets/place-access.json'),true,32,JSON_THROW_ON_ERROR);
        $result=gcParse([gcFeature()]);$place=$result['results'][0];
        gcCheck($place['lat']===-32.0562977 && $place['lon']===115.7499259,'the exact oval pitch resolves to its reviewed Parry Street arrival point');
        gcCheck(str_starts_with($place['name'],'Fremantle Oval (Parry Street access)'),'the user sees an explicit access label rather than an undisclosed moved destination');
        gcCheck(str_contains($place['name'],'Parry Street, Fremantle, Western Australia'),'the access result preserves its address and locality');
        gcCheck($place['venuePoint']===['lat'=>-32.0561919,'lon'=>115.7512715],'the original venue centre stays available separately from the route endpoint');
        gcCheck($place['osmType']==='W' && $place['osmId']==='29283043' && $place['osmKey']==='leisure' && $place['osmValue']==='pitch','Photon feature identity and tags are preserved for downstream interpretation');
        gcCheck($place['accessSource']['osmType']==='N' && $place['accessSource']['osmId']==='2343952485' && $place['accessSource']['sourceUrl']==='https://www.openstreetmap.org/node/2343952485','reviewed access carries the exact mapped node and its public source');
        gcCheck($place['accessSource']['reviewedAt']==='2026-10-25','review date remains visible in the access metadata');
        gcCheck(str_contains($result['attribution'],'OpenStreetMap'),'address suggestions retain their upstream attribution');
        $ordinary=gcParse([gcFeature(['osm_type'=>'N','osm_id'=>123456,'osm_key'=>'place','osm_value'=>'house','name'=>'Example place','housenumber'=>'10','street'=>'Example Road'])])['results'][0];
        gcCheck($ordinary['lat']===-32.0561919 && $ordinary['lon']===115.7512715 && !isset($ordinary['accessSource']),'ordinary addresses retain their selected location');
        gcCheck($ordinary['name']==='Example place, 10 Example Road, Fremantle, Western Australia','ordinary address formatting is unchanged');
        foreach (['wrong object ID'=>['osm_id'=>29283044],'wrong object type'=>['osm_type'=>'N'],'wrong OSM key'=>['osm_key'=>'landuse'],'wrong OSM value'=>['osm_value'=>'stadium']] as $label=>$change) {
            $p=gcParse([gcFeature($change)])['results'][0];
            gcCheck(!isset($p['accessSource']) && $p['lat']===-32.0561919 && $p['lon']===115.7512715,$label.' cannot trigger a reviewed access override');
        }
        $board=gcParse([gcFeature(['osm_type'=>'N','osm_id'=>2891147737,'osm_key'=>'information','osm_value'=>'board','street'=>'Fothergill Street'],[115.7521645,-32.0554896])])['results'][0];
        gcCheck(!isset($board['accessSource']) && $board['lat']===-32.0554896,'a similarly named information board is not relabelled as an entrance');
        $nameChanged=gcParse([gcFeature(['name'=>'Venue translated by provider'])])['results'][0];
        gcCheck(isset($nameChanged['accessSource']),'matching uses reviewed feature identity rather than name text or proximity');
        $stringId=gcParse([gcFeature(['osm_id'=>'29283043'])])['results'][0];
        gcCheck($stringId['lat']===$place['lat'],'integer and decimal-string provider IDs identify the same reviewed object');
        $missing=gcParse([gcFeature()],$isolated.'/missing.json')['results'][0];
        gcCheck(!isset($missing['accessSource']) && $missing['lat']===-32.0561919,'a missing optional catalog leaves the original result intact');
        $broken=$isolated.'/broken.json';file_put_contents($broken,'{broken');
        gcCheck(!isset(gcParse([gcFeature()],$broken)['results'][0]['accessSource']),'a malformed catalog cannot invent or move an endpoint');
        $wrongVersion=$catalog;$wrongVersion['version']=2;
        gcCheck(!isset(gcParse([gcFeature()],gcCatalog($wrongVersion))['results'][0]['accessSource']),'an unsupported access catalog version is ignored safely');
        foreach (['outside regional coordinates'=>['lat'=>0], 'invalid longitude'=>['lon'=>'not-a-coordinate'], 'wrong source node'=>['sourceUrl'=>'https://www.openstreetmap.org/node/999'], 'untrusted source host'=>['sourceUrl'=>'https://example.test/node/2343952485'], 'credential-bearing source'=>['sourceUrl'=>'https://user@www.openstreetmap.org/node/2343952485'], 'missing access name'=>['name'=>'']] as $label=>$change) {
            $bad=$catalog;$bad['places'][0]['access']=array_merge($bad['places'][0]['access'],$change);
            gcCheck(!isset(gcParse([gcFeature()],gcCatalog($bad))['results'][0]['accessSource']),$label.' is rejected without changing the destination');
        }
        $badDate=$catalog;$badDate['places'][0]['reviewedAt']='2026-02-30';
        gcCheck(!isset(gcParse([gcFeature()],gcCatalog($badDate))['results'][0]['accessSource']),'an impossible review date cannot validate an access record');
        $far=gcParse([gcFeature([],[115.9,-31.95])])['results'][0];
        gcCheck(!isset($far['accessSource']),'an inconsistent feature location cannot move a result across the city');
        $linked=$isolated.'/linked.json';symlink($project.'/assets/place-access.json',$linked);
        gcCheck(!isset(gcParse([gcFeature()],$linked)['results'][0]['accessSource']),'symlinked optional catalogs are not followed');
        gcCheck(gcParse([gcFeature([],[-10,20]),gcFeature([],[115.75,-32.05,10]),'invalid'])['results']===[],'out-of-region, malformed and non-point result coordinates are excluded');
        gcCheck(gcParse([])['results']===[],'an empty provider result remains an empty suggestion list');
        gcReject(static fn()=>Geocoder::parseResponse('{broken'),'malformed provider JSON fails clearly');
        gcReject(static fn()=>Geocoder::parseResponse('{"features":"wrong"}'),'a malformed provider feature collection fails clearly');
        $query='Fixture Fremantle Oval';
        $legacy=$isolated.'/geocode-'.hash('sha256',strtolower($query)).'.php';
        file_put_contents($legacy,"<?php http_response_code(404); exit; ?>\n".json_encode(['results'=>[['name'=>'OLD CENTROID','lat'=>-32.0561919,'lon'=>115.7512715]]]));
        GeocodeFixture::$body=json_encode(['features'=>[gcFeature()]],JSON_THROW_ON_ERROR);
        $cached=Geocoder::search($query);
        gcCheck(GeocodeFixture::$calls===1 && $cached['results'][0]['lat']===-32.0562977,'a legacy centroid cache cannot mask the corrected access result');
        gcCheck(count(glob($isolated.'/geocode-v2-*.php'))===1,'the new result uses the versioned cache namespace');
        $again=Geocoder::search($query);
        gcCheck(GeocodeFixture::$calls===1 && $again===$cached,'repeated address search reuses the corrected cached result');
        gcCheck(str_starts_with(GeocodeFixture::$url,'https://photon.komoot.io/api/?') && str_contains(GeocodeFixture::$url,'q=Fixture+Fremantle+Oval'),'the provider remains the fixed encoded Photon search endpoint');
        gcCheck(file_get_contents($legacy)!==false,'cache invalidation leaves old private state untouched');
        echo PHP_EOL.$checks.' geocoder/access checks passed. Synthetic provider data and reviewed local catalog only; no network requests.'.PHP_EOL;
    } finally {
        foreach (glob($isolated.'/*') as $file) { unlink($file); }
        rmdir($isolated);
    }
}
