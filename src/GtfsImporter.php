<?php
declare(strict_types=1);
namespace App;

use PDO;
use RuntimeException;
use ZipArchive;

/** A failed refresh never replaces the last usable timetable. */
final class GtfsImporter
{
    public const SOURCE = 'https://www.transperth.wa.gov.au/TimetablePDFs/GoogleTransit/Production/google_transit.zip';
    private const MAX_DOWNLOAD = 350_000_000;

    public function import(?string $localZip = null): array
    {
        if (!class_exists(ZipArchive::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('Enable PHP Zip and PDO SQLite in cPanel before importing.');
        }
        if (!is_dir(APP_VAR) && !mkdir(APP_VAR, 0700, true)) {
            throw new RuntimeException('The var directory must be writable by PHP.');
        }
        $lock = fopen(APP_VAR . '/import.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('A timetable import is already running.');
        }
        $staging = APP_VAR . '/import-' . bin2hex(random_bytes(8)) . '.sqlite';
        $download = null;
        $zip = new ZipArchive();
        $opened = false;
        $db = null;
        try {
            if ($localZip === null) {
                $download = tempnam(APP_VAR, 'gtfs-');
                $this->download($download);
                $localZip = $download;
            }
            if ($zip->open($localZip) !== true) {
                throw new RuntimeException('The timetable file is not a readable ZIP archive.');
            }
            $opened = true;
            if ($zip->numFiles > 100 || $zip->numFiles < 4) {
                throw new RuntimeException('Unexpected GTFS archive contents.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $total += (int) $entry['size'];
            }
            if ($total > 3_000_000_000) {
                throw new RuntimeException('GTFS archive exceeds the 3 GB expanded limit.');
            }
            $db = new PDO('sqlite:' . $staging, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF; PRAGMA temp_store=FILE;');
            $db->exec('CREATE TABLE meta (key TEXT PRIMARY KEY,value TEXT);
                CREATE TABLE stops (id TEXT PRIMARY KEY,name TEXT,lat REAL,lon REAL);
                CREATE TABLE routes (id TEXT PRIMARY KEY,name TEXT,type INTEGER);
                CREATE TABLE trips (id TEXT PRIMARY KEY,route_id TEXT,service_id TEXT,headsign TEXT,shape_id TEXT,start INTEGER,end INTEGER);
                CREATE TABLE stop_times (trip_id TEXT,stop_id TEXT,seq INTEGER,arrival INTEGER,departure INTEGER,pickup INTEGER,dropoff INTEGER,PRIMARY KEY(trip_id,seq));
                CREATE TABLE connections (trip_id TEXT,seq INTEGER,from_id TEXT,to_id TEXT,departure INTEGER,arrival INTEGER,pickup INTEGER,dropoff INTEGER);
                CREATE TABLE calendar (service_id TEXT PRIMARY KEY,mon INTEGER,tue INTEGER,wed INTEGER,thu INTEGER,fri INTEGER,sat INTEGER,sun INTEGER,start TEXT,end TEXT);
                CREATE TABLE exceptions (service_id TEXT,date TEXT,type INTEGER,PRIMARY KEY(service_id,date));
                CREATE TABLE shapes (id TEXT,seq INTEGER,lat REAL,lon REAL,PRIMARY KEY(id,seq));');
            $counts = [];
            $counts['stops'] = $this->rows($zip, 'stops.txt', true, $db,
                'INSERT INTO stops VALUES (?,?,?,?)', static fn($r) => [$r['stop_id'], $r['stop_name'], (float)$r['stop_lat'], (float)$r['stop_lon']]);
            $counts['routes'] = $this->rows($zip, 'routes.txt', true, $db,
                'INSERT INTO routes VALUES (?,?,?)', static fn($r) => [$r['route_id'], ($r['route_short_name'] ?? '') ?: ($r['route_long_name'] ?? $r['route_id']), (int)$r['route_type']]);
            $counts['trips'] = $this->rows($zip, 'trips.txt', true, $db,
                'INSERT INTO trips VALUES (?,?,?,?,?,NULL,NULL)', static fn($r) => [$r['trip_id'], $r['route_id'], $r['service_id'], $r['trip_headsign'] ?? '', $r['shape_id'] ?? '']);
            $counts['stop_times'] = $this->rows($zip, 'stop_times.txt', true, $db,
                'INSERT INTO stop_times VALUES (?,?,?,?,?,?,?)', static fn($r) => [$r['trip_id'], $r['stop_id'], (int)$r['stop_sequence'], self::seconds($r['arrival_time']), self::seconds($r['departure_time']), (int)($r['pickup_type'] ?? 0), (int)($r['drop_off_type'] ?? 0)]);
            $this->rows($zip, 'calendar.txt', false, $db,
                'INSERT INTO calendar VALUES (?,?,?,?,?,?,?,?,?,?)', static fn($r) => [$r['service_id'], (int)$r['monday'], (int)$r['tuesday'], (int)$r['wednesday'], (int)$r['thursday'], (int)$r['friday'], (int)$r['saturday'], (int)$r['sunday'], $r['start_date'], $r['end_date']]);
            $this->rows($zip, 'calendar_dates.txt', false, $db,
                'INSERT INTO exceptions VALUES (?,?,?)', static fn($r) => [$r['service_id'], $r['date'], (int)$r['exception_type']]);
            $counts['shapes'] = $this->rows($zip, 'shapes.txt', false, $db,
                'INSERT INTO shapes VALUES (?,?,?,?)', static fn($r) => [$r['shape_id'], (int)$r['shape_pt_sequence'], (float)$r['shape_pt_lat'], (float)$r['shape_pt_lon']]);
            foreach (['stops', 'routes', 'trips', 'stop_times'] as $required) {
                if (!$counts[$required]) {
                    throw new RuntimeException('GTFS has no ' . $required . '.');
                }
            }
            if (!(int)$db->query('SELECT (SELECT COUNT(*) FROM calendar)+(SELECT COUNT(*) FROM exceptions)')->fetchColumn()) {
                throw new RuntimeException('GTFS needs calendar.txt or calendar_dates.txt.');
            }
            // Stop sequences need not be consecutive; LEAD is safer than seq + 1.
            $db->exec('INSERT INTO connections SELECT trip_id,seq,stop_id,next_stop,departure,next_arrival,pickup,next_dropoff FROM
                (SELECT *, LEAD(stop_id) OVER w next_stop, LEAD(arrival) OVER w next_arrival, LEAD(dropoff) OVER w next_dropoff
                 FROM stop_times WINDOW w AS (PARTITION BY trip_id ORDER BY seq)) WHERE next_stop IS NOT NULL AND next_arrival>=departure;
                UPDATE trips SET start=(SELECT MIN(departure) FROM stop_times WHERE trip_id=trips.id),end=(SELECT MAX(arrival) FROM stop_times WHERE trip_id=trips.id);
                CREATE INDEX stops_location ON stops(lat,lon);
                CREATE INDEX trips_service ON trips(service_id,start,end);
                CREATE INDEX connections_time ON connections(departure);
                CREATE INDEX times_stop ON stop_times(stop_id,departure);
                CREATE INDEX exceptions_date ON exceptions(date);
                ANALYZE;');
            $bounds = $db->query("SELECT MIN(d),MAX(d) FROM (SELECT start d FROM calendar UNION ALL SELECT end FROM calendar UNION ALL SELECT date FROM exceptions WHERE type=1)")->fetch(PDO::FETCH_NUM);
            if (!$bounds[1] || $bounds[1] < date('Ymd')) {
                throw new RuntimeException('This feed has expired. Download a current Transperth timetable; the old database was retained.');
            }
            $meta = $db->prepare('INSERT INTO meta VALUES (?,?)');
            foreach (['imported_at' => date(DATE_ATOM), 'source' => $download ? self::SOURCE : 'Local GTFS ZIP', 'start_date' => $bounds[0], 'end_date' => $bounds[1]] as $key => $value) {
                $meta->execute([$key, $value]);
            }
            if ($db->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('Timetable database validation failed.');
            }
            $db = null;
            chmod($staging, 0600);
            if (!rename($staging, APP_VAR . '/transit.sqlite')) {
                throw new RuntimeException('Could not publish the imported database.');
            }
            return ['counts' => $counts, 'validFrom' => $bounds[0], 'validUntil' => $bounds[1], 'importedAt' => date(DATE_ATOM)];
        } finally {
            $db = null;
            if ($opened) { $zip->close(); }
            if (is_file($staging)) { unlink($staging); }
            if ($download && is_file($download)) { unlink($download); }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function rows(ZipArchive $zip, string $name, bool $required, PDO $db, string $sql, callable $map): int
    {
        $stream = $zip->getStream($name);
        if (!$stream) {
            if ($required) { throw new RuntimeException('Missing GTFS file: ' . $name); }
            return 0;
        }
        $header = fgetcsv($stream, 0, ',', '"', '');
        if (!$header) { throw new RuntimeException('Empty GTFS file: ' . $name); }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        // The official Perth shapes file contains spaces after its header commas.
        $header = array_map(static fn($column) => trim((string)$column), $header);
        $columns = [
            'stops.txt' => ['stop_id','stop_name','stop_lat','stop_lon'],
            'routes.txt' => ['route_id','route_type'],
            'trips.txt' => ['trip_id','route_id','service_id'],
            'stop_times.txt' => ['trip_id','stop_id','stop_sequence','arrival_time','departure_time'],
            'calendar.txt' => ['service_id','monday','tuesday','wednesday','thursday','friday','saturday','sunday','start_date','end_date'],
            'calendar_dates.txt' => ['service_id','date','exception_type'],
            'shapes.txt' => ['shape_id','shape_pt_lat','shape_pt_lon','shape_pt_sequence'],
        ];
        $missing = array_diff($columns[$name] ?? [], $header);
        if ($missing || count($header) !== count(array_unique($header))) {
            fclose($stream);
            throw new RuntimeException('Invalid GTFS columns in ' . $name . ($missing ? ': missing ' . implode(', ', $missing) : ': duplicate column names'));
        }
        $stmt = $db->prepare($sql);
        $count = 0;
        $db->beginTransaction();
        try {
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($row === [null]) { continue; }
                if (count($row) !== count($header)) { throw new RuntimeException('Malformed CSV in ' . $name); }
                $record = array_combine($header, $row);
                $stmt->execute($map($record));
                $count++;
            }
            $db->commit();
        } finally {
            if ($db->inTransaction()) { $db->rollBack(); }
            fclose($stream);
        }
        return $count;
    }

    public static function seconds(string $time): int
    {
        if (!preg_match('/^(\d{1,3}):([0-5]\d):([0-5]\d)$/', $time, $parts)) {
            throw new RuntimeException('GTFS has a missing/invalid stop time. Interpolate untimed stops before import.');
        }
        return (int)$parts[1] * 3600 + (int)$parts[2] * 60 + (int)$parts[3];
    }

    private function download(string $target): void
    {
        if (!function_exists('curl_init')) { throw new RuntimeException('Enable PHP cURL or import a local ZIP using the CLI.'); }
        $out = fopen($target, 'wb');
        $bytes = 0;
        $curl = curl_init(self::SOURCE);
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 240,
            CURLOPT_USERAGENT => 'PerthMove/0.1 GTFS timetable importer', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($out, &$bytes): int {
                $bytes += strlen($chunk);
                return $bytes <= self::MAX_DOWNLOAD ? fwrite($out, $chunk) : 0;
            }]);
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($out);
        if ($ok === false || $status !== 200) {
            throw new RuntimeException('Official Transperth download failed (HTTP ' . $status . '). Check hosting outbound HTTPS access; alternatively import an official local ZIP. ' . $error);
        }
    }
}
