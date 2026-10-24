<?php
/**
 * Perth Map cPanel installer and maintenance console.
 * Upload this file into an empty site folder, open it, and read the generated
 * var/setup-token.php in cPanel File Manager to obtain the maintenance key.
 * No key is ever returned by this page. The app and this loader update together.
 * Local CLI commands are documented by php loader.php --help.
 */
declare(strict_types=1);

const PM_LOADER_ROOT = __DIR__;
const PM_LOADER_PRIVATE = __DIR__ . '/var';
const PM_LOADER_GUARD = "<?php http_response_code(404); exit; ?>\n";
const PM_LOADER_ARCHIVE = 'https://codeload.github.com/efrgdgr0024345/map/zip/refs/heads/main';

function pm_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pm_requirements(): array
{
    return [
        'PHP 8.1 or newer' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'PDO SQLite extension' => extension_loaded('pdo_sqlite'),
        'ZIP extension' => class_exists('ZipArchive'),
        'cURL extension with HTTPS' => extension_loaded('curl') && in_array('https', curl_version()['protocols'], true),
        'Application folder writable' => is_writable(PM_LOADER_ROOT),
    ];
}

function pm_write_private(string $path, array $data): void
{
    if (is_link($path)) {
        throw new RuntimeException('A private configuration path is a symbolic link; remove it through cPanel before continuing.');
    }
    $temp = $path . '.' . bin2hex(random_bytes(8)) . '.php';
    $content = PM_LOADER_GUARD . json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    if (file_put_contents($temp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write private setup configuration. Check folder ownership in cPanel.');
    }
    chmod($temp, 0600);
    if (!rename($temp, $path)) {
        @unlink($temp);
        throw new RuntimeException('Cannot save private setup configuration.');
    }
}

function pm_read_private(string $path): array
{
    if (is_link($path) || !is_file($path)) {
        throw new RuntimeException('Private maintenance configuration is missing or unsafe.');
    }
    $content = file_get_contents($path);
    if ($content === false || !str_starts_with($content, PM_LOADER_GUARD)) {
        throw new RuntimeException('Private maintenance configuration is invalid. Restore it through cPanel.');
    }
    return json_decode(substr($content, strlen(PM_LOADER_GUARD)), true, 32, JSON_THROW_ON_ERROR);
}

function pm_prepare_private(): void
{
    if (is_link(PM_LOADER_PRIVATE) || (file_exists(PM_LOADER_PRIVATE) && !is_dir(PM_LOADER_PRIVATE))) {
        throw new RuntimeException('The var path must be a real directory.');
    }
    if (!is_dir(PM_LOADER_PRIVATE) && !mkdir(PM_LOADER_PRIVATE, 0700)) {
        throw new RuntimeException('Cannot create var. Make the application folder writable by your cPanel PHP user.');
    }
    if (!is_writable(PM_LOADER_PRIVATE)) {
        throw new RuntimeException('The var folder is not writable by PHP.');
    }
    $deny = PM_LOADER_PRIVATE . '/.htaccess';
    if (!file_exists($deny)) {
        file_put_contents($deny, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n", LOCK_EX);
    }
    if (!is_file($deny) || is_link($deny)) {
        throw new RuntimeException('Cannot protect the var folder with Apache access rules.');
    }
    $rules = file_get_contents($deny);
    if (!is_string($rules) || !preg_match('/Require\s+all\s+denied|Deny\s+from\s+all/i', $rules)
        || preg_match('/Require\s+all\s+granted|Allow\s+from\s+all/i', $rules)) {
        throw new RuntimeException('The existing var/.htaccess does not deny public access. Protect that private directory in cPanel before continuing.');
    }
    $index = PM_LOADER_PRIVATE . '/index.php';
    if (is_link($index)) {
        throw new RuntimeException('The private directory index must not be a symbolic link.');
    }
    if (!file_exists($index)) {
        file_put_contents($index, "<?php http_response_code(404); exit;\n", LOCK_EX);
    }
}

function pm_lock()
{
    $path = PM_LOADER_PRIVATE . '/loader.lock';
    if (is_link($path)) {
        throw new RuntimeException('Unsafe maintenance lock path.');
    }
    $handle = fopen($path, 'c');
    if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another installation, update, or import is running. Try again after it finishes.');
    }
    chmod($path, 0600);
    return $handle;
}

function pm_auth_source(): array
{
    $environment = getenv('PERTH_MAP_SETUP_TOKEN');
    if (is_string($environment) && $environment !== '') {
        if (strlen($environment) < 20) {
            throw new RuntimeException('PERTH_MAP_SETUP_TOKEN must contain at least 20 characters. Change it securely in your hosting settings.');
        }
        return ['plain' => $environment, 'version' => hash('sha256', $environment)];
    }
    $maintenance = PM_LOADER_PRIVATE . '/maintenance.php';
    if (is_file($maintenance)) {
        $data = pm_read_private($maintenance);
        if (!isset($data['password_hash']) || !is_string($data['password_hash'])) {
            throw new RuntimeException('Maintenance key configuration is invalid.');
        }
        return ['hash' => $data['password_hash'], 'version' => hash('sha256', $data['password_hash'])];
    }
    $setup = PM_LOADER_PRIVATE . '/setup-token.php';
    if (!is_file($setup)) {
        pm_write_private($setup, ['setup_token' => bin2hex(random_bytes(32))]);
    }
    $data = pm_read_private($setup);
    if (!isset($data['setup_token']) || !is_string($data['setup_token'])) {
        throw new RuntimeException('Setup token configuration is invalid.');
    }
    return ['plain' => $data['setup_token'], 'version' => hash('sha256', $data['setup_token'])];
}

function pm_safe_relative(string $path): bool
{
    if ($path === '' || strlen($path) > 240 || preg_match('/[\x00-\x1f\x7f\\\\:]/', $path) || $path[0] === '/') {
        return false;
    }
    foreach (explode('/', rtrim($path, '/')) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return false;
        }
    }
    return true;
}

