<?php
declare(strict_types=1);

// Namespace-local cURL substitutes exercise the production fixed-URL request and
// cache/privacy paths without contacting any routing server.
namespace App {
    function curl_init(string $url): object { return (object)['url'=>$url,'options'=>[]]; }
    function curl_setopt_array(object $handle,array $options): bool { $handle->options=$options; return true; }
    function curl_exec(object $handle): bool {
        \WalkingFixture::$calls++;
        \WalkingFixture::$last=$handle;
        if (\WalkingFixture::$failure) { return false; }
        $body=\WalkingFixture::$oversize ? str_repeat(' ',11*1024*1024) : (\WalkingFixture::$responseOverride ?? \WalkingFixture::responseForUrl($handle->url));
        return $handle->options[CURLOPT_WRITEFUNCTION]($handle,$body) === strlen($body);
    }
    function curl_getinfo(object $handle,int $option): int { return \WalkingFixture::$failure ? 503 : \WalkingFixture::$httpStatus; }
    function usleep(int $microseconds): void { /* Offline tests must not wait on provider pacing. */ }
}
namespace {
    require_once dirname(__DIR__).'/src/Geo.php';
    require_once dirname(__DIR__).'/src/WalkingRouteException.php';
    require_once dirname(__DIR__).'/src/WalkingRouter.php';
    use App\Geo;
    use App\WalkingRouter;
    use App\WalkingRouteException;

