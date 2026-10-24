<?php
declare(strict_types=1);

/** Synthetic timetable/style migration tests. Real application storage is untouched. */
$project = dirname(__DIR__);
$isolated = sys_get_temp_dir() . '/perthmove-timetable-test-' . bin2hex(random_bytes(6));
mkdir($isolated . '/src', 0700, true);
mkdir($isolated . '/var', 0700, true);
copy($project . '/bootstrap.php', $isolated . '/bootstrap.php');
foreach (glob($project . '/src/*.php') as $source) { copy($source, $isolated . '/src/' . basename($source)); }
require $isolated . '/bootstrap.php';

use App\GtfsImporter;
use App\Transit;

$checks = 0;
function timetableCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++;
    echo 'PASS: ' . $message . PHP_EOL;
}
function timetableCsv(array $header, array $rows): string
{
    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, $header, ',', '"', '', "\n");
    foreach ($rows as $row) { fputcsv($stream, $row, ',', '"', '', "\n"); }
    rewind($stream);
    $result = stream_get_contents($stream);
    fclose($stream);
    return $result;
}
function timetableClock(int $seconds): string
{
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}
function timetableIds(array $rows): array { return array_column($rows, 'tripId'); }
function timetableRow(array $rows, string $tripId): array
{
    foreach ($rows as $row) { if (($row['tripId'] ?? '') === $tripId) { return $row; } }
    throw new RuntimeException('Expected fixture departure not returned: ' . $tripId);
}
function timetableReject(callable $operation, string $exception, string $message): void
{
    $rejected = false;
    try { $operation(); } catch (Throwable $error) { $rejected = $error instanceof $exception; }
    timetableCheck($rejected, $message);
}
function timetableRemove(string $directory): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($directory);
}

