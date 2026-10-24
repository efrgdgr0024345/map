<?php
declare(strict_types=1);

/** Deterministic isolated fixtures; no real timetable or network calls. */
$project = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/perth-route-cache-test-' . bin2hex(random_bytes(6));
mkdir($temporary . '/var', 0700, true);
define('APP_ROOT', $project);
define('APP_VAR', $temporary . '/var');
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) { require APP_ROOT . '/src/' . substr($class, 4) . '.php'; }
});

use App\RouteCache;

$checks = 0;
function rc_check(bool $passed, string $message): void
{
    global $checks;
    if (!$passed) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++;
    echo 'PASS: ' . $message . PHP_EOL;
}

function rc_snapshot(): array
{
    $hashes = [];
    foreach (['routes.json', 'routes.json.gz', 'routes.meta.json'] as $name) {
        $hashes[$name] = is_file(APP_VAR . '/' . $name) ? hash_file('sha256', APP_VAR . '/' . $name) : null;
    }
    return $hashes;
}

function rc_remove(string $path): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($path);
}

try {
    $database = $temporary . '/fixture.sqlite';
    $db = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=1000;');
    $db->exec('CREATE TABLE meta(key TEXT PRIMARY KEY,value TEXT);
        CREATE TABLE routes(id TEXT PRIMARY KEY,name TEXT,type INTEGER);
        CREATE TABLE route_styles(route_id TEXT PRIMARY KEY,color TEXT,text_color TEXT);
        CREATE TABLE trips(id TEXT PRIMARY KEY,route_id TEXT,shape_id TEXT);
        CREATE TABLE shapes(id TEXT,seq INTEGER,lat REAL,lon REAL,PRIMARY KEY(id,seq));
        CREATE TABLE stops(id TEXT PRIMARY KEY,lat REAL,lon REAL);
        CREATE TABLE stop_times(trip_id TEXT,stop_id TEXT,seq INTEGER);
        INSERT INTO meta VALUES ("imported_at","2026-10-24T12:00:00+08:00"),("end_date","20270118");
        INSERT INTO routes VALUES ("BUS","950",3),("TRAIN","Airport Line",2),("FERRY","Ferry",4);
        INSERT INTO route_styles VALUES ("BUS","FF0000","FFFFFF"),("TRAIN","",""),("FERRY","009645","FFFFFF");
        INSERT INTO trips VALUES ("bus1","BUS","STRAIGHT"),("bus2","BUS","STRAIGHT"),
            ("rail1","TRAIN","STRAIGHT"),("rail2","TRAIN","BEND"),("ferry1","FERRY","");
        INSERT INTO stops VALUES ("FA",-31.95,115.8),("FB",-31.945,115.805),("FC",-31.94,115.81);
        INSERT INTO stop_times VALUES ("ferry1","FC",30),("ferry1","FA",10),("ferry1","FB",20);');
    $insert = $db->prepare('INSERT INTO shapes VALUES (?,?,?,?)');
    for ($i = 0; $i <= 100; $i++) {
        $insert->execute(['STRAIGHT', $i * 10, -31.95 + $i * 0.0001, 115.85 + $i * 0.0001]);
    }
    foreach ([[-31.95,115.85],[-31.949,115.851],[-31.948,115.852],[-31.948,115.86],[-31.948,115.87]] as $i => [$lat,$lon]) {
        $insert->execute(['BEND', $i * 10, $lat, $lon]);
    }
    $result = RouteCache::build($database);
    $cache = RouteCache::read($database);
    rc_check($result['lines'] === 4 && count($cache['lines']) === 4 && $result['omittedLines'] === 0, 'all unique route/shape variants are cached once despite repeated trips');
    rc_check($cache['revision'] === RouteCache::revision($database) && $cache['mode'] === 'timetable' && $cache['approximate'] === true, 'network cache carries a stable timetable revision and approximation label');
    rc_check(!isset($cache['vehicles']) && str_contains($cache['message'], 'do not indicate which services are running now'), 'static overview never claims current vehicle positions or active services');
    rc_check(RouteCache::path($database) === APP_VAR . '/routes.json' && $result['bytes'] === filesize(APP_VAR . '/routes.json'), 'cache path supports direct serving without decoding the network');
    $lines = [];
    foreach ($cache['lines'] as $line) { $lines[$line['routeId'] . '/' . $line['shapeId']] = $line; }
    rc_check(count($lines) === 4 && isset($lines['BUS/STRAIGHT'], $lines['TRAIN/STRAIGHT'], $lines['TRAIN/BEND'], $lines['FERRY/']), 'route/shape identities preserve shared shapes and alternate variants');
    $straight = $lines['BUS/STRAIGHT'];
    rc_check(count($straight['geometry']) === 2 && $straight['geometry'][0] === [-31.95,115.85] && $straight['geometry'][1] === [-31.94,115.86], 'simplification removes redundant points while preserving directional endpoints');
    rc_check(in_array([-31.948,115.852], $lines['TRAIN/BEND']['geometry'], true), 'simplification retains a meaningful bend exceeding the metre tolerance');
    rc_check($straight['routeColor'] === '#FF0000' && $straight['routeTextColor'] === '#000000' && $straight['mode'] === 'bus', 'feed colour and readable label are retained on bus geometry');
    rc_check($lines['TRAIN/BEND']['routeColor'] === '#4FC7B5' && $lines['TRAIN/BEND']['lineName'] === 'Airport Line' && $lines['TRAIN/BEND']['mode'] === 'train', 'named network style supplies missing train feed colours');
    rc_check($lines['FERRY/']['mode'] === 'ferry' && $lines['FERRY/']['geometry'] === [[-31.95,115.8],[-31.94,115.81]], 'missing-shape trips fall back to stops in sequence order');
    foreach ($cache['lines'] as $line) {
        [$west,$south,$east,$north] = $line['bbox'];
        foreach ($line['geometry'] as [$lat,$lon]) {
            if ($lon < $west || $lon > $east || $lat < $south || $lat > $north) { throw new RuntimeException('Bounding box omitted a geometry point.'); }
        }
    }
    rc_check(true, 'west/south/east/north bounds enclose every rendered geometry');
    rc_check(!isset($straight['tripId'], $straight['lat'], $straight['bearing']) && $result['points'] < $result['sourcePoints'], 'overview is compact and contains no trip-position fields');
    if (function_exists('gzdecode')) {
        rc_check(gzdecode(file_get_contents(APP_VAR . '/routes.json.gz')) === file_get_contents(APP_VAR . '/routes.json') && $result['gzipBytes'] === filesize(APP_VAR . '/routes.json.gz'), 'gzip sidecar exactly matches the completed JSON cache');
    }
    $firstRevision = $result['revision'];
    $second = RouteCache::build($database);
    rc_check($second['revision'] === $firstRevision && $second['lines'] === $result['lines'] && $second['points'] === $result['points'], 'repeated cache builds keep the same source revision and geometry counts');
    $path = APP_VAR . '/routes.json';
    $untouched = rc_snapshot();
    RouteCache::path($database);
    rc_check(rc_snapshot() === $untouched, 'fresh cache reads reuse persistent files without rebuilding');
    $renamed = $temporary . '/published.sqlite';
    rename($database, $renamed);
    $sameAfterRename = RouteCache::revision($renamed);
    rename($renamed, $database);
    rc_check($sameAfterRename === $firstRevision, 'publishing a staging database by rename preserves its cache revision');

    $db->exec('UPDATE meta SET value="2026-10-24T12:01:00+08:00" WHERE key="imported_at"');
    $fresh = RouteCache::read($database);
    rc_check($fresh['revision'] !== $firstRevision && $fresh['revision'] === RouteCache::revision($database), 'changed import metadata automatically invalidates and refreshes the route cache');
    $lastComplete = rc_snapshot();
    $db->exec('UPDATE meta SET value="2026-10-24T12:02:00+08:00" WHERE key="imported_at"');
    $lock = fopen(APP_VAR . '/routes.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $stalePath = RouteCache::path($database);
        rc_check($stalePath === APP_VAR . '/routes.json' && rc_snapshot() === $lastComplete, 'concurrent refresh request serves the previous complete cache without blocking');
        $oldJson = file_get_contents($stalePath);
        unlink($stalePath);
        $busy = false;
        try { RouteCache::path($database); } catch (RuntimeException $error) { $busy = str_contains($error->getMessage(), 'being prepared'); }
        file_put_contents($stalePath, $oldJson);
        rc_check($busy, 'first-build contention returns an explicit retryable error instead of an empty network');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    $recovered = RouteCache::read($database);
    rc_check($recovered['revision'] === RouteCache::revision($database) && $recovered['revision'] !== $fresh['revision'], 'next request refreshes a stale cache once the build lock is released');
    $lastComplete = rc_snapshot();
    $db->exec('UPDATE shapes SET lat=999 WHERE id="BEND" AND seq=0');
    $invalid = false;
    try { RouteCache::build($database); } catch (RuntimeException $error) { $invalid = str_contains($error->getMessage(), 'invalid coordinate'); }
    rc_check($invalid && rc_snapshot() === $lastComplete, 'invalid source coordinates cannot publish partial or corrupt network JSON');
    rc_check(glob(APP_VAR . '/routes-*.json*') === [], 'failed cache builds clean staged JSON, metadata and compressed files');
    $db->exec('UPDATE shapes SET lat=-31.95 WHERE id="BEND" AND seq=0; DROP TABLE route_styles;
        UPDATE meta SET value="2026-10-24T12:03:00+08:00" WHERE key="imported_at";');
    $legacy = RouteCache::read($database);
    $legacyBus = array_values(array_filter($legacy['lines'], static fn($line) => $line['routeId'] === 'BUS'))[0];
    rc_check($legacyBus['routeColor'] === '#009645' && count($legacy['lines']) === 4, 'legacy databases without the optional route_styles table still cache every route');
    $db->exec('DELETE FROM trips;');
    $lastComplete = rc_snapshot();
    $emptyRejected = false;
    try { RouteCache::build($database); } catch (RuntimeException $error) { $emptyRejected = str_contains($error->getMessage(), 'no usable route geometry'); }
    rc_check($emptyRejected && rc_snapshot() === $lastComplete, 'an empty source network cannot replace the last usable overview');
    $db = null;
    echo PHP_EOL . $checks . ' route-cache checks passed. Isolated local geometry only.' . PHP_EOL;
} finally {
    rc_remove($temporary);
}