function pm_preserved(string $path): bool
{
    $parts = explode('/', $path);
    $first = strtolower($parts[0]);
    return in_array($first, ['var', '.git', '.github', 'config.php', '.env', '.user.ini', 'php.ini'], true)
        || str_starts_with($first, '.env.');
}

function pm_assert_destination(string $relative): void
{
    $current = PM_LOADER_ROOT;
    foreach (explode('/', $relative) as $part) {
        $current .= '/' . $part;
        if (is_link($current)) {
            throw new RuntimeException('Update refused: a destination path contains a symbolic link.');
        }
    }
}

function pm_remove_tree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        if (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
        return;
    }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        pm_remove_tree($entry->getPathname());
    }
    @rmdir($path);
}

function pm_download_archive(string $target): void
{
    $stream = fopen($target, 'xb');
    if (!$stream) {
        throw new RuntimeException('Cannot create the temporary download.');
    }
    chmod($target, 0600);
    $bytes = 0;
    $curl = curl_init(PM_LOADER_ARCHIVE);
    curl_setopt_array($curl, [
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'PerthMap-cPanel-Loader/1.0',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($stream, &$bytes): int {
            $bytes += strlen($chunk);
            return $bytes > 50 * 1024 * 1024 ? 0 : (int) fwrite($stream, $chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    $curl = null;
    fclose($stream);
    if ($ok === false || $status !== 200) {
        throw new RuntimeException('GitHub download failed (HTTP ' . $status . '). ' . ($error !== '' ? $error : 'Confirm the project has been published to the configured GitHub repository.'));
    }
}

function pm_application_manifest(): array
{
    return ['loader.php', 'index.php', 'bootstrap.php', 'api.php', '.htaccess',
        'src/.htaccess', 'scripts/.htaccess',
        'src/GtfsImporter.php', 'src/Transit.php', 'src/Demo.php', 'src/Geo.php', 'src/Geocoder.php', 'src/NetworkStyle.php', 'src/RouteCache.php', 'src/VehicleMotion.php', 'src/WalkingRouter.php', 'src/StreetJourneys.php', 'src/Payphones.php', 'scripts/import-gtfs.php', 'scripts/build-route-cache.php',
        'assets/app.js', 'assets/style.css', 'assets/black-cat.svg', 'assets/mascot-head.webp',
        'assets/vendor/leaflet/leaflet.js', 'assets/vendor/leaflet/leaflet.css', 'assets/vendor/leaflet/LICENSE'];
}

/** Apply a previously obtained trusted package. No browser input reaches this path. */
function pm_apply_archive(string $archive): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Enable PHP ZIP in cPanel Select PHP Version before installing the application.');
    }
    $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5));
    $workspace = PM_LOADER_PRIVATE . '/update-' . $id;
    $backup = PM_LOADER_PRIVATE . '/backups/' . $id;
    foreach ([PM_LOADER_PRIVATE . '/backups', $workspace, $backup] as $dir) {
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700, true))) {
            throw new RuntimeException('Cannot create protected update and backup folders.');
        }
    }
    $zip = new ZipArchive();
    $changed = [];
    $created = [];
    $opened = false;
    try {
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('GitHub did not return a valid ZIP archive.');
        }
        $opened = true;
        if ($zip->numFiles > 10000) {
            throw new RuntimeException('Archive contains too many files.');
        }
        $files = [];
        $total = 0;
        $prefix = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            if (!pm_safe_relative($name)) {
                throw new RuntimeException('Archive contains an unsafe path.');
            }
            $segments = explode('/', $name, 2);
            $prefix = $prefix ?? $segments[0];
            if ($segments[0] !== $prefix) {
                throw new RuntimeException('Archive does not have one expected project root.');
            }
            $os = 0;
            $attributes = 0;
            $zip->getExternalAttributesIndex($i, $os, $attributes);
            $mode = ($attributes >> 16) & 0170000;
            if ($mode === 0120000 || ($mode !== 0 && $mode !== 0100000 && $mode !== 0040000)) {
                throw new RuntimeException('Archive contains a symbolic link or special file.');
            }
            if (!isset($segments[1]) || $segments[1] === '' || str_ends_with($name, '/')) {
                continue;
            }
            $relative = $segments[1];
            if (pm_preserved($relative)) {
                continue;
            }
            if (isset($files[$relative]) || $stat['size'] > 10 * 1024 * 1024) {
                throw new RuntimeException('Archive contains duplicate paths or an oversized application file.');
            }
            $total += $stat['size'];
            if ($total > 200 * 1024 * 1024) {
                throw new RuntimeException('Uncompressed application archive is too large.');
            }
            pm_assert_destination($relative);
            $files[$relative] = $i;
        }
        foreach (pm_application_manifest() as $required) {
            if (!isset($files[$required]) || $zip->statIndex($files[$required])['size'] === 0) {
                throw new RuntimeException('The GitHub archive does not contain the complete Perth Map application yet. Publish the project code before using install/update. No application files were changed.');
            }
        }
        // Replace the executing loader only after every other file is in place.
        $loaderIndex = $files['loader.php'];
        unset($files['loader.php']);
        $files['loader.php'] = $loaderIndex;
        // Fully stage and validate the archive before touching the installed app.
        foreach ($files as $relative => $index) {
            $staged = $workspace . '/staged/' . $relative;
            if (!is_dir(dirname($staged)) && !mkdir(dirname($staged), 0700, true)) {
                throw new RuntimeException('Cannot prepare the update staging folder.');
            }
            $input = $zip->getStream($zip->getNameIndex($index));
            $output = fopen($staged, 'xb');
            if (!$input || !$output || stream_copy_to_stream($input, $output) !== $zip->statIndex($index)['size']) {
                throw new RuntimeException('Could not safely extract an application file.');
            }
            fclose($input);
            fclose($output);
            chmod($staged, 0600);
        }
        $zip->close();
        $opened = false;
        // Back up every overwritten file before the first application write.
        foreach ($files as $relative => $index) {
            $destination = PM_LOADER_ROOT . '/' . $relative;
            if (file_exists($destination)) {
                if (!is_file($destination)) {
                    throw new RuntimeException('An application file conflicts with an existing directory.');
                }
                $saved = $backup . '/' . $relative;
                if ((!is_dir(dirname($saved)) && !mkdir(dirname($saved), 0700, true)) || !copy($destination, $saved)) {
                    throw new RuntimeException('Could not back up an existing file. No update was applied.');
                }
                chmod($saved, 0600);
                if (hash_file('sha256', $destination) !== hash_file('sha256', $saved)) {
                    throw new RuntimeException('Backup verification failed. No update was applied.');
                }
            }
        }
        foreach ($files as $relative => $index) {
            $destination = PM_LOADER_ROOT . '/' . $relative;
            if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true)) {
                throw new RuntimeException('Cannot create an application folder.');
            }
            if (file_exists($destination)) {
                $changed[] = $relative;
            } else {
                $created[] = $relative;
            }
            if (!rename($workspace . '/staged/' . $relative, $destination)) {
                throw new RuntimeException('Cannot replace an application file.');
            }
            chmod($destination, 0644);
            if (function_exists('opcache_invalidate') && str_ends_with($relative, '.php')) {
                @opcache_invalidate($destination, true);
            }
        }
        pm_write_private($backup . '/backup-info.php', ['created_at' => gmdate(DATE_ATOM), 'source' => PM_LOADER_ARCHIVE, 'overwritten' => $changed, 'new' => $created]);
        pm_write_private(PM_LOADER_PRIVATE . '/last-update.php', ['updated_at' => gmdate(DATE_ATOM), 'source' => PM_LOADER_ARCHIVE, 'backup' => 'var/backups/' . $id, 'files' => count($files)]);
        return ['files' => count($files), 'backup' => 'var/backups/' . $id, 'message' => 'Installed ' . count($files) . ' files, including the latest loader.php. Previous files are backed up in var/backups/' . $id . '. Your database, configuration, and maintenance credentials were preserved.'];
    } catch (Throwable $exception) {
        $rollbackFailed = false;
        foreach ($changed as $relative) {
            if (!copy($backup . '/' . $relative, PM_LOADER_ROOT . '/' . $relative)) {
                $rollbackFailed = true;
            } elseif (function_exists('opcache_invalidate') && str_ends_with($relative, '.php')) {
                @opcache_invalidate(PM_LOADER_ROOT . '/' . $relative, true);
            }
        }
        foreach ($created as $relative) {
            if (is_file(PM_LOADER_ROOT . '/' . $relative) && !unlink(PM_LOADER_ROOT . '/' . $relative)) {
                $rollbackFailed = true;
            }
        }
        if ($rollbackFailed) {
            throw new RuntimeException('Update failed and automatic restoration was incomplete. Restore files from var/backups/' . $id . ' in cPanel File Manager. Original error: ' . $exception->getMessage());
        }
        throw $exception;
    } finally {
        if ($opened) {
            $zip->close();
        }
        pm_remove_tree($workspace);
    }
}

