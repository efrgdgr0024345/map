<?php
declare(strict_types=1);
namespace App;

use RuntimeException;
use Throwable;

/** Pedestrian OSRM routing. Personal endpoints are never persisted to disk. */
final class WalkingRouter
{
    // This is FOSSGIS's separately extracted pedestrian backend, not its car router.
    public const ENDPOINT = 'https://routing.openstreetmap.de/routed-foot/route/v1/driving/';
    public const SOURCE = 'FOSSGIS OSRM foot / OpenStreetMap';
    private const TTL = 30 * 86400;
    private const MAX_RESPONSE = 10 * 1024 * 1024;
    private const MAX_SNAP = 50.0;
    private const GUARD = "<?php http_response_code(404); exit; ?>\n";
    private static array $memo = [];

    /**
     * Endpoints with trusted server-supplied GTFS IDs may use the public-stop
     * cache. GPS/address/map endpoints have no IDs and remain request-local.
     */
    public static function route(array $from, array $to, int $timeoutSeconds = 6): array
    {
        $a = self::point($from); $b = self::point($to);
        if (Geo::distance($a, $b) < 0.1) {
            throw new RuntimeException('These walking endpoints are the same location. No street route is needed.');
        }
        $key = hash('sha256', self::ENDPOINT . json_encode([$a, $b], JSON_THROW_ON_ERROR));
        if (isset(self::$memo[$key])) { return self::$memo[$key]; }
        $publicStops = self::publicStops($from, $to);
        $directory = self::directory();
        $cache = $directory . '/route-' . $key . '.php';
        if ($publicStops && ($cached = self::readCache($cache, $a, $b)) !== null) {
            return self::remember($key, $cached);
        }
        $lock = null;
        if ($publicStops) {
            $lockPath = $directory . '/route-' . $key . '.lock';
            if (is_link($lockPath)) { throw new RuntimeException('Walking route cache has an unsafe lock path.'); }
            $lock = fopen($lockPath, 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                if ($lock) { fclose($lock); }
                throw new RuntimeException('This walking route is already being prepared. Please try again shortly.');
            }
            chmod($lockPath, 0600);
        }
        try {
            // Recheck after acquiring the shared public-route cache lock.
            if ($publicStops && ($cached = self::readCache($cache, $a, $b)) !== null) {
                return self::remember($key, $cached);
            }
            self::pace($directory);
            $json = self::download($a, $b, max(1, min(6, $timeoutSeconds)));
            $result = self::parseResponse($json, $a, $b);
            if ($publicStops) { self::writeCache($cache, $json); }
            return self::remember($key, $result);
        } finally {
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    /** Validate a provider response without network access; useful for offline checks. */
    public static function parseResponse(string $json, array $from, array $to): array
    {
        $a = self::point($from); $b = self::point($to);
        if (strlen($json) > self::MAX_RESPONSE) { throw new RuntimeException('The walking service response is too large.'); }
        try { $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new RuntimeException('The walking service returned an unreadable response. Please try again later.'); }
        if (!is_array($data) || ($data['code'] ?? '') !== 'Ok') {
            throw new RuntimeException('No mapped pedestrian route was found between these points. Choose a nearby accessible street or stop.');
        }
        $route = $data['routes'][0] ?? null;
        $waypoints = $data['waypoints'] ?? null;
        if (!is_array($route) || !is_array($waypoints) || !array_is_list($waypoints) || count($waypoints) !== 2
            || !is_array($route['geometry'] ?? null) || ($route['geometry']['type'] ?? '') !== 'LineString') {
            throw new RuntimeException('The walking service returned an incomplete pedestrian route.');
        }
        $coordinates = $route['geometry']['coordinates'] ?? null;
        if (!is_array($coordinates) || !array_is_list($coordinates) || count($coordinates) < 2 || count($coordinates) > 50000) {
            throw new RuntimeException('The walking service returned no usable footpath geometry.');
        }
        $geometry = [];
        foreach ($coordinates as $coordinate) { $geometry[] = self::coordinate($coordinate); }
        $distance = self::metric($route['distance'] ?? null, 500000.0);
        $duration = self::metric($route['duration'] ?? null, 172800.0);
        $snaps = []; $connectors = []; $accessDistance = 0.0;
        foreach ([$a, $b] as $index => $requested) {
            $waypoint = $waypoints[$index];
            if (!is_array($waypoint)) { throw new RuntimeException('The walking service returned invalid access points.'); }
            $snapped = self::coordinate($waypoint['location'] ?? null);
            $snappedPoint = ['lat'=>$snapped[0], 'lon'=>$snapped[1]];
            $gap = Geo::distance($requested, $snappedPoint);
            $reportedGap = $waypoint['distance'] ?? null;
            if ($gap > self::MAX_SNAP || !is_numeric($reportedGap) || !is_finite((float)$reportedGap)
                || (float)$reportedGap < 0 || (float)$reportedGap > self::MAX_SNAP) {
                throw new RuntimeException('A walking endpoint is too far from a mapped pedestrian path. Move it onto a nearby accessible street or stop.');
            }
            $edge = $index === 0 ? $geometry[0] : $geometry[count($geometry)-1];
            if (Geo::distance($snappedPoint, ['lat'=>$edge[0], 'lon'=>$edge[1]]) > 5.0) {
                throw new RuntimeException('The walking route does not connect to its reported pedestrian access points.');
            }
            $snaps[] = $snappedPoint;
            if ($gap > 0.5) {
                $connector = $index === 0
                    ? [[$requested['lat'],$requested['lon']],$snapped]
                    : [$snapped,[$requested['lat'],$requested['lon']]];
                $connectors[] = ['geometry'=>$connector, 'distance'=>round($gap,1),
                    'duration'=>(int)ceil($gap), 'routing'=>'unrouted_access', 'approximate'=>true,
                    'message'=>'Short access connection to the mapped path; access through private land or barriers is not verified.'];
                $accessDistance += $gap;
            }
        }
        $length = 0.0;
        for ($i=1; $i<count($geometry); $i++) {
            $length += Geo::distance(['lat'=>$geometry[$i-1][0],'lon'=>$geometry[$i-1][1]], ['lat'=>$geometry[$i][0],'lon'=>$geometry[$i][1]]);
        }
        if ($length < 0.1 || $distance < 0.1 || $duration <= 0 || $distance < $length * 0.5 || $distance > $length * 3 + 100) {
            throw new RuntimeException('The walking service returned invalid route distance or timing.');
        }
        return ['geometry'=>$geometry, 'duration'=>(int)ceil(max($duration,$distance/1.25)+$accessDistance),
            'distance'=>round($distance+$accessDistance,1), 'routingSource'=>self::SOURCE,
            'routing'=>'street', 'approximate'=>false, 'snappedFrom'=>$snaps[0], 'snappedTo'=>$snaps[1],
            'accessConnectors'=>$connectors, 'accessDistance'=>round($accessDistance,1),
            'attribution'=>'Walking paths: OpenStreetMap contributors. Routing: FOSSGIS OSRM foot.'];
    }

    private static function point(array $point): array
    {
        if (!isset($point['lat'],$point['lon']) || !is_numeric($point['lat']) || !is_numeric($point['lon'])) {
            throw new RuntimeException('Choose valid walking endpoints in the Perth region.');
        }
        $lat = (float)$point['lat']; $lon = (float)$point['lon'];
        if (!is_finite($lat) || !is_finite($lon) || $lat < -33.5 || $lat > -30.5 || $lon < 114.5 || $lon > 117.5) {
            throw new RuntimeException('Choose walking endpoints in the Perth region.');
        }
        return ['lat'=>round($lat,6), 'lon'=>round($lon,6)];
    }

    private static function coordinate(mixed $coordinate): array
    {
        if (!is_array($coordinate) || !array_is_list($coordinate) || count($coordinate) !== 2) {
            throw new RuntimeException('The walking service returned invalid path coordinates.');
        }
        $point = self::point(['lat'=>$coordinate[1] ?? null, 'lon'=>$coordinate[0] ?? null]);
        return [$point['lat'],$point['lon']];
    }

    private static function metric(mixed $value, float $maximum): float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > $maximum) {
            throw new RuntimeException('The walking service returned invalid route distance or timing.');
        }
        return (float)$value;
    }

