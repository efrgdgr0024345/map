<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function point(string $value): array
{
    $parts=explode(',',$value);
    if (count($parts)!==2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) { throw new InvalidArgumentException('Choose a valid location.'); }
    $p=['lat'=>(float)$parts[0],'lon'=>(float)$parts[1]];
    if ($p['lat'] < -33.5 || $p['lat'] > -30.5 || $p['lon'] < 114.5 || $p['lon'] > 117.5) { throw new InvalidArgumentException('Choose a location in the Perth region.'); }
    return $p;
}

function matchesEtag(string $etag): bool
{
    foreach (explode(',',$_SERVER['HTTP_IF_NONE_MATCH'] ?? '') as $candidate) {
        if (trim($candidate)==='*' || preg_replace('/^W\//','',trim($candidate))===preg_replace('/^W\//','',$etag)) { return true; }
    }
    return false;
}

function acceptsGzip(): bool
{
    foreach (explode(',',$_SERVER['HTTP_ACCEPT_ENCODING'] ?? '') as $encoding) {
        $parts=explode(';',trim($encoding));
        if (strtolower(trim($parts[0]))!=='gzip') { continue; }
        $quality=1.0;
        foreach (array_slice($parts,1) as $parameter) {
            if (preg_match('/^\s*q\s*=\s*([\d.]+)/i',$parameter,$m)) { $quality=(float)$m[1]; }
        }
        return $quality>0;
    }
    return false;
}

try {
    foreach ($_GET as $value) {
        if (!is_string($value)) { throw new InvalidArgumentException('Invalid request parameters.'); }
    }
    $action=$_GET['action'] ?? 'status';
    if (!is_string($action)) { throw new InvalidArgumentException('Invalid action.'); }
    $service=is_file(APP_VAR.'/transit.sqlite') ? new App\Transit() : new App\Demo();
    switch ($action) {
        case 'status':
            $result=$service->status();
            $result['routeRevision']=$service instanceof App\Demo ? 'demo-network-2' : App\RouteCache::revision();
            break;
        case 'routes':
            if ($service instanceof App\Demo) {
                $result=$service->routes(); $etag='W/"'.$result['revision'].'"';
                header('Cache-Control: public, max-age=300, stale-while-revalidate=86400'); header('ETag: '.$etag);
                if (matchesEtag($etag)) { http_response_code(304); exit; }
                break;
            }
            $path=App\RouteCache::path();
            $metadata=json_decode((string)@file_get_contents(APP_VAR.'/routes.meta.json'),true) ?: [];
            $etag='W/"'.hash('sha256',($metadata['revision'] ?? '').':'.filesize($path).':'.filemtime($path)).'"';
            header('Cache-Control: public, max-age=300, stale-while-revalidate=86400');
            header('ETag: '.$etag); header('Vary: Accept-Encoding');
            if (matchesEtag($etag)) { http_response_code(304); exit; }
            if (is_file($path.'.gz') && acceptsGzip() && !ini_get('zlib.output_compression')) {
                header('Content-Encoding: gzip'); $path.='.gz';
            }
            readfile($path);
            exit;
        case 'vehicles':
            $bbox=explode(',',(string)($_GET['bbox'] ?? '115.65,-32.2,116.1,-31.7'));
            if (count($bbox)!==4 || count(array_filter($bbox,'is_numeric'))!==4) { throw new InvalidArgumentException('Invalid map bounds.'); }
            $bbox=array_map('floatval',$bbox);
            if ($bbox[0]>=$bbox[2] || $bbox[1]>=$bbox[3] || $bbox[0]<-180 || $bbox[2]>180 || $bbox[1]<-90 || $bbox[3]>90) { throw new InvalidArgumentException('Invalid map bounds.'); }
            $routes=array_values(array_filter(explode(',',substr((string)($_GET['routes'] ?? ''),0,2000))));
            $result=$service->vehicles($bbox,$routes,substr((string)($_GET['journey'] ?? ''),0,200),($_GET['lines'] ?? '')!=='0');
            break;
        case 'plan':
            $from=point((string)($_GET['from'] ?? '')); $to=point((string)($_GET['to'] ?? ''));
            $from['name']='Your starting point'; $to['name']='Your destination';
            $result=App\StreetJourneys::plan($service,$from,$to,time());
            break;
        case 'phones':
            $parts=explode(',',(string)($_GET['bbox'] ?? '115.65,-32.2,116.1,-31.7'));
            if (count($parts)!==4 || count(array_filter($parts,'is_numeric'))!==4) { throw new InvalidArgumentException('Invalid map bounds.'); }
            $result=App\Payphones::inBounds(array_map('floatval',$parts));
            break;
        case 'approach':
            $trip=trim((string)($_GET['trip'] ?? '')); $stop=trim((string)($_GET['stop'] ?? ''));
            if (strlen($trip)>200 || strlen($stop)>200) { throw new InvalidArgumentException('Invalid stop or service.'); }
            $result=$service->approach($trip,$stop,null,isset($_GET['date']) ? (string)$_GET['date'] : null,isset($_GET['boarding']) ? (string)$_GET['boarding'] : null);
            break;
        case 'transfer':
            $from=trim((string)($_GET['from'] ?? '')); $to=trim((string)($_GET['to'] ?? ''));
            $trip=trim((string)($_GET['trip'] ?? ''));
            if (strlen($from)>200 || strlen($to)>200 || strlen($trip)>200) { throw new InvalidArgumentException('Invalid transfer stop or service.'); }
            $result=App\TransferGuide::guide($from,$to,$trip!=='' ? $trip : null,isset($_GET['date']) ? (string)$_GET['date'] : null);
            break;
        case 'timetable':
            $stop=trim((string)($_GET['stop'] ?? '')); $trip=trim((string)($_GET['trip'] ?? ''));
            if (strlen($stop)>200 || strlen($trip)>200) { throw new InvalidArgumentException('Invalid stop or service.'); }
            $result=$service->timetable($stop,$trip,null,isset($_GET['date']) ? (string)$_GET['date'] : null);
            break;
        case 'geocode':
            $query=trim((string)($_GET['q'] ?? ''));
            if (strlen($query)<3 || strlen($query)>160) { throw new InvalidArgumentException('Enter an address or place name (3–160 characters).'); }
            $result=App\Geocoder::search($query);
            break;
        default:
            throw new InvalidArgumentException('Unknown API action.');
    }
    $json=json_encode($result,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (strlen($json)>2048 && acceptsGzip() && function_exists('gzencode') && !ini_get('zlib.output_compression')) {
        header('Vary: Accept-Encoding'); header('Content-Encoding: gzip');
        echo gzencode($json,4);
    } else {
        echo $json;
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error'=>$e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(503);
    echo json_encode(['error'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('Perth Move API: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>'The transport service is unavailable. Check the PHP error log and refresh the timetable in loader.php.']);
}