/** Download only the fixed project repository; URLs and paths are never accepted from forms. */
function pm_install_archive(): array
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('Enable PHP cURL in cPanel before downloading the application.');
    }
    $archive = PM_LOADER_PRIVATE . '/download-' . bin2hex(random_bytes(8)) . '.zip';
    try {
        pm_download_archive($archive);
        return pm_apply_archive($archive);
    } finally {
        if (is_file($archive)) {
            unlink($archive);
        }
    }
}

/** Inspect installed files and the actual timetable, without executing application code. */
function pm_readiness(): array
{
    $result = ['ready' => false, 'application' => false, 'timetable' => false,
        'message' => 'Upload only loader.php, unlock maintenance, and choose Install & set up.'];
    foreach (pm_application_manifest() as $file) {
        $path = PM_LOADER_ROOT . '/' . $file;
        if (!is_file($path) || is_link($path) || filesize($path) === 0) {
            $result['message'] = 'Application installation is incomplete. Install the project from GitHub to continue.';
            return $result;
        }
    }
    $result['application'] = true;
    $result['message'] = 'Application files are installed. Timetable setup is not complete yet.';
    $database = PM_LOADER_PRIVATE . '/transit.sqlite';
    if (!is_file($database) || is_link($database) || !extension_loaded('pdo_sqlite')) {
        return $result;
    }
    try {
        $db = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only=ON');
        if ($db->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('The timetable database failed its integrity check.');
        }
        $meta = $db->query('SELECT key,value FROM meta')->fetchAll(PDO::FETCH_KEY_PAIR);
        $today = (new DateTimeImmutable('now', new DateTimeZone('Australia/Perth')))->format('Ymd');
        $start = (string) ($meta['start_date'] ?? '');
        $end = (string) ($meta['end_date'] ?? '');
        if (!preg_match('/^\d{8}$/', $start) || !preg_match('/^\d{8}$/', $end) || empty($meta['imported_at'])) {
            throw new RuntimeException('The timetable is missing import metadata.');
        }
        $result['validFrom'] = $start;
        $result['validUntil'] = $end;
        if ($start > $today || $end < $today) {
            throw new RuntimeException('The timetable does not cover today in Perth. Refresh it to complete setup.');
        }
        foreach (['stops', 'routes', 'trips', 'stop_times', 'connections'] as $table) {
            if (!$db->query('SELECT 1 FROM ' . $table . ' LIMIT 1')->fetchColumn()) {
                throw new RuntimeException('The timetable has no ' . $table . '.');
            }
        }
        $day = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'][(int) (new DateTimeImmutable('now', new DateTimeZone('Australia/Perth')))->format('N') - 1];
        $active = $db->prepare('SELECT 1 FROM trips WHERE service_id IN (
            SELECT service_id FROM calendar WHERE start<=:today AND end>=:today AND ' . $day . '=1
            UNION SELECT service_id FROM exceptions WHERE date=:today AND type=1)
            AND service_id NOT IN (SELECT service_id FROM exceptions WHERE date=:today AND type=2) LIMIT 1');
        $active->execute(['today' => $today]);
        if (!$active->fetchColumn()) {
            throw new RuntimeException('The timetable contains no active trips for today in Perth. Refresh the feed.');
        }
        $result['ready'] = true;
        $result['timetable'] = true;
        $result['message'] = 'Application and current Perth timetable verified. Valid through ' . substr($end, 0, 4) . '-' . substr($end, 4, 2) . '-' . substr($end, 6, 2) . '.';
        $result['importedAt'] = $meta['imported_at'];
    } catch (Throwable $exception) {
        $result['message'] = $exception->getMessage();
    }
    return $result;
}