    final class WalkingFixture {
        public static int $calls=0;
        public static ?object $last=null;
        public static bool $failure=false;
        public static bool $oversize=false;
        public static int $httpStatus=200;
        public static ?string $responseOverride=null;
        public static function response(array $a,array $b): array {
            $corner=['lat'=>$a['lat'],'lon'=>$b['lon']];
            $length=Geo::distance($a,$corner)+Geo::distance($corner,$b);
            return [
                'code'=>'Ok',
                'routes'=>[
                    ['distance'=>$length,'duration'=>$length/1.1,
                     'geometry'=>['type'=>'LineString','coordinates'=>[
                         [$a['lon'],$a['lat']],[$corner['lon'],$corner['lat']],[$b['lon'],$b['lat']],
                     ]]],
                ],
                'waypoints'=>[
                    ['location'=>[$a['lon'],$a['lat']],'distance'=>0],
                    ['location'=>[$b['lon'],$b['lat']],'distance'=>0],
                ],
            ];
        }
        public static function responseForUrl(string $url): string {
            $parts=explode('/',parse_url($url,PHP_URL_PATH));$pair=explode(';',end($parts));
            $a=explode(',',$pair[0]);$b=explode(',',$pair[1]);
            return json_encode(self::response(['lat'=>(float)$a[1],'lon'=>(float)$a[0]],['lat'=>(float)$b[1],'lon'=>(float)$b[0]]),JSON_THROW_ON_ERROR);
        }
    }
    $checks=0;
    function walkingCheck(bool $condition,string $message): void {
        global $checks;
        if(!$condition){throw new RuntimeException('FAIL: '.$message);}
        $checks++;echo 'PASS: '.$message.PHP_EOL;
    }
    function walkingRejected(callable $operation,string $message): void {
        $failed=false;try{$operation();}catch(RuntimeException){$failed=true;}
        walkingCheck($failed,$message);
    }
    function walkingMemoReset(): void { (new ReflectionProperty(WalkingRouter::class,'memo'))->setValue(null,[]); }
    function walkingRemove(string $path): void {
        if(is_link($path)||!is_dir($path)){@unlink($path);return;}
        foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $entry){walkingRemove($entry->getPathname());}
        rmdir($path);
    }
    $isolated=sys_get_temp_dir().'/perthmove-walking-test-'.bin2hex(random_bytes(6));
    mkdir($isolated,0700,true);define('APP_VAR',$isolated.'/var');mkdir(APP_VAR,0700);
    $a=['lat'=>-31.95,'lon'=>115.85];$b=['lat'=>-31.949,'lon'=>115.851];
    $response=WalkingFixture::response($a,$b);
    $encode=static fn(array $value):string=>json_encode($value,JSON_THROW_ON_ERROR);
    try {
        $route=WalkingRouter::parseResponse($encode($response),$a,$b);
        walkingCheck($route['geometry']===[[-31.95,115.85],[-31.95,115.851],[-31.949,115.851]],'pedestrian GeoJSON is converted to an ordered latitude/longitude footpath with its street corner intact');
        walkingCheck($route['routing']==='street' && $route['approximate']===false && $route['routingSource']===WalkingRouter::SOURCE,'only a successfully validated pedestrian route is labelled as a mapped street route');
        walkingCheck($route['duration']>0 && is_int($route['duration']) && $route['distance']>Geo::distance($a,$b),'route duration and distance describe the routed detour instead of the straight chord');
        $quick=$response;$quick['routes'][0]['duration']=1;
        $conservative=WalkingRouter::parseResponse($encode($quick),$a,$b);
        walkingCheck($conservative['duration']>=ceil($conservative['distance']/1.25),'boarding feasibility uses a conservative walking-time floor instead of an implausibly fast provider time');
        $door=['lat'=>$a['lat']-10/111000,'lon'=>$a['lon']];$snap=$response;$snap['waypoints'][0]['distance']=10;
        $access=WalkingRouter::parseResponse($encode($snap),$door,$b);
        walkingCheck(count($access['accessConnectors'])===1 && $access['accessConnectors'][0]['approximate']===true && $access['accessConnectors'][0]['routing']==='unrouted_access','a short door-to-network connection is explicitly marked unverified access');
        walkingCheck($access['geometry'][0]===[$a['lat'],$a['lon']] && $access['duration']>$route['duration'] && $access['distance']>$route['distance'],'access connectors do not masquerade as mapped footpaths and their time is included');
        $far=['lat'=>$a['lat']-60/111000,'lon'=>$a['lon']];
        walkingRejected(static fn()=>WalkingRouter::parseResponse($encode($response),$far,$b),'endpoint snapping beyond fifty metres is rejected even if the server understates its snap distance');
        $bad=$response;$bad['waypoints'][0]['distance']=80;
        walkingRejected(static fn()=>WalkingRouter::parseResponse($encode($bad),$a,$b),'reported excessive endpoint snap distance is rejected');
        $bad=$response;$bad['routes'][0]['geometry']['coordinates'][0]=[115.852,-31.95];
        walkingRejected(static fn()=>WalkingRouter::parseResponse($encode($bad),$a,$b),'disconnected geometry cannot be accepted as a route between the declared access points');
        walkingRejected(static fn()=>WalkingRouter::parseResponse('{"code":"NoRoute"}',$a,$b),'an unroutable pedestrian response is not replaced with a straight line');
        walkingRejected(static fn()=>WalkingRouter::parseResponse('<html>service unavailable</html>',$a,$b),'HTML and malformed provider JSON fail clearly');
        $bad=$response;$bad['routes']=[];
        walkingRejected(static fn()=>WalkingRouter::parseResponse($encode($bad),$a,$b),'successful status without an actual route is rejected');
        $bad=$response;$bad['routes'][0]['geometry']['type']='Point';
        walkingRejected(static fn()=>WalkingRouter::parseResponse($encode($bad),$a,$b),'non-line route geometry is rejected');
        $bad=$response;$bad['waypoints']=['one'=>$response['waypoints'][0],'two'=>$response['waypoints'][1]];
        walkingRejected(static fn()=>WalkingRouter::parseResponse($encode($bad),$a,$b),'object-shaped access point collections fail without undefined-array warnings');
        $bad=$response;$bad['waypoints'][0]='invalid';
        walkingRejected(static fn()=>WalkingRouter::parseResponse($encode($bad),$a,$b),'scalar access points fail with a clear routing exception');
        $bad=$response;$bad['routes'][0]['geometry']='invalid';
        walkingRejected(static fn()=>WalkingRouter::parseResponse($encode($bad),$a,$b),'scalar route geometry fails with a clear routing exception');
        $bad=$response;$bad['routes'][0]['geometry']['coordinates'][1]=[0,0];
        walkingRejected(static fn()=>WalkingRouter::parseResponse($encode($bad),$a,$b),'invalid or out-of-region route coordinates are rejected');
        $bad=$response;$bad['routes'][0]['distance']=0;$bad['routes'][0]['duration']=0;
        walkingRejected(static fn()=>WalkingRouter::parseResponse($encode($bad),$a,$b),'zero-length or zero-duration provider paths are rejected');
        $bad=$response;$bad['routes'][0]['distance']=-1;
        walkingRejected(static fn()=>WalkingRouter::parseResponse($encode($bad),$a,$b),'negative provider metrics are rejected');
        $bad=$response;$bad['routes'][0]['distance']=1;
        walkingRejected(static fn()=>WalkingRouter::parseResponse($encode($bad),$a,$b),'reported distance inconsistent with the actual path geometry is rejected');

        $private=WalkingRouter::route($a,$b);
        walkingCheck(WalkingFixture::$calls===1 && glob(APP_VAR.'/walking/route-*')===[],'personal GPS and address walking paths leave no route cache or coordinate-derived lock on disk');
        WalkingRouter::route($a,$b);
        walkingCheck(WalkingFixture::$calls===1,'repeated private endpoint pairs are memoized within the same request');
        $clock=file_get_contents(APP_VAR.'/walking/provider-clock.php');
        walkingCheck(!str_contains($clock,'115.85') && !str_contains($clock,'31.95'),'provider pacing persists a timestamp without personal endpoint coordinates');
        $options=WalkingFixture::$last->options;
        walkingCheck(str_starts_with(WalkingFixture::$last->url,WalkingRouter::ENDPOINT) && str_contains(WalkingRouter::ENDPOINT,'/routed-foot/') && $options[CURLOPT_FOLLOWLOCATION]===false,'provider requests stay on the fixed dedicated foot backend and do not follow redirects');
        walkingCheck($options[CURLOPT_SSL_VERIFYPEER]===true && $options[CURLOPT_SSL_VERIFYHOST]===2 && $options[CURLOPT_CONNECTTIMEOUT]===3 && $options[CURLOPT_TIMEOUT]===6,'provider requests retain TLS verification and the bounded planning timeout');

        walkingMemoReset();
        $publicA=$a+['id'=>'100'];$publicB=$b+['id'=>'200'];
        WalkingRouter::route($publicA,$publicB);
        $caches=glob(APP_VAR.'/walking/route-*.php');
        walkingCheck(WalkingFixture::$calls===2 && count($caches)===1 && str_starts_with(file_get_contents($caches[0]),'<?php http_response_code(404); exit; ?>'),'public GTFS stop-to-stop paths alone receive a guarded persistent cache');
        walkingMemoReset();WalkingRouter::route($publicA,$publicB);
        walkingCheck(WalkingFixture::$calls===2,'a later request can reuse the persistent public-stop route without a provider call');
        $payload=json_decode(explode("\n",file_get_contents($caches[0]),2)[1],true);$payload['createdAt']=time()-31*86400;
        file_put_contents($caches[0],"<?php http_response_code(404); exit; ?>\n".$encode($payload));
        walkingMemoReset();WalkingRouter::route($publicA,$publicB);
        walkingCheck(WalkingFixture::$calls===3,'expired public-stop route caches are refreshed after the thirty-day TTL');
        walkingMemoReset();WalkingFixture::$failure=true;
        $calls=WalkingFixture::$calls;
        walkingRejected(static fn()=>WalkingRouter::route($b,$a),'provider failures report unavailable pedestrian routing instead of fabricating a street path');
        walkingCheck(WalkingFixture::$calls===$calls+1 && count(glob(APP_VAR.'/walking/route-*.php'))===1,'failed personal routing is neither retried automatically nor persisted');
        WalkingFixture::$failure=false;WalkingFixture::$oversize=true;
        walkingRejected(static fn()=>WalkingRouter::route($b,$a),'provider responses exceeding the ten-megabyte limit are rejected');
        WalkingFixture::$oversize=false;
        walkingMemoReset();
        WalkingFixture::$httpStatus=400;
        WalkingFixture::$responseOverride=$encode(['code'=>'NoSegment','message'=>'Could not find a matching segment for coordinate 1 SECRET_PROVIDER_URL']);
        $classified=null;
        try { WalkingRouter::route($b,$a); } catch (WalkingRouteException $error) { $classified=$error; }
        walkingCheck($classified!==null && $classified->reason==='no_segment' && $classified->endpoint===1,'HTTP 400 NoSegment identifies an unmapped destination instead of reporting a provider outage');
        walkingCheck(str_contains($classified->getMessage(),'public entrance') && !str_contains($classified->getMessage(),'SECRET_PROVIDER_URL'),'unmapped destinations receive a trusted entrance instruction without raw provider text');
        parse_str(parse_url(WalkingFixture::$last->url,PHP_URL_QUERY),$parameters);
        walkingCheck($parameters['radiuses']==='50;50','endpoint classification never widens the mapped pedestrian safety radius');
        walkingCheck(count(glob(APP_VAR.'/walking/route-*.php'))===1,'unmapped private endpoints do not create a persistent route or fictional geometry');
        WalkingFixture::$responseOverride=$encode(['code'=>'NoSegment','message'=>'Could not find a matching segment for coordinate 0']);
        $classified=null;
        try { WalkingRouter::route($b,$a); } catch (WalkingRouteException $error) { $classified=$error; }
        walkingCheck($classified!==null && $classified->endpoint===0 && str_contains($classified->getMessage(),'starting point'),'an unmapped origin remains distinct from an unmapped destination');
        WalkingFixture::$responseOverride=$encode(['code'=>'NoRoute','message'=>'Provider internals must remain private']);
        $classified=null;
        try { WalkingRouter::route($b,$a); } catch (WalkingRouteException $error) { $classified=$error; }
        walkingCheck($classified!==null && $classified->reason==='no_route' && $classified->endpoint===null,'a disconnected pedestrian network remains distinct from endpoint snapping or a service outage');
        WalkingFixture::$responseOverride='<html>Bad gateway</html>';
        $generic=null;
        try { WalkingRouter::route($b,$a); } catch (RuntimeException $error) { $generic=$error; }
        walkingCheck($generic!==null && !($generic instanceof WalkingRouteException),'an unknown HTTP failure cannot masquerade as a diagnosed entrance problem');
        WalkingFixture::$httpStatus=200; WalkingFixture::$responseOverride=null;
        walkingRejected(static fn()=>WalkingRouter::route($a,$a),'coincident endpoints do not create an invented street route');
        echo PHP_EOL.$checks.' walking route checks passed. Provider responses are offline fixtures; public service availability is not established.'.PHP_EOL;
    } finally { walkingRemove($isolated); }
}
