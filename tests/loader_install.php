<?php
declare(strict_types=1);

/** Standalone installer/update integration tests. No network or real app data. */
date_default_timezone_set('Australia/Perth');
$project = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/perth-loader-install-' . bin2hex(random_bytes(6));
$site = $temporary . '/site';
mkdir($site, 0700, true);
$originalLoader = file_get_contents($project . '/loader.php');
file_put_contents($site . '/loader.php', $originalLoader);
define('PM_LOADER_LIBRARY_ONLY', true);
require $site . '/loader.php';

$checks = 0;
function li_check(bool $passed, string $message): void
{
    global $checks;
    if (!$passed) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++;
    echo 'PASS: ' . $message . PHP_EOL;
}

function li_package(string $path, array $files, ?string $symlink = null): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create fixture ZIP.');
    }
    foreach ($files as $name => $content) { $zip->addFromString('map-main/' . $name, $content); }
    if ($symlink !== null) {
        $name = 'map-main/' . $symlink;
        $zip->addFromString($name, '../../outside.txt');
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
    }
    $zip->close();
}

function li_source_files(string $project): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project, FilesystemIterator::SKIP_DOTS)) as $entry) {
        $name = substr($entry->getPathname(), strlen($project) + 1);
        if ($entry->isFile() && !preg_match('~^(?:\.git|var|tests)/~', $name)) {
            $files[$name] = file_get_contents($entry->getPathname());
        }
    }
    return $files;
}

function li_snapshot(string $site, array $names): array
{
    $snapshot = [];
    foreach ($names as $name) {
        if (is_file($site . '/' . $name)) { $snapshot[$name] = hash_file('sha256', $site . '/' . $name); }
    }
    return $snapshot;
}

function li_rejected_without_change(string $zip, string $site, array $names): bool
{
    $before = li_snapshot($site, $names);
    $rejected = false;
    try { pm_apply_archive($zip); } catch (RuntimeException) { $rejected = true; }
    return $rejected && li_snapshot($site, $names) === $before;
}

function li_database(string $path, bool $expired = false, bool $active = true): void
{
    if (is_file($path)) { unlink($path); }
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE meta(key TEXT PRIMARY KEY,value TEXT);
        CREATE TABLE stops(id TEXT PRIMARY KEY,name TEXT,lat REAL,lon REAL);
        CREATE TABLE routes(id TEXT PRIMARY KEY,name TEXT,type INTEGER);
        CREATE TABLE trips(id TEXT PRIMARY KEY,route_id TEXT,service_id TEXT,headsign TEXT,shape_id TEXT,start INTEGER,end INTEGER);
        CREATE TABLE stop_times(trip_id TEXT,stop_id TEXT,seq INTEGER,arrival INTEGER,departure INTEGER,pickup INTEGER,dropoff INTEGER);
        CREATE TABLE connections(trip_id TEXT,seq INTEGER,from_id TEXT,to_id TEXT,departure INTEGER,arrival INTEGER,pickup INTEGER,dropoff INTEGER);
        CREATE TABLE calendar(service_id TEXT PRIMARY KEY,mon INTEGER,tue INTEGER,wed INTEGER,thu INTEGER,fri INTEGER,sat INTEGER,sun INTEGER,start TEXT,end TEXT);
        CREATE TABLE exceptions(service_id TEXT,date TEXT,type INTEGER);
        CREATE TABLE shapes(id TEXT,seq INTEGER,lat REAL,lon REAL);
        INSERT INTO stops VALUES ("A","Fixture origin",-31.95,115.85),("B","Fixture destination",-31.95,115.90);
        INSERT INTO routes VALUES ("R","Fixture",3);
        INSERT INTO trips VALUES ("T","R","DAILY","Fixture","",36000,36600);
        INSERT INTO stop_times VALUES ("T","A",1,36000,36000,0,0),("T","B",2,36600,36600,0,0);
        INSERT INTO connections VALUES ("T",1,"A","B",36000,36600,0,0);');
    $from = date('Ymd', strtotime('-7 days'));
    $until = date('Ymd', strtotime($expired ? '-1 day' : '+7 days'));
    $statement = $db->prepare('INSERT INTO meta VALUES (?,?)');
    foreach (['imported_at' => date(DATE_ATOM), 'start_date' => $from, 'end_date' => $until, 'source' => 'Synthetic test only'] as $key => $value) {
        $statement->execute([$key, $value]);
    }
    if ($active) {
        $db->prepare('INSERT INTO calendar VALUES ("DAILY",1,1,1,1,1,1,1,?,?)')->execute([$from, $until]);
    }
    $db = null;
}