/** Run the complete first install/update, or retry only its failed timetable stage. */
function pm_run_setup(bool $downloadApplication = true): array
{
    @set_time_limit(600);
    $result = ['complete' => false, 'startedAt' => gmdate(DATE_ATOM), 'steps' => [], 'readiness' => pm_readiness()];
    $save = static function () use (&$result): void {
        pm_write_private(PM_LOADER_PRIVATE . '/setup-status.php', $result);
    };
    try {
        pm_prepare_private();
        $save();
        $missing = array_keys(array_filter(pm_requirements(), static fn ($passed) => !$passed));
        if ($missing) {
            throw new RuntimeException('Hosting requirements are missing: ' . implode(', ', $missing) . '. Enable these in cPanel and retry.');
        }
        $result['steps'][] = ['step' => 'Hosting and private storage', 'status' => 'passed', 'message' => 'Required PHP extensions and writable protected storage verified.'];
        $save();
        if ($downloadApplication) {
            $installed = pm_install_archive();
            $result['steps'][] = ['step' => 'Download, backup and install', 'status' => 'passed', 'message' => $installed['message']];
            $result['backup'] = $installed['backup'];
            $save();
        }
        $readiness = pm_readiness();
        if (!$readiness['application']) {
            throw new RuntimeException('Application files are incomplete. Use Install & set up before retrying the timetable.');
        }
        $result['steps'][] = ['step' => 'Import official Perth timetable', 'status' => 'running', 'message' => 'Downloading and importing the official schedule. If hosting terminates this request, retry the timetable stage.'];
        $save();
        @set_time_limit(600);
        require_once PM_LOADER_ROOT . '/bootstrap.php';
        $import = (new \App\GtfsImporter())->import();
        $result['steps'][count($result['steps']) - 1] = ['step' => 'Import official Perth timetable', 'status' => 'passed', 'message' => 'Official timetable imported. ' . json_encode($import, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        $save();
        $result['readiness'] = pm_readiness();
        if (!$result['readiness']['ready']) {
            throw new RuntimeException($result['readiness']['message']);
        }
        $result['steps'][] = ['step' => 'Verify application readiness', 'status' => 'passed', 'message' => $result['readiness']['message']];
        try {
            $phones=pm_phone_cache(false);
            $result['steps'][]=['step'=>'Cache public telephone locations (optional)','status'=>'passed','optional'=>true,
                'message'=>$phones['locations'].' mapped locations saved from '.$phones['source'].'.'];
        } catch (Throwable $phoneError) {
            $result['steps'][]=['step'=>'Cache public telephone locations (optional)','status'=>'unavailable','optional'=>true,
                'message'=>'The map and timetable are ready. Public telephone locations can be retried with --refresh-phones.'];
        }
        $result['complete'] = true;
        $result['finishedAt'] = gmdate(DATE_ATOM);
        $save();
    } catch (Throwable $exception) {
        $result['error'] = $exception->getMessage();
        $result['readiness'] = pm_readiness();
        $last = count($result['steps']) - 1;
        if ($last >= 0 && $result['steps'][$last]['status'] === 'running') {
            $result['steps'][$last]['status'] = 'failed';
            $result['steps'][$last]['message'] = $exception->getMessage();
        } else {
            $result['steps'][] = ['step' => 'Setup', 'status' => 'failed', 'message' => $exception->getMessage()];
        }
        $result['finishedAt'] = gmdate(DATE_ATOM);
        try {
            $save();
        } catch (Throwable $saveError) {
            $result['error'] .= ' Setup status could not be saved: ' . $saveError->getMessage();
        }
    }
    return $result;
}

function pm_phone_cache(bool $force=true): array
{
    if (!is_file(PM_LOADER_ROOT.'/bootstrap.php') || !is_file(PM_LOADER_ROOT.'/src/Payphones.php')) {
        throw new RuntimeException('Install or update the application before refreshing public telephone locations.');
    }
    require_once PM_LOADER_ROOT.'/bootstrap.php';
    $data=$force ? \App\Payphones::refresh() : \App\Payphones::inBounds(\App\Payphones::COVERAGE);
    if (empty($data['updatedAt'])) { throw new RuntimeException('Public telephone locations could not be downloaded; retry later.'); }
    return ['complete'=>true,'locations'=>count($data['phones'] ?? []),'source'=>$data['source'] ?? 'Published telephone data','updatedAt'=>$data['updatedAt']];
}

function pm_cli_usage(): string
{
    return <<<'USAGE'
Perth Map — installer and maintenance

Usage: php loader.php <command>

  --help              Show this help (also the default without a command).
  --check             Check PHP extensions and writable application storage.
  --show-setup-token  Show the initial browser maintenance key in this terminal.
                      Available only before maintenance has been configured.
  --install           Download the app and loader, then import and verify the timetable.
  --update            Back up and update the app and loader, then refresh the timetable.
  --refresh           Import and verify the timetable without downloading application files.
  --refresh-phones    Download and cache published public telephone locations.

Install, update and refresh print a JSON result. Exit status is 0 only when
setup completes, 1 on a failed check/setup, or 2 for an invalid command.
Run commands as your cPanel account user from the folder containing loader.php.
The guarded var/setup-token.php is private data: read it in File Manager or
use --show-setup-token; executing that token file directly does not print it.
USAGE;
}

/** CLI access requires local account access; it never uses a browser session. */
function pm_cli(array $arguments): int
{
    if ($arguments === [] || $arguments === ['--help'] || $arguments === ['-h']) {
        fwrite(STDOUT, pm_cli_usage() . PHP_EOL);
        return 0;
    }
    if (count($arguments) !== 1 || !in_array($arguments[0], ['--check', '--show-setup-token', '--install', '--update', '--refresh', '--refresh-phones'], true)) {
        fwrite(STDERR, "Unknown or combined commands. Choose exactly one command.\n\n" . pm_cli_usage() . PHP_EOL);
        return 2;
    }
    $command = $arguments[0];
    if ($command === '--check') {
        $requirements = pm_requirements();
        foreach ($requirements as $label => $passed) {
            fwrite(STDOUT, ($passed ? '[OK] ' : '[MISSING] ') . $label . PHP_EOL);
        }
        return in_array(false, $requirements, true) ? 1 : 0;
    }
    if ($command === '--show-setup-token') {
        try {
            pm_prepare_private();
            $lock = pm_lock();
            try {
                if (file_exists(PM_LOADER_PRIVATE . '/maintenance.php') || is_link(PM_LOADER_PRIVATE . '/maintenance.php')) {
                    throw new RuntimeException('No setup token is available: maintenance is already configured and only a password hash is stored. Use your saved maintenance key; if it is lost, follow the recovery instructions on loader.php.');
                }
                $environment = getenv('PERTH_MAP_SETUP_TOKEN');
                if (is_string($environment) && $environment !== '') {
                    throw new RuntimeException('No generated setup token is available: the maintenance key is supplied by PERTH_MAP_SETUP_TOKEN in your hosting settings. Use your configured key.');
                }
                $source = pm_auth_source();
                if (!isset($source['plain'])) {
                    throw new RuntimeException('No plaintext setup token is stored. Use your saved maintenance key.');
                }
                $token = $source['plain'];
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            // The operator explicitly requested this value in their own terminal.
            fwrite(STDOUT, $token . PHP_EOL);
            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }
    try {
        pm_prepare_private();
        $lock = pm_lock();
        try {
            $result = $command==='--refresh-phones' ? pm_phone_cache(true) : pm_run_setup($command !== '--refresh');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } catch (Throwable $exception) {
        $result = ['complete' => false, 'error' => $exception->getMessage(), 'steps' => []];
    }
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    return !empty($result['complete']) ? 0 : 1;
}

if (defined('PM_LOADER_LIBRARY_ONLY') && PM_LOADER_LIBRARY_ONLY === true) {
    return;
}

if (PHP_SAPI === 'cli') {
    exit(pm_cli(array_slice($argv, 1)));
}

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
session_name('perth_map_maintenance');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Strict', 'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/']);
$sessionStarted = session_start();
$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(32));
$notice = '';
$error = '';
$fatal = false;
$authenticated = false;
$environmentKey = false;
$setupResult = null;
try {
    if (!$sessionStarted) {
        throw new RuntimeException('PHP session storage is unavailable. Ask your host to configure a writable session.save_path before using maintenance.');
    }
    pm_prepare_private();
    $lock = pm_lock();
    $source = pm_auth_source();
    flock($lock, LOCK_UN);
    fclose($lock);
    $environmentKey = (getenv('PERTH_MAP_SETUP_TOKEN') ?: '') !== '';
    $authenticated = ($_SESSION['maintenance_version'] ?? '') === $source['version'] && (int) ($_SESSION['maintenance_seen'] ?? 0) > time() - 3600;
    if ($authenticated) {
        $_SESSION['maintenance_seen'] = time();
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
            throw new RuntimeException('This form expired. Reload the page and try again.');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'login') {
            $attempts = $_SESSION['login_attempts'] ?? [];
            $attempts = array_filter($attempts, static fn ($time) => $time > time() - 60);
            if (count($attempts) >= 8) {
                throw new RuntimeException('Too many attempts in this browser. Wait one minute and try again.');
            }
            $attempts[] = time();
            $_SESSION['login_attempts'] = $attempts;
            $key = is_string($_POST['key'] ?? null) ? $_POST['key'] : '';
            $valid = isset($source['hash']) ? password_verify($key, $source['hash']) : hash_equals($source['plain'], $key);
            if (!$valid) {
                throw new RuntimeException('The maintenance key is incorrect. Read the setup token through cPanel File Manager or use your saved maintenance key.');
            }
            $lock = pm_lock();
            if (!is_file(PM_LOADER_PRIVATE . '/maintenance.php')) {
                pm_write_private(PM_LOADER_PRIVATE . '/maintenance.php', ['password_hash' => password_hash($key, PASSWORD_DEFAULT)]);
            }
            if (is_file(PM_LOADER_PRIVATE . '/setup-token.php')) {
                unlink(PM_LOADER_PRIVATE . '/setup-token.php');
            }
            $source = pm_auth_source();
            flock($lock, LOCK_UN);
            fclose($lock);
            session_regenerate_id(true);
            $_SESSION['maintenance_version'] = $source['version'];
            $_SESSION['maintenance_seen'] = time();
            $_SESSION['login_attempts'] = [];
            $authenticated = true;
            $notice = 'Maintenance unlocked for this browser for one hour of inactivity. Save your key securely; the one-time setup token file has been removed.';
        } elseif ($action === 'logout') {
            unset($_SESSION['maintenance_version'], $_SESSION['maintenance_seen']);
            session_regenerate_id(true);
            $authenticated = false;
            $notice = 'Maintenance locked.';
        } else {
            if (!$authenticated) {
                throw new RuntimeException('Unlock maintenance before making changes.');
            }
            if ($action === 'install' || $action === 'import') {
                if ($action === 'install' && ($_POST['confirm'] ?? '') !== 'yes') {
                    throw new RuntimeException('Confirm replacement of application files before installing or updating.');
                }
                $lock = pm_lock();
                try {
                    $setupResult = pm_run_setup($action === 'install');
                    if ($setupResult['complete']) {
                        $notice = 'Setup complete. The application and a current Perth timetable have been verified.';
                    } else {
                        $error = 'Setup incomplete. ' . ($setupResult['error'] ?? 'The timetable still needs to be prepared.');
                    }
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            } elseif ($action === 'phones') {
                $lock=pm_lock();
                try {
                    $phones=pm_phone_cache(true);
                    $notice='Public telephone locations refreshed: '.$phones['locations'].' locations from '.$phones['source'].'.';
                } finally {
                    flock($lock,LOCK_UN); fclose($lock);
                }
            } elseif ($action === 'prepare') {
                pm_prepare_private();
                $notice = 'Private storage is prepared. Choose Install & set up to download the application and prepare its timetable automatically.';
            } elseif ($action === 'rotate') {
                if ($environmentKey) {
                    throw new RuntimeException('Change PERTH_MAP_SETUP_TOKEN in your hosting settings while it is configured.');
                }
                $newKey = is_string($_POST['new_key'] ?? null) ? $_POST['new_key'] : '';
                if (strlen($newKey) < 20 || strlen($newKey) > 512) {
                    throw new RuntimeException('Use a new maintenance key between 20 and 512 characters.');
                }
                $lock = pm_lock();
                try {
                    pm_write_private(PM_LOADER_PRIVATE . '/maintenance.php', ['password_hash' => password_hash($newKey, PASSWORD_DEFAULT)]);
                    $source = pm_auth_source();
                    $_SESSION['maintenance_version'] = $source['version'];
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
                $notice = 'Maintenance key changed. Other browser sessions are now locked.';
            } else {
                throw new RuntimeException('Unknown maintenance action.');
            }
        }
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
    $fatal = !isset($source);
}
$requirements = pm_requirements();
$readiness = pm_readiness();
$installed = $readiness['application'];
if ($authenticated && $setupResult === null && is_file(PM_LOADER_PRIVATE . '/setup-status.php')) {
    try {
        $setupResult = pm_read_private(PM_LOADER_PRIVATE . '/setup-status.php');
    } catch (Throwable $exception) {
        $error = 'Saved setup status could not be read: ' . $exception->getMessage();
    }
}
$setupComplete = $readiness['ready'];
$lastAttemptComplete = is_array($setupResult) && ($setupResult['complete'] ?? false);
$csrf = pm_escape($_SESSION['csrf']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Perth Map · Setup</title>
<style>
:root{color-scheme:light;--ink:#16201b;--muted:#68736c;--line:#dde4dc;--lime:#c6f569}*{box-sizing:border-box}body{margin:0;background:#f3f5f0;color:var(--ink);font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}main{max-width:860px;margin:0 auto;padding:48px 22px 70px}header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:38px}.brand{display:flex;align-items:center;gap:12px;font-size:20px;font-weight:750;letter-spacing:-.7px}.mark{display:grid;place-items:center;background:var(--ink);color:var(--lime);width:40px;height:40px;border-radius:13px;font-size:24px}.tag{font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted)}h1{font-size:40px;line-height:1.1;letter-spacing:-1.8px;margin:0 0 14px}h2{font-size:19px;letter-spacing:-.4px;margin:0 0 10px}p{margin:0 0 16px}.intro{color:var(--muted);max-width:630px;margin-bottom:28px}.card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:26px;margin:18px 0}.checks{display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;padding:0;list-style:none;margin:20px 0 0}.checks li{display:flex;gap:9px;align-items:center}.ok,.missing{font-size:12px;font-weight:700}.ok{color:#39712b}.missing{color:#a73428}label{display:block;font-size:13px;font-weight:600;margin:15px 0 7px}input[type=password]{border:1px solid #bdc9bd;border-radius:10px;padding:13px 14px;font:inherit;width:100%;max-width:460px;background:#fafcf8}input:focus{outline:2px solid #85b737;outline-offset:2px}button,.button{display:inline-block;border:0;border-radius:11px;padding:13px 20px;background:var(--ink);color:#fff;font:600 14px/1.4 inherit;cursor:pointer;text-decoration:none}button:hover,.button:hover{background:#2b3b30}.primary{background:var(--lime);color:var(--ink)}.primary:hover{background:#b7e657}.quiet{background:#ecf0e8;color:var(--ink)}.quiet:hover{background:#dee7d8}.row{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:18px}.note{font-size:13px;color:var(--muted)}.message{border-radius:12px;padding:16px 18px;overflow-wrap:anywhere}.success{background:#e3f3d8;border:1px solid #bad7a5}.error{background:#fff0e9;border:1px solid #e8c5b7}code{font-size:12px;background:#f1f4ee;padding:3px 5px;border-radius:4px;overflow-wrap:anywhere}pre{white-space:pre-wrap;background:#f1f4ee;padding:14px;border-radius:10px;font-size:12px}a{color:inherit}.confirm{display:flex;gap:10px;align-items:flex-start;font-weight:400;margin-top:20px}.confirm input{margin-top:5px;accent-color:#29472e}details summary{cursor:pointer;font-weight:600}details p{margin-top:14px}.status{font-size:12px;background:#e8eee2;border-radius:20px;padding:6px 11px}.setup-steps{padding-left:22px}.setup-steps li{padding:8px 0}.setup-steps p{margin:5px 0 4px}.divider{height:1px;background:var(--line);margin:22px 0}@media(max-width:550px){main{padding-top:28px}.checks{grid-template-columns:1fr}h1{font-size:34px}.card{padding:21px}}
</style>
</head>
<body><main>
<header><div class="brand"><span class="mark" aria-hidden="true">↗</span> Perth Map</div><span class="tag">Setup & maintenance</span></header>
<h1>A good place to start.</h1>
<p class="intro">Upload this file once. Install & set up downloads the complete map and prepares its Perth timetable. Return here to update the application and this loader together.</p>
<?php if ($notice !== ''): ?><p class="message success" role="status"><?= pm_escape($notice) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="message error" role="alert"><?= pm_escape($error) ?></p><?php endif; ?>
<section class="card"><h2>Hosting requirements</h2><p class="note">Choose PHP 8.1 or newer in cPanel and enable the extensions below. Apache must honour <code>.htaccess</code> so timetable databases and backups stay private. Use HTTPS for this console and phone location.</p><ul class="checks">
<?php foreach ($requirements as $label => $passed): ?><li><span class="<?= $passed ? 'ok' : 'missing' ?>"><?= $passed ? '✓' : '!' ?></span><?= pm_escape($label) ?></li><?php endforeach; ?>
</ul></section>
<?php if (!$authenticated && !$fatal): ?>
<section class="card"><h2>Unlock maintenance</h2><p>This console requires a maintenance key before it can change your site.</p>
<?php if ($environmentKey): ?><p class="note">Use the key configured in your hosting environment as <code>PERTH_MAP_SETUP_TOKEN</code>.</p><?php elseif (is_file(PM_LOADER_PRIVATE . '/setup-token.php')): ?><p class="note">In <strong>cPanel → File Manager</strong>, open <code>var/setup-token.php</code> beside this file and read the <code>setup_token</code> value. Alternatively, open cPanel Terminal in this folder and run <code>php loader.php --show-setup-token</code>. Paste that key below and save it in your password manager. The token file is protected data: opening it over HTTP returns 404, and executing the token file directly prints nothing. It is removed after your first successful login.</p><?php else: ?><p class="note">Use your saved maintenance key. If it is lost, use cPanel File Manager to remove <code>var/maintenance.php</code>, then reload this page to generate a new private setup token. Existing transport data is preserved.</p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="login"><label for="key">Maintenance key</label><input id="key" type="password" name="key" required autocomplete="current-password" maxlength="512"><div class="row"><button class="primary" type="submit">Unlock setup ↗</button></div></form></section>
<?php elseif ($authenticated): ?>
<section class="card"><span class="status"><?= $setupComplete ? 'Map ready · current timetable verified' : ($installed ? 'Setup incomplete · timetable needs attention' : 'Ready to install from one file') ?></span><h2 style="margin-top:16px"><?= $installed ? 'Update the complete system' : 'Install & set up your map' ?></h2><p><?= $installed ? 'Back up and update the map and loader together, then automatically refresh the official Perth timetable.' : 'This one action downloads all project files, sets up protected storage, and imports the official Perth timetable. You do not need to upload the application separately.' ?></p><p class="note">Code comes from <a href="https://github.com/efrgdgr0024345/map" rel="noreferrer">efrgdgr0024345/map</a>, branch <code>main</code>, over verified HTTPS. Your database, local <code>config.php</code>, environment files, and maintenance credentials are preserved. Replaced files, including the loader, are backed up before installation.</p><p class="note">The application code must already be published to that GitHub repository. A README-only repository is refused without changing application files. Your hosting needs outbound HTTPS access to <code>codeload.github.com</code> and the official Transperth feed.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="install"><label class="confirm"><input type="checkbox" name="confirm" value="yes" required><span>Install the published GitHub version of the app and loader, with a backup of replaced files. I have saved any local code changes separately.</span></label><div class="row"><button type="submit" class="primary"><?= $installed ? 'Back up, update & set up' : 'Install & set up' ?> ↗</button><?php if ($setupComplete): ?><a class="button quiet" href="./">Open map</a><?php endif; ?></div></form>
</section>
<?php if (is_array($setupResult) && !empty($setupResult['steps'])): ?>
<section class="card"><h2>Latest setup attempt</h2><p class="note"><?= $lastAttemptComplete ? 'This setup attempt completed successfully. Current readiness is checked separately against the installed files and timetable.' : ($setupComplete ? 'The latest attempt did not complete. Your installed map and its existing current timetable remain usable; retry the failed refresh below.' : 'This setup attempt is incomplete. Finish the failed step and verify the current timetable to make the map ready.') ?></p>
<ol class="setup-steps">
<?php foreach ($setupResult['steps'] as $step): ?>
<li><strong><?= pm_escape((string) ($step['step'] ?? 'Setup')) ?></strong><span class="<?= ($step['status'] ?? '') === 'passed' ? 'ok' : 'missing' ?>"> · <?= pm_escape((string) ($step['status'] ?? 'unknown')) ?></span><p class="note"><?= pm_escape((string) ($step['message'] ?? '')) ?></p></li>
<?php endforeach; ?>
</ol>
<?php if (!$lastAttemptComplete): ?><p class="note">If your host stopped a request during the import, the installed app stays in place and an existing timetable is retained. Retry timetable setup below without downloading the app again.</p><?php endif; ?>
</section>
<?php endif; ?>
<?php if ($installed): ?>
<section class="card"><h2><?= $setupComplete ? 'Timetable maintenance' : 'Finish timetable setup' ?></h2><p><?= pm_escape($readiness['message']) ?></p><p class="note">Refresh imports the official schedule and verifies that it covers today in Perth. Scheduled vehicle positions are estimates; importing the timetable does not enable live GPS tracking.</p><p class="note">Allow several minutes and free disk space for the feed, a temporary database, and existing data. The console requests up to 600 seconds, but your hosting provider may impose a shorter limit. A failed import retains the previous database.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="import"><div class="row"><button type="submit"><?= $setupComplete ? 'Refresh timetable' : 'Retry timetable setup' ?></button><?php if ($setupComplete): ?><a class="button quiet" href="./">Open map</a><?php endif; ?></div></form>
<details style="margin-top:20px"><summary>Large-feed troubleshooting</summary><p class="note">If your hosting request limit prevents the browser import from finishing, ask your provider to raise it. cPanel Terminal or a scheduled task can also run the same importer without a browser timeout:</p><pre>cd <?= pm_escape(PM_LOADER_ROOT) ?>
php loader.php --refresh</pre><p class="note">Return here afterwards; readiness is checked against the actual database, not the presence of application files.</p></details>
</section>
<?php endif; ?>
<?php if ($installed): ?><section class="card"><h2>Public telephone locations</h2><p class="note">The optional phone layer uses a published location register. Refresh its server cache here if the map reports unavailable data.</p><form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="phones"><button type="submit">Refresh public phone locations</button></form></section><?php endif; ?>
<section class="card"><details><summary>Maintenance key & storage</summary><p class="note">Keep <code>var</code> private and writable by your PHP user. Do not grant world-write permissions. Download a backup through cPanel File Manager before clearing old backups. Authenticated access expires after one hour of inactivity.</p>
<?php if (!$environmentKey): ?><form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="rotate"><label for="new-key">New maintenance key · at least 20 characters</label><input id="new-key" type="password" name="new_key" required minlength="20" maxlength="512" autocomplete="new-password"><div class="row"><button class="quiet" type="submit">Change key</button></div></form><?php else: ?><p class="note">Change your key through the <code>PERTH_MAP_SETUP_TOKEN</code> hosting setting.</p><?php endif; ?>
</details><div class="divider"></div><form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="logout"><button type="submit" class="quiet">Lock maintenance</button></form></section>
<?php endif; ?>
<p class="note">For cPanel / Apache hosting. PHP built-in servers and Nginx need separate rules denying HTTP access to <code>var/</code>. Loader installation does not publish your local code to GitHub.</p>
</main></body></html>
