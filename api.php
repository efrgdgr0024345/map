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
            break;
        case 'vehicles':
            $bbox=explode(',',(string)($_GET['bbox'] ?? '115.65,-32.2,116.1,-31.7'));
            if (count($bbox)!==4 || count(array_filter($bbox,'is_numeric'))!==4) { throw new InvalidArgumentException('Invalid map bounds.'); }
            $bbox=array_map('floatval',$bbox);
            if ($bbox[0]>=$bbox[2] || $bbox[1]>=$bbox[3] || $bbox[0]<-180 || $bbox[2]>180 || $bbox[1]<-90 || $bbox[3]>90) { throw new InvalidArgumentException('Invalid map bounds.'); }
            $routes=array_values(array_filter(explode(',',substr((string)($_GET['routes'] ?? ''),0,2000))));
            $result=$service->vehicles($bbox,$routes,substr((string)($_GET['journey'] ?? ''),0,200));
            break;
        case 'plan':
            $from=point((string)($_GET['from'] ?? '')); $to=point((string)($_GET['to'] ?? ''));
            $from['name']='Your starting point'; $to['name']='Your destination';
            $result=$service->plan($from,$to,time());
            break;
        case 'geocode':
            $query=trim((string)($_GET['q'] ?? ''));
            if (strlen($query)<3 || strlen($query)>160) { throw new InvalidArgumentException('Enter an address or place name (3–160 characters).'); }
            $result=App\Geocoder::search($query);
            break;
        default:
            throw new InvalidArgumentException('Unknown API action.');
    }
    echo json_encode($result,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
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