    private static function publicStops(array $from, array $to): bool
    {
        return isset($from['id'],$to['id']) && is_string($from['id']) && is_string($to['id'])
            && $from['id'] !== '' && $to['id'] !== '';
    }

    private static function remember(string $key, array $result): array
    {
        if (count(self::$memo) >= 128) { array_shift(self::$memo); }
        return self::$memo[$key] = $result;
    }

    private static function directory(): string
    {
        if (!defined('APP_VAR')) { throw new RuntimeException('Walking routes require configured private application storage.'); }
        $directory = APP_VAR . '/walking';
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory,0700,true)) || !is_writable($directory)) {
            throw new RuntimeException('Private walking route storage is not writable.');
        }
        $deny = $directory . '/.htaccess';
        if (is_link($deny)) { throw new RuntimeException('Walking route storage has an unsafe access-rules path.'); }
        if (!is_file($deny) && file_put_contents($deny,"Require all denied\n",LOCK_EX) === false) {
            throw new RuntimeException('Cannot protect private walking route storage.');
        }
        $rules = file_get_contents($deny);
        if (!is_string($rules) || !preg_match('/Require\s+all\s+denied|Deny\s+from\s+all/i',$rules)
            || preg_match('/Require\s+all\s+granted|Allow\s+from\s+all/i',$rules)) {
            throw new RuntimeException('Private walking route storage must deny public access.');
        }
        return $directory;
    }

    private static function readCache(string $path, array $from, array $to): ?array
    {
        if (!is_file($path) || is_link($path) || filesize($path) > self::MAX_RESPONSE * 2 + 1024) { return null; }
        try {
            $content = file_get_contents($path);
            if (!is_string($content) || !str_starts_with($content,self::GUARD)) { return null; }
            $data = json_decode(substr($content,strlen(self::GUARD)),true,64,JSON_THROW_ON_ERROR);
            $age = time()-(int)($data['createdAt'] ?? 0);
            if ($age < 0 || $age > self::TTL || !is_string($data['response'] ?? null)) { return null; }
            return self::parseResponse($data['response'],$from,$to);
        } catch (Throwable) { return null; }
    }

    private static function writeCache(string $path, string $response): void
    {
        if (is_link($path)) { throw new RuntimeException('Walking route cache has an unsafe destination path.'); }
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.php';
        $content = self::GUARD . json_encode(['createdAt'=>time(),'response'=>$response],JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary,$content,LOCK_EX) === false) { throw new RuntimeException('Could not save the public-stop walking route cache.'); }
        chmod($temporary,0600);
        if (!rename($temporary,$path)) { @unlink($temporary); throw new RuntimeException('Could not publish the public-stop walking route cache.'); }
    }

    /** Limit provider calls to one new request per second; store timestamps only. */
    private static function pace(string $directory): void
    {
        $path = $directory . '/provider-clock.php';
        if (is_link($path)) { throw new RuntimeException('Walking service pacing has an unsafe storage path.'); }
        $handle = fopen($path,'c+');
        if (!$handle || !flock($handle,LOCK_EX)) { if($handle){fclose($handle);} throw new RuntimeException('Walking service is busy. Please try again shortly.'); }
        try {
            chmod($path,0600);
            $content = stream_get_contents($handle);
            $last = is_string($content) && str_starts_with($content,self::GUARD) ? (float)substr($content,strlen(self::GUARD)) : 0.0;
            $wait = 1.0-(microtime(true)-$last);
            if ($wait > 0 && $wait <= 1.0) { usleep((int)ceil($wait*1000000)); }
            rewind($handle); ftruncate($handle,0);
            fwrite($handle,self::GUARD . (string)microtime(true)); fflush($handle);
        } finally { flock($handle,LOCK_UN); fclose($handle); }
    }

    private static function download(array $from, array $to, int $timeout): string
    {
        if (!function_exists('curl_init')) { throw new RuntimeException('Street walking routes require the PHP cURL extension.'); }
        $coordinates = sprintf('%.6F,%.6F;%.6F,%.6F',$from['lon'],$from['lat'],$to['lon'],$to['lat']);
        $url = self::ENDPOINT . $coordinates . '?' . http_build_query([
            'overview'=>'full','geometries'=>'geojson','steps'=>'false','alternatives'=>'false','radiuses'=>'50;50','generate_hints'=>'false',
        ]);
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl,[CURLOPT_CONNECTTIMEOUT=>min(3,$timeout),CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'PerthMove/0.1 (https://github.com/efrgdgr0024345/map)',
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use(&$body): int {
                if(strlen($body)+strlen($chunk)>self::MAX_RESPONSE){return 0;}
                $body.=$chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($curl); $status = (int)curl_getinfo($curl,CURLINFO_HTTP_CODE); $curl = null;
        if ($ok === false || $status !== 200) {
            throw new RuntimeException('Pedestrian street routing is temporarily unavailable. No road or footpath route has been substituted; try again shortly.');
        }
        return $body;
    }
}
