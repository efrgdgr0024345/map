<?php
declare(strict_types=1);

/** Run with `php tests/transit.php`; all databases and synthetic GTFS remain in /tmp. */
$project = dirname(__DIR__);
$isolated = sys_get_temp_dir() . '/perthmove-transit-test-' . bin2hex(random_bytes(6));
mkdir($isolated . '/src', 0700, true);
mkdir($isolated . '/var', 0700, true);
copy($project . '/bootstrap.php', $isolated . '/bootstrap.php');
foreach (glob($project . '/src/*.php') as $source) {
    copy($source, $isolated . '/src/' . basename($source));
}
require $isolated . '/bootstrap.php';

use App\Geo;
use App\GtfsImporter;
use App\Transit;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++;
    echo 'PASS: ' . $message . PHP_EOL;
}

function csv(array $header, array $records): string
{
    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, $header, ',', '"', '', "\n");
    foreach ($records as $row) { fputcsv($stream, $row, ',', '"', '', "\n"); }
    rewind($stream);
    $result = stream_get_contents($stream);
    fclose($stream);
    return $result;
}

function clockAt(int $seconds): string
{
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}

function archive(string $path, array $files): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create test archive.');
    }
    foreach ($files as $name => $content) { $zip->addFromString($name, $content); }
    $zip->close();
}

function rideIds(array $journey): array
{
    return array_values(array_map(static fn($leg) => $leg['tripId'], array_filter($journey['legs'], static fn($leg) => $leg['mode'] !== 'walk')));
}

function removeTree(string $directory): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($directory);
}