try {
    $now = time();
    $midnight = strtotime(date('Y-m-d', $now));
    $today = date('Ymd', $midnight);
    $yesterday = date('Ymd', $midnight - 86400);
    $tomorrow = date('Ymd', $midnight + 86400);
    $start = date('Ymd', $midnight - 2 * 86400);
    $end = date('Ymd', $midnight + 7 * 86400);
    $trips = []; $times = [];
    $trip = static function (string $id, string $route, string $service, array $events, string $shape = 'AC') use (&$trips, &$times): void {
        $trips[] = [$route, $service, $id, 'Fixture destination', $shape];
        foreach ($events as $i => $event) {
            [$stop, $seconds] = $event;
            $pickup = $event[2] ?? 0;
            $dropoff = $event[3] ?? 0;
            $arrival = timetableClock($seconds);
            $departure = timetableClock($seconds + ($event[4] ?? 0));
            $times[] = [$id, $arrival, $departure, $stop, ($i + 1) * 10, $pickup, $dropoff];
        }
    };
    $trip('early', 'R', 'DAILY', [['A', 600], ['B', 1200]]);
    $trip('night', 'R', 'NIGHT', [['A', 25 * 3600], ['B', 25 * 3600 + 600], ['C', 25 * 3600 + 1200, 1, 1]]);
    $trip('cancelled', 'R', 'CANCEL', [['A', 3600], ['B', 4200]]);
    $trip('added', 'R', 'ADDED', [['A', 3900], ['B', 4500]]);
    $trip('morning', 'R', 'DAILY', [['A', 4200, 0, 0, 30], ['B', 4800], ['C', 5400, 1]]);
    $trip('no-pickup', 'R', 'DAILY', [['A', 4500, 1], ['B', 5100]]);
    $trip('phone-pickup', 'BLUE', 'DAILY', [['A', 4800, 2], ['B', 5400]]);
    $trip('driver-pickup', 'BAD', 'DAILY', [['A', 5100, 3], ['B', 5700]]);
    $trip('boundary', 'R', 'DAILY', [['A', 6 * 3600 + 50 * 60], ['B', 7 * 3600]]);
    $trip('beyond-window', 'R', 'DAILY', [['A', 6 * 3600 + 50 * 60 + 1], ['B', 7 * 3600 + 1]]);
    $trip('late', 'R', 'DAILY', [['A', 23 * 3600 + 45 * 60], ['B', 23 * 3600 + 55 * 60]]);
    $trip('numeric-service', 'R', '123', [['A', 8100], ['B', 8700]]);
    $trip('noon', 'R', 'DAILY', [['A', 12 * 3600 + 300], ['B', 12 * 3600 + 1200]]);
    for ($i = 0; $i < 25; $i++) {
        $trip('bulk-' . $i, 'R', 'DAILY', [['C', 2 * 3600 + $i * 60], ['B', 2 * 3600 + 1800 + $i * 60]]);
    }
    $seconds = $now - $midnight;
    $trip('current-bus', 'R', 'DAILY', [['D', max(0, $seconds - 600)], ['E', $seconds + 600]], 'DE');
    $trip('current-train', 'RAIL', 'DAILY', [['E', max(0, $seconds - 600)], ['D', $seconds + 600]], 'ED');
    $files = [
        'stops.txt' => timetableCsv(['stop_id','stop_name','stop_lat','stop_lon'], [
            ['A','Fixture origin',-31.95,115.85], ['B','Fixture middle',-31.95,115.90], ['C','Fixture terminus',-31.95,115.95],
            ['D','Fixture current origin',-31.96,116.00], ['E','Fixture current destination',-31.96,116.05], ['F','Fixture empty platform',-32.2,116.1],
        ]),
        'routes.txt' => timetableCsv(['route_id','route_short_name','route_type','route_color','route_text_color'], [
            ['R','Synthetic bus',3,'102030','FFFFFF'], ['BLUE','Blue CAT',3,'',''],
            ['BAD','Synthetic invalid colour',3,'url(javascript:bad)','invalid'], ['RAIL','Airport Line',2,'',''],
        ]),
        'trips.txt' => timetableCsv(['route_id','service_id','trip_id','trip_headsign','shape_id'], $trips),
        'stop_times.txt' => timetableCsv(['trip_id','arrival_time','departure_time','stop_id','stop_sequence','pickup_type','drop_off_type'], $times),
        'calendar.txt' => timetableCsv(['service_id','monday','tuesday','wednesday','thursday','friday','saturday','sunday','start_date','end_date'], [
            ['DAILY',1,1,1,1,1,1,1,$start,$end], ['CANCEL',1,1,1,1,1,1,1,$start,$end], ['123',1,1,1,1,1,1,1,$start,$end],
        ]),
        'calendar_dates.txt' => timetableCsv(['service_id','date','exception_type'], [['CANCEL',$today,2], ['NIGHT',$yesterday,1], ['ADDED',$today,1]]),
        'shapes.txt' => timetableCsv(['shape_id','shape_pt_lat','shape_pt_lon','shape_pt_sequence'], [
            ['AC',-31.95,115.85,10], ['AC',-31.95,115.90,20], ['AC',-31.95,115.95,30],
            ['DE',-31.96,116.00,10], ['DE',-31.96,116.05,20], ['ED',-31.96,116.05,10], ['ED',-31.96,116.00,20],
        ]),
    ];
    $zip = new ZipArchive();
    $fixture = $isolated . '/timetable-fixture.zip';
    if ($zip->open($fixture, ZipArchive::CREATE) !== true) { throw new RuntimeException('Cannot create fixture ZIP.'); }
    foreach ($files as $name => $content) { $zip->addFromString($name, $content); }
    $zip->close();
    (new GtfsImporter())->import($fixture);
    $db = new PDO('sqlite:' . APP_VAR . '/transit.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    timetableCheck((int)$db->query('SELECT COUNT(*) FROM route_styles')->fetchColumn() === 4, 'new imports retain optional route style rows without changing the core routes schema');
    timetableCheck($db->query("SELECT color FROM route_styles WHERE route_id='R'")->fetchColumn() === '102030', 'GTFS route colour is retained for application styling');
    $transit = new Transit();
    $at = $midnight + 50 * 60;
    $stop = $transit->timetable('A', '', $at);
    $departures = $stop['departures'];
    $ids = timetableIds($departures);
    timetableCheck($stop['mode'] === 'timetable' && !empty($stop['generatedAt']), 'stop timetable identifies scheduled data and generation time');
    timetableCheck(array_slice($ids,0,3) === ['night','added','morning'], 'stop departures merge yesterday after-midnight trips and calendar additions in actual departure order');
    timetableCheck(!in_array('cancelled', $ids, true), 'calendar removal prevents a cancelled trip appearing at its stop');
    timetableCheck(!in_array('no-pickup', $ids, true), 'a stop that prohibits boarding is excluded from boardable departures');
    timetableCheck(in_array('phone-pickup', $ids, true) && timetableRow($departures,'phone-pickup')['pickupType'] === 2 && timetableRow($departures,'driver-pickup')['pickupType'] === 3, 'conditional pickup services remain visible with their boarding restrictions');
    timetableCheck(in_array('boundary', $ids, true) && !in_array('beyond-window', $ids, true), 'six-hour departure window includes its exact boundary and excludes a departure one second later');
    $bulk = $transit->timetable('C', '', $at);
    timetableCheck(count($bulk['departures']) === 20 && $bulk['departures'][0]['tripId'] === 'bulk-0' && $bulk['departures'][19]['tripId'] === 'bulk-19', 'busy stop timetable returns the first twenty departures in time order');
    $empty = $transit->timetable('F', '', $at);
    timetableCheck($empty['departures'] === [] && str_contains($empty['message'], 'No scheduled departures'), 'a known stop without departures returns an honest empty timetable');
    timetableCheck(strtotime($stop['generatedAt']) === $at && str_ends_with($stop['generatedAt'], '+08:00'), 'timetable generation time uses the supplied time in the Perth timezone');
    $night = timetableRow($departures,'night');
    timetableCheck($night['serviceDate'] === $yesterday && strtotime($night['departure']) === $midnight + 3600, 'after-midnight departure retains its previous GTFS service date and correct actual timestamp');
    $morning = timetableRow($departures,'morning');
    timetableCheck(strtotime($morning['arrival']) === $midnight + 4200 && strtotime($morning['departure']) === $midnight + 4230, 'timetable preserves different arrival and departure times for a dwelling service');
    timetableCheck($morning['routeColor'] === '#102030' && $morning['routeTextColor'] === '#FFFFFF', 'stop timetable uses the feed route colour and readable feed text colour');
    timetableCheck(timetableRow($departures,'phone-pickup')['routeColor'] === '#002F6C' && preg_match('/^#[0-9A-F]{6}$/', timetableRow($departures,'driver-pickup')['routeColor']) === 1, 'named CAT fallback and invalid-feed-colour sanitization reach stop timetables');
    $numericTrip = $transit->timetable('', 'numeric-service', $at, $today);
    timetableCheck($numericTrip['trip']['id'] === 'numeric-service' && $numericTrip['serviceDate'] === $today && count($numericTrip['stops']) === 2, 'numeric-text GTFS service identifiers remain active in full trip timetable lookups');
    $nightTrip = $transit->timetable('', 'night', $at, $yesterday);
    timetableCheck($nightTrip['serviceDate'] === $yesterday && $nightTrip['trip']['serviceDate'] === $yesterday && count($nightTrip['stops']) === 3, 'full trip timetable identifies the selected service day and every ordered stop');
    timetableCheck(strtotime($nightTrip['stops'][0]['departure']) === $midnight + 3600 && strtotime($nightTrip['stops'][2]['arrival']) === $midnight + 4800, 'full trip timetable converts clocks beyond 24:00 to the following local day');
    timetableCheck($nightTrip['stops'][2]['pickupType'] === 1 && $nightTrip['stops'][2]['dropoffType'] === 1, 'full trip view keeps unboardable stops and exposes pickup and drop-off restrictions');
    $defaultNight = $transit->timetable('', 'night', $at);
    timetableCheck($defaultNight['serviceDate'] === $yesterday, 'default trip selection finds an upcoming after-midnight service on yesterday calendar');
    $defaultEarly = $transit->timetable('', 'early', $midnight + 23 * 3600 + 30 * 60);
    timetableCheck($defaultEarly['serviceDate'] === $tomorrow, 'default trip selection prefers tomorrow upcoming service over a past run with the same trip identifier');
    $late = $transit->timetable('A', '', $midnight + 23 * 3600 + 30 * 60);
    $earlyTomorrow = array_values(array_filter($late['departures'], static fn($row) => $row['tripId'] === 'early' && $row['serviceDate'] === $tomorrow));
    timetableCheck(count($earlyTomorrow) === 1 && strtotime($earlyTomorrow[0]['departure']) === $midnight + 86400 + 600, 'late-evening stop timetable includes tomorrow services within six hours');
    timetableCheck(in_array('cancelled', timetableIds($late['departures']), true), 'calendar cancellation is scoped to its exact date and does not remove tomorrow service');
    timetableReject(static fn() => $transit->timetable('', '', $at), InvalidArgumentException::class, 'timetable rejects requests without a stop or trip');
    timetableReject(static fn() => $transit->timetable('A', 'morning', $at), InvalidArgumentException::class, 'timetable rejects ambiguous stop-and-trip requests');
    timetableReject(static fn() => $transit->timetable('unknown', '', $at), InvalidArgumentException::class, 'unknown stop identifiers are rejected clearly');
    timetableReject(static fn() => $transit->timetable('', 'unknown', $at), InvalidArgumentException::class, 'unknown trip identifiers are rejected clearly');
    timetableReject(static fn() => $transit->timetable('', 'cancelled', $at, $today), InvalidArgumentException::class, 'full trip timetable rejects a service cancelled on the requested date');
    timetableReject(static fn() => $transit->timetable('', 'night', $at, $tomorrow), InvalidArgumentException::class, 'an inactive trip cannot invent a timetable for another date');
    timetableReject(static fn() => $transit->timetable('', 'morning', $at, 'not-a-date'), InvalidArgumentException::class, 'malformed timetable service dates are rejected');

    $network = $transit->vehicles([115.7,-32.1,116.1,-31.8]);
    $bus = timetableRow($network['vehicles'], 'current-bus');
    $train = timetableRow($network['vehicles'], 'current-train');
    timetableCheck($bus['routeColor'] === '#102030' && $bus['bearing'] > 89 && $bus['bearing'] < 91, 'active bus markers receive route colour and their local forward heading');
    timetableCheck($train['routeColor'] === '#4FC7B5' && $train['bearing'] > 269 && $train['bearing'] < 271, 'active rail markers receive official line fallback colour and reverse travel heading');
    $busLines = array_values(array_filter($network['lines'], static fn($line) => $line['routeId'] === 'R'));
    timetableCheck($busLines !== [] && $busLines[0]['routeColor'] === '#102030', 'drawn network lines share their vehicle route colours');
    $plan = $transit->plan(['lat'=>-31.95,'lon'=>115.85], ['lat'=>-31.95,'lon'=>115.90], $midnight + 12 * 3600);
    $rides = array_values(array_filter($plan['journeys'][0]['legs'], static fn($leg) => $leg['mode'] !== 'walk'));
    timetableCheck($rides !== [] && $rides[0]['routeColor'] === '#102030', 'selected journey ride legs retain the same route colours as map markers');

    // Simulate an existing cPanel timetable created before route styles existed.
    unset($transit);
    $legacy = APP_VAR . '/legacy.sqlite';
    copy(APP_VAR . '/transit.sqlite', $legacy);
    $legacyDb = new PDO('sqlite:' . $legacy, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $legacyDb->exec('DROP TABLE route_styles');
    $legacyDb = null;
    $beforeHash = hash_file('sha256', $legacy);
    $oldTransit = new Transit($legacy);
    $oldStop = $oldTransit->timetable('A', '', $at);
    timetableCheck(timetableRow($oldStop['departures'],'phone-pickup')['routeColor'] === '#002F6C' && preg_match('/^#[0-9A-F]{6}$/', timetableRow($oldStop['departures'],'morning')['routeColor']) === 1, 'pre-update databases remain usable with CAT and mode colour fallbacks');
    $oldTransit->vehicles([115.7,-32.1,116.1,-31.8]);
    $oldTransit->plan(['lat'=>-31.95,'lon'=>115.85], ['lat'=>-31.95,'lon'=>115.90], $midnight + 12 * 3600);
    unset($oldTransit);
    timetableCheck(hash_file('sha256', $legacy) === $beforeHash, 'style fallback leaves the legacy database byte-for-byte unchanged');
    $db->exec("UPDATE meta SET value='20000101' WHERE key='end_date'");
    timetableReject(static fn() => (new Transit())->timetable('A', '', $at), RuntimeException::class, 'expired timetable cannot present current stop departures');
    $db = null;
    echo PHP_EOL . $checks . ' timetable and style migration checks passed. Synthetic fixtures only.' . PHP_EOL;
} finally {
    timetableRemove($isolated);
}