try {
    li_check(!is_file($site . '/bootstrap.php') && array_values(array_diff(scandir($site), ['.', '..'])) === ['loader.php'], 'test deployment starts with loader.php alone');
    $empty = pm_readiness();
    li_check($empty['ready'] === false && $empty['application'] === false, 'single uploaded loader is not misreported as an installed application');
    pm_prepare_private();
    li_check(is_file($site . '/var/.htaccess') && is_file($site . '/var/index.php'), 'standalone loader prepares protected storage itself');
    $maintenance = ['password_hash' => password_hash('fixture-maintenance-key-only', PASSWORD_DEFAULT)];
    pm_write_private($site . '/var/maintenance.php', $maintenance);
    file_put_contents($site . '/config.php', "<?php // local fixture configuration\n");
    file_put_contents($site . '/.env', "FIXTURE_SETTING=keep\n");
    mkdir($site . '/.git', 0700);
    file_put_contents($site . '/.git/HEAD', "ref: refs/heads/local-fixture\n");
    file_put_contents($site . '/var/transit.sqlite', 'existing database sentinel');
    $preserved = ['config.php', '.env', '.git/HEAD', 'var/transit.sqlite', 'var/maintenance.php'];
    $beforePreserved = li_snapshot($site, $preserved);

    $files = li_source_files($project);
    $files['index.php'] .= "\n<!-- installer fixture app version one -->\n";
    $files['loader.php'] .= "\n<!-- installer fixture loader version one -->\n";
    // Protected host-specific paths in a release must never overwrite local values.
    $files['config.php'] = '<?php /* replacement forbidden */';
    $files['.env'] = 'REPLACEMENT=forbidden';
    $files['var/transit.sqlite'] = 'replacement forbidden';
    $files['var/maintenance.php'] = 'replacement forbidden';
    $files['.git/HEAD'] = 'replacement forbidden';
    $package = $temporary . '/release-one.zip';
    li_package($package, $files);
    $installed = pm_apply_archive($package);
    li_check(is_int($installed['files']) && $installed['files'] > 10 && is_string($installed['backup']) && is_string($installed['message']), 'archive installer returns its documented files, backup and message values');
    foreach (['index.php', 'bootstrap.php', 'api.php', '.htaccess', 'src/Transit.php', 'src/GtfsImporter.php', 'scripts/import-gtfs.php', 'assets/app.js', 'assets/style.css', 'assets/vendor/leaflet/leaflet.js', 'assets/vendor/leaflet/leaflet.css'] as $required) {
        if (!is_file($site . '/' . $required)) { throw new RuntimeException('Missing installed file: ' . $required); }
    }
    li_check(true, 'loader-only deployment installs the complete application and bundled map assets');
    li_check(str_contains(file_get_contents($site . '/loader.php'), 'installer fixture loader version one'), 'installation automatically updates loader.php itself');
    li_check(file_get_contents($site . '/' . $installed['backup'] . '/loader.php') === $originalLoader, 'self-update keeps an exact backup of the original standalone loader');
    li_check(li_snapshot($site, $preserved) === $beforePreserved, 'application install preserves database, maintenance key, local configuration and Git metadata');

    unlink($site . '/var/transit.sqlite');
    $noTimetable = pm_readiness();
    li_check($noTimetable['ready'] === false && $noTimetable['application'] === true, 'application without a timetable remains incomplete rather than ready in demo mode');
    li_database($site . '/var/transit.sqlite');
    $ready = pm_readiness();
    li_check($ready['ready'] === true && $ready['application'] === true && $ready['timetable'] === true && $ready['validUntil'] >= date('Ymd'), 'readiness requires a usable current timetable and complete application');
    $events=[];
    $beforeReadiness=hash_file('sha256',$site.'/var/transit.sqlite');
    $reported=pm_readiness(static function(string $label,string $message) use (&$events): void { $events[]=[$label,$message]; });
    li_check($reported['ready'] && count($events)===3 && str_contains($events[1][0],'metadata') && str_contains($events[2][0],"today's services"), 'initial readiness identifies file, metadata and active-service checks in progress');
    li_check(hash_file('sha256',$site.'/var/transit.sqlite')===$beforeReadiness, 'lightweight readiness does not modify the installed timetable');
    $held=new PDO('sqlite:'.$site.'/var/transit.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $held->exec('BEGIN EXCLUSIVE');
    $started=hrtime(true); $busy=pm_readiness(); $wait=(hrtime(true)-$started)/1_000_000_000;
    $held->exec('ROLLBACK'); $held=null;
    li_check(!$busy['ready'] && str_contains(strtolower($busy['message']),'locked') && $wait<5, 'a locked timetable reports unavailable within a bounded wait instead of hanging');
    li_check(pm_readiness()['ready'] && hash_file('sha256',$site.'/var/transit.sqlite')===$beforeReadiness, 'readiness recovers after the competing database lock is released');
    li_database($site . '/var/transit.sqlite', true);
    li_check(pm_readiness()['ready'] === false, 'expired timetable cannot be reported as ready');
    li_database($site . '/var/transit.sqlite', false, false);
    li_check(pm_readiness()['ready'] === false, 'date metadata alone cannot make a feed with no active services ready');
    li_database($site . '/var/transit.sqlite');

    $beforePreserved = li_snapshot($site, $preserved);
    $oldIndex = file_get_contents($site . '/index.php');
    $oldLoader = file_get_contents($site . '/loader.php');
    $versionTwo = $files;
    $versionTwo['index.php'] = str_replace('app version one', 'app version two', $files['index.php']);
    $versionTwo['loader.php'] = str_replace('loader version one', 'loader version two', $files['loader.php']);
    li_package($temporary . '/release-two.zip', $versionTwo);
    $updated = pm_apply_archive($temporary . '/release-two.zip');
    li_check(str_contains(file_get_contents($site . '/index.php'), 'app version two') && str_contains(file_get_contents($site . '/loader.php'), 'loader version two'), 'subsequent update replaces both application and loader');
    li_check(file_get_contents($site . '/' . $updated['backup'] . '/index.php') === $oldIndex && file_get_contents($site . '/' . $updated['backup'] . '/loader.php') === $oldLoader, 'update backs up every overwritten application and loader version exactly');
    li_check(li_snapshot($site, $preserved) === $beforePreserved && pm_readiness()['ready'] === true, 'update preserves a working timetable, configuration, maintenance authentication and Git metadata');
    $names = array_keys($files);

    $missing = $versionTwo;
    unset($missing['assets/vendor/leaflet/leaflet.js']);
    li_package($temporary . '/missing.zip', $missing);
    li_check(li_rejected_without_change($temporary . '/missing.zip', $site, $names), 'incomplete release is rejected before changing installed files');
    $traversal = $versionTwo;
    $traversal['../escape.php'] = '<?php // forbidden';
    li_package($temporary . '/traversal.zip', $traversal);
    li_check(li_rejected_without_change($temporary . '/traversal.zip', $site, $names) && !is_file($site . '/escape.php') && !is_file($temporary . '/escape.php'), 'traversal archive is rejected before changing installed files');
    li_package($temporary . '/symlink.zip', $versionTwo, 'assets/evil-link');
    li_check(li_rejected_without_change($temporary . '/symlink.zip', $site, $names), 'symlink archive is rejected before changing installed files');
    file_put_contents($temporary . '/invalid.zip', 'This is not an archive.');
    li_check(li_rejected_without_change($temporary . '/invalid.zip', $site, $names), 'invalid ZIP is rejected without changing installed files');
    $savedApp = file_get_contents($site . '/assets/app.js');
    unlink($site . '/assets/app.js');
    file_put_contents($temporary . '/outside.txt', 'outside sentinel');
    symlink($temporary . '/outside.txt', $site . '/assets/app.js');
    li_check(li_rejected_without_change($package, $site, $names) && file_get_contents($temporary . '/outside.txt') === 'outside sentinel', 'destination symlink cannot redirect update writes outside the application');
    unlink($site . '/assets/app.js');
    file_put_contents($site . '/assets/app.js', $savedApp);

    // Fixture-only importer verifies setup orchestration without network or alternate production URLs.
    // The real application downloader remains fixed to its trusted GitHub source.
    $bootstrap = <<<'PHP'
<?php
namespace {
    define('APP_ROOT', __DIR__);
    define('APP_VAR', __DIR__ . '/var');
}
namespace App {
    final class GtfsImporter {
        public function import(?string $local = null): array {
            if ($local !== null) { throw new \RuntimeException('Unexpected local import argument'); }
            if (is_file(APP_VAR . '/fixture-import-fail')) { throw new \RuntimeException('Fixture timetable download unavailable'); }
            \li_database(APP_VAR . '/transit.sqlite');
            file_put_contents(APP_VAR . '/fixture-import-ran', 'yes');
            return ['counts' => ['stops' => 2, 'routes' => 1, 'trips' => 1, 'stop_times' => 2], 'validUntil' => date('Ymd', strtotime('+7 days'))];
        }
    }
}
PHP;
    file_put_contents($site . '/bootstrap.php', $bootstrap);
    unlink($site . '/var/transit.sqlite');
    $setup = pm_run_setup(false);
    li_check(is_file($site . '/var/fixture-import-ran') && $setup['complete'] === true && $setup['readiness']['ready'] === true, 'setup automatically imports the timetable and completes only after readiness succeeds');
    li_check(is_array($setup['steps']) && count($setup['steps']) >= 1 && count(array_filter($setup['steps'], static fn($step) => isset($step['step'], $step['status'], $step['message']))) === count($setup['steps']), 'automatic setup returns documented structured step results');
    $settings = li_snapshot($site, ['config.php', '.env', 'var/maintenance.php', '.git/HEAD']);
    $usableDatabase = hash_file('sha256', $site . '/var/transit.sqlite');
    file_put_contents($site . '/var/fixture-import-fail', 'yes');
    $failed = pm_run_setup(false);
    li_check($failed['complete'] === false && $failed['readiness']['ready'] === true && str_contains($failed['error'], 'Fixture timetable download unavailable') && hash_file('sha256', $site . '/var/transit.sqlite') === $usableDatabase, 'failed refresh is reported without losing the previous usable timetable');
    $savedFailure = pm_read_private($site . '/var/setup-status.php');
    li_check($savedFailure['complete'] === false && count(array_filter($savedFailure['steps'], static fn($step) => $step['status'] === 'failed')) === 1, 'failed setup records a structured retryable failure in protected storage');
    unlink($site . '/var/fixture-import-fail');
    $repeat = pm_run_setup(false);
    li_check($repeat['complete'] === true && $repeat['readiness']['ready'] === true && li_snapshot($site, ['config.php', '.env', 'var/maintenance.php', '.git/HEAD']) === $settings, 'automatic setup can run repeatedly while preserving local settings and authentication');

    echo PHP_EOL . $checks . ' loader installation/update checks passed. Local fixtures only; production downloads were not tested.' . PHP_EOL;
} finally {
    pm_remove_tree($temporary);
}