try {
    check(date_default_timezone_get() === 'Australia/Perth', 'application uses the Perth timezone');
    check(GtfsImporter::seconds('25:10:30') === 90630, 'GTFS clock supports times after midnight');
    $invalidClock = false;
    try { GtfsImporter::seconds('12:60:00'); } catch (RuntimeException) { $invalidClock = true; }
    check($invalidClock, 'invalid GTFS clock is rejected');

    $now = time();
    $midnight = strtotime(date('Y-m-d', $now));
    $today = date('Ymd', $midnight);
    $yesterday = date('Ymd', $midnight - 86400);
    $tomorrow = date('Ymd', $midnight + 86400);
    $start = date('Ymd', $midnight - 2 * 86400);
    $end = date('Ymd', $midnight + 7 * 86400);
    $liveSeconds = $now - $midnight;
    $liveStart = max(0, $liveSeconds - 300);
    $liveEnd = $liveSeconds + 300;

    $stops = [
        ['A', 'Origin', -31.95, 115.85],
        ['B', 'Transfer', -31.95, 115.90],
        ['C', 'Destination', -31.95, 115.95],
        ['D', 'Far stop', -31.95, 116.00],
        ['E', 'Farther stop', -31.95, 116.05],
    ];
    $routes = [['FAST', 'Fast', 3], ['SLOW', 'Slow', 3], ['SECOND', 'Second', 3], ['NETWORK', 'Network', 3], ['RAIL', 'Rail', 2]];
    $trips = [];
    $times = [];
    $addTrip = static function (string $id, string $route, string $service, array $events, string $shape = '') use (&$trips, &$times): void {
        $trips[] = [$route, $service, $id, 'Synthetic integration fixture', $shape];
        foreach ($events as $i => [$stop, $seconds]) {
            // Nonconsecutive sequence numbers exercise connection construction via LEAD.
            $times[] = [$id, clockAt($seconds), clockAt($seconds), $stop, ($i + 1) * 10, 0, 0];
        }
    };
    $addTrip('fast', 'FAST', 'DAILY', [['A', 12 * 3600 + 300], ['B', 12 * 3600 + 900]], 'AB');
    $addTrip('second', 'SECOND', 'DAILY', [['B', 12 * 3600 + 1080], ['C', 12 * 3600 + 1800]], 'BC');
    $addTrip('slow', 'SLOW', 'DAILY', [['A', 12 * 3600 + 180], ['C', 12 * 3600 + 2700]], 'AC');
    $addTrip('later', 'SLOW', 'DAILY', [['A', 12 * 3600 + 1200], ['C', 12 * 3600 + 3000]], 'AC');
    $addTrip('cancelled-shortcut', 'FAST', 'CANCELLED', [['A', 12 * 3600 + 60], ['C', 12 * 3600 + 120]], 'AC');
    $addTrip('added', 'NETWORK', 'ADDED', [['D', 12 * 3600], ['E', 12 * 3600 + 600]]);
    $addTrip('night', 'FAST', 'NIGHT', [['A', 25 * 3600], ['B', 25 * 3600 + 1200]], 'AB');
    // A separate network exercises current-time vehicles without influencing deterministic noon plans.
    $addTrip('current', 'NETWORK', 'DAILY', [['D', $liveStart], ['E', $liveEnd]], 'DE');
    $addTrip('other-current', 'NETWORK', 'DAILY', [['D', max(0, $liveSeconds - 400)], ['E', $liveSeconds + 400]], 'DE');
    $addTrip('upcoming', 'NETWORK', 'DAILY', [['D', $liveSeconds + 600], ['E', $liveSeconds + 1200]], 'DE');
    $addTrip('train-current', 'RAIL', 'DAILY', [['D', $liveStart], ['E', $liveEnd]], 'DE');

    $calendarHeader = ['service_id', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'start_date', 'end_date'];
    $shapeRows = [
        ['AB', -31.95, 115.85, 10], ['AB', -31.96, 115.875, 20], ['AB', -31.95, 115.90, 30],
        ['BC', -31.95, 115.90, 10], ['BC', -31.95, 115.95, 30],
        ['AC', -31.95, 115.85, 10], ['AC', -31.95, 115.95, 30],
        ['DE', -31.95, 116.00, 10], ['DE', -31.96, 116.025, 20], ['DE', -31.95, 116.05, 30],
    ];
    $files = [
        'stops.txt' => csv(['stop_id', 'stop_name', 'stop_lat', 'stop_lon'], $stops),
        'routes.txt' => csv(['route_id', 'route_short_name', 'route_type'], $routes),
        'trips.txt' => csv(['route_id', 'service_id', 'trip_id', 'trip_headsign', 'shape_id'], $trips),
        'stop_times.txt' => csv(['trip_id', 'arrival_time', 'departure_time', 'stop_id', 'stop_sequence', 'pickup_type', 'drop_off_type'], $times),
        'calendar.txt' => csv($calendarHeader, [
            ['DAILY', 1, 1, 1, 1, 1, 1, 1, $start, $end],
            ['CANCELLED', 1, 1, 1, 1, 1, 1, 1, $start, $end],
        ]),
        'calendar_dates.txt' => csv(['service_id', 'date', 'exception_type'], [
            ['CANCELLED', $today, 2], ['ADDED', $today, 1], ['NIGHT', $yesterday, 1], ['DAILY', $tomorrow, 2],
        ]),
        'shapes.txt' => csv(['shape_id', 'shape_pt_lat', 'shape_pt_lon', 'shape_pt_sequence'], $shapeRows),
    ];
    $feed = $isolated . '/fixture.zip';
    archive($feed, $files);
    $imported = (new GtfsImporter())->import($feed);
    check($imported['counts']['stops'] === 5 && $imported['counts']['trips'] === 11 && $imported['counts']['stop_times'] === 22, 'synthetic GTFS imported with expected record counts');
    check($imported['validUntil'] === $end && is_file(APP_VAR . '/transit.sqlite'), 'validated timetable published to isolated database');

    $transit = new Transit();
    $status = $transit->status();
    check($status['mode'] === 'timetable' && !$status['realtime'] && !$status['expired'], 'imported feed reports timetable estimates rather than demo or GPS');
    $days = array_column($transit->serviceDays($midnight + 12 * 3600, 86400), null, 'date');
    check(in_array('DAILY', $days[$today]['ids'], true) && !in_array('CANCELLED', $days[$today]['ids'], true), 'calendar removal excludes a normally scheduled service');
    check(in_array('ADDED', $days[$today]['ids'], true), 'calendar addition enables service without a calendar row');
    check(!in_array('DAILY', $days[$tomorrow]['ids'], true), 'calendar removal is applied on its exact service date');
    check(in_array('NIGHT', $days[$yesterday]['ids'], true), 'yesterday remains available for clocks beyond 24:00');

    $a = ['lat' => -31.95, 'lon' => 115.85];
    $b = ['lat' => -31.95, 'lon' => 115.90];
    $c = ['lat' => -31.95, 'lon' => 115.95];
    $noon = $midnight + 12 * 3600;
    $direct = $transit->plan($a, $b, $noon)['journeys'];
    check(count($direct) >= 1 && rideIds($direct[0]) === ['fast'] && strtotime($direct[0]['arrival']) === $noon + 900, 'earliest direct journey uses the feasible scheduled trip');
    $transfer = $transit->plan($a, $c, $noon)['journeys'];
    check(count($transfer) === 2 && rideIds($transfer[0]) === ['fast', 'second'] && $transfer[0]['transfers'] === 1 && strtotime($transfer[0]['arrival']) === $noon + 1800, 'earliest arrival correctly prefers a transfer over a slower direct bus');
    check(rideIds($transfer[1]) === ['slow'] && strtotime($transfer[1]['arrival']) === $noon + 2700, 'skip-this-bus alternative is recalculated from the timetable');
    check(!in_array('cancelled-shortcut', rideIds($transfer[0]), true), 'cancelled service cannot win journey planning');
    $night = $transit->plan($a, $b, $midnight + 50 * 60)['journeys'];
    check(count($night) >= 1 && rideIds($night[0]) === ['night'] && strtotime($night[0]['arrival']) === $midnight + 80 * 60, 'previous service day routes correctly after midnight');
    check($transit->plan($a, ['lat' => -32.4, 'lon' => 116.4], $noon)['journeys'] === [], 'infeasible distant destination produces no invented journey');

    // Repeated scans share stop locations, but corrected street durations must be applied each round.
    $approach=['lat'=>-31.9501,'lon'=>115.85];
    $beforeWalkCorrection=$transit->plan($approach,$c,$noon)['journeys'];
    $timetableHash=hash_file('sha256',APP_VAR.'/transit.sqlite');
    $corrected=$transit->plan($approach,$c,$noon,[Geo::walkingKey($approach,$a)=>400])['journeys'];
    check(rideIds($beforeWalkCorrection[0])===['fast','second'] && rideIds($corrected[0])===['later'], 'cached nearby locations still apply a newly verified longer walk and reject missed boardings');
    $resetWalkCorrection=$transit->plan($approach,$c,$noon)['journeys'];
    check(rideIds($resetWalkCorrection[0])===['fast','second'], 'walk-duration overrides do not persist into a later independent plan');
    $reverseWalkCorrection=$transit->plan($approach,$c,$noon,[Geo::walkingKey($a,$approach)=>400])['journeys'];
    check(rideIds($reverseWalkCorrection[0])===['fast','second'], 'cached stop clusters preserve pedestrian direction when applying verified durations');
    check(hash_file('sha256',APP_VAR.'/transit.sqlite')===$timetableHash, 'planner optimizations read existing databases without adding indexes or migrating data');
    $olderSqlite=new Transit();
    (new ReflectionProperty(Transit::class,'materializedTrips'))->setValue($olderSqlite,false);
    check($olderSqlite->plan($a,$c,$noon)['journeys']===$transfer, 'older SQLite fallback preserves the same complete transfer journeys');
    check($olderSqlite->plan($a,$b,$midnight+50*60)['journeys']===$night, 'older SQLite fallback preserves overnight service-day planning');
    unset($olderSqlite);

    $bbox = [115.7, -32.1, 116.1, -31.8];
    $network = $transit->vehicles($bbox, ['NETWORK'], 'current');
    $current = array_values(array_filter($network['vehicles'], static fn($v) => $v['tripId'] === 'current'));
    check($network['mode'] === 'timetable' && count($current) >= 1 && $current[0]['estimated'] && $current[0]['routeId'] === 'NETWORK', 'current vehicle matches its imported trip and route');
    check(count(array_filter($network['vehicles'], static fn($v) => $v['tripId'] === 'other-current')) >= 1, 'other currently operating vehicles remain visible on the chosen line');
    check(count(array_filter($network['vehicles'], static fn($v) => $v['routeId'] !== 'NETWORK')) === 0, 'focused route filter excludes unrelated routes and trains');
    $focusedStops=array_column($network['stops'],'id');
    sort($focusedStops);
    check($focusedStops===['D','E'], 'focused journeys hide stops unrelated to their selected services');
    $expectedFraction = max(0, min(1, (time() - $midnight - $liveStart) / ($liveEnd - $liveStart)));
    $expected = Geo::point([[-31.95, 116.00], [-31.96, 116.025], [-31.95, 116.05]], $expectedFraction);
    check(abs($current[0]['lat'] - $expected[0]) < 0.0001 && abs($current[0]['lon'] - $expected[1]) < 0.0001, 'vehicle interpolates along its bent GTFS shape instead of a straight chord');
    $future = $transit->vehicles($bbox, ['NETWORK'], 'upcoming');
    $upcoming = array_values(array_filter($future['vehicles'], static fn($v) => $v['tripId'] === 'upcoming'));
    check(count($upcoming) === 1 && $upcoming[0]['upcoming'] && abs($upcoming[0]['lat'] + 31.95) < 0.000001 && abs($upcoming[0]['lon'] - 116.00) < 0.000001, 'selected upcoming bus waits at the first stop without invented movement');
    $all = $transit->vehicles($bbox);
    check(count(array_filter($all['vehicles'], static fn($v) => $v['tripId'] === 'train-current' && $v['mode'] === 'train')) >= 1, 'network view includes an operating train with the correct mode');

    $publishedHash = hash_file('sha256', APP_VAR . '/transit.sqlite');
    $expired = $files;
    $expired['calendar.txt'] = csv($calendarHeader, [['DAILY', 1, 1, 1, 1, 1, 1, 1, date('Ymd', $midnight - 30 * 86400), $yesterday]]);
    $expired['calendar_dates.txt'] = csv(['service_id', 'date', 'exception_type'], []);
    archive($isolated . '/expired.zip', $expired);
    $expiredRejected = false;
    try { (new GtfsImporter())->import($isolated . '/expired.zip'); } catch (RuntimeException $error) { $expiredRejected = str_contains($error->getMessage(), 'expired'); }
    check($expiredRejected && hash_file('sha256', APP_VAR . '/transit.sqlite') === $publishedHash, 'expired feed is rejected while retaining the last usable database');
    $malformed = $files;
    $malformed['stop_times.txt'] .= "bad,row\n";
    archive($isolated . '/malformed.zip', $malformed);
    $malformedRejected = false;
    try { (new GtfsImporter())->import($isolated . '/malformed.zip'); } catch (RuntimeException $error) { $malformedRejected = str_contains($error->getMessage(), 'Malformed CSV'); }
    check($malformedRejected && hash_file('sha256', APP_VAR . '/transit.sqlite') === $publishedHash, 'malformed GTFS CSV cannot replace the last usable database');
    file_put_contents($isolated . '/not-a-zip.zip', 'This is not a GTFS ZIP archive.');
    $badZipRejected = false;
    try { (new GtfsImporter())->import($isolated . '/not-a-zip.zip'); } catch (RuntimeException $error) { $badZipRejected = str_contains($error->getMessage(), 'readable ZIP'); }
    check($badZipRejected && hash_file('sha256', APP_VAR . '/transit.sqlite') === $publishedHash, 'invalid ZIP reports a readable error and preserves the existing database');
    check(glob(APP_VAR . '/import-*.sqlite') === [], 'failed import staging databases are cleaned up');

    unset($transit);
    $repeat = (new GtfsImporter())->import($feed);
    check($repeat['counts'] === $imported['counts'] && (new Transit())->status()['mode'] === 'timetable', 'reimport is repeatable and retains timetable functionality');

    // Exact header formatting observed in the official Transperth shapes.txt.
    $spaced = $files;
    $spaced['shapes.txt'] = preg_replace('/^[^\n]+\n/', "shape_id, shape_pt_lat, shape_pt_lon, shape_pt_sequence\r\n", $files['shapes.txt'], 1);
    archive($isolated . '/official-header-format.zip', $spaced);
    $spacedResult = (new GtfsImporter())->import($isolated . '/official-header-format.zip');
    $shapeDb = new PDO('sqlite:' . APP_VAR . '/transit.sqlite');
    $actualShapes = array_map(static fn($row) => [$row['id'], (float)$row['lat'], (float)$row['lon'], (int)$row['seq']], $shapeDb->query('SELECT id,seq,lat,lon FROM shapes ORDER BY id,seq')->fetchAll(PDO::FETCH_ASSOC));
    $expectedShapes = $shapeRows;
    usort($expectedShapes, static fn($a, $b) => [$a[0], $a[3]] <=> [$b[0], $b[3]]);
    check($spacedResult['counts']['shapes'] === count($shapeRows) && $actualShapes === $expectedShapes, 'official whitespace-padded shapes headers preserve every latitude, longitude and sequence');
    $shapeDb = null;
    $publishedHash = hash_file('sha256', APP_VAR . '/transit.sqlite');

    $missingColumn = $spaced;
    $missingColumn['shapes.txt'] = preg_replace('/^[^\n]+\n/', "shape_id, shape_pt_lat, shape_pt_lon, incorrect_sequence\r\n", $spaced['shapes.txt'], 1);
    archive($isolated . '/missing-column.zip', $missingColumn);
    $missingRejected = false;
    try { (new GtfsImporter())->import($isolated . '/missing-column.zip'); } catch (RuntimeException $error) {
        $missingRejected = str_contains($error->getMessage(), 'Invalid GTFS columns in shapes.txt') && str_contains($error->getMessage(), 'missing shape_pt_sequence');
    }
    check($missingRejected && hash_file('sha256', APP_VAR . '/transit.sqlite') === $publishedHash, 'missing required shape column reports its name and preserves the previous database');

    $duplicateColumn = $spaced;
    $duplicateColumn['shapes.txt'] = preg_replace('/^[^\n]+\n/', "shape_id, shape_pt_lat, shape_pt_lon, shape_pt_sequence,shape_pt_sequence\r\n", $spaced['shapes.txt'], 1);
    archive($isolated . '/duplicate-column.zip', $duplicateColumn);
    $duplicateRejected = false;
    try { (new GtfsImporter())->import($isolated . '/duplicate-column.zip'); } catch (RuntimeException $error) {
        $duplicateRejected = str_contains($error->getMessage(), 'Invalid GTFS columns in shapes.txt') && str_contains($error->getMessage(), 'duplicate column names');
    }
    check($duplicateRejected && hash_file('sha256', APP_VAR . '/transit.sqlite') === $publishedHash, 'duplicate normalized shape column is rejected clearly without replacing the previous database');
    echo PHP_EOL . $checks . ' checks passed. Synthetic fixtures only; no official feed or live vehicle access was tested.' . PHP_EOL;
} finally {
    removeTree($isolated);
}
