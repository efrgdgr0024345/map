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
    private ?\Closure $progress = null;
    private array $progressTimes = [];

    /** The optional observer receives public import counters, never credentials or CSV records. */
    public function import(?string $localZip = null, ?callable $progress = null): array
    {
        $this->progress = $progress === null ? null : \Closure::fromCallable($progress);
        $this->progressTimes = [];
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
            $this->notify('archive', 'gtfs', 'Checking timetable archive', 'Checking the ZIP and its expanded size.', [], true);
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
            $this->notify('archive', 'gtfs', 'Timetable archive checked', 'The timetable archive passed its size and structure checks.', ['status'=>'complete','completed'=>1,'total'=>1,'unit'=>'archive','percent'=>100], true);
            $this->notify('prepare', 'database', 'Preparing timetable database', 'Creating a separate database; the installed timetable remains available.', [], true);
            $db = new PDO('sqlite:' . $staging, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF; PRAGMA temp_store=FILE;');
            $db->exec('CREATE TABLE meta (key TEXT PRIMARY KEY,value TEXT);
                CREATE TABLE stops (id TEXT PRIMARY KEY,name TEXT,lat REAL,lon REAL);
                CREATE TABLE stop_details (stop_id TEXT PRIMARY KEY,parent_station TEXT,location_type INTEGER,description TEXT,platform_code TEXT,supported_modes TEXT);
                CREATE TABLE transfers (from_stop_id TEXT,to_stop_id TEXT,transfer_type INTEGER,min_transfer_time INTEGER,from_route_id TEXT,to_route_id TEXT,from_trip_id TEXT,to_trip_id TEXT);
                CREATE TABLE routes (id TEXT PRIMARY KEY,name TEXT,type INTEGER);
                CREATE TABLE route_styles (route_id TEXT PRIMARY KEY,color TEXT,text_color TEXT);
                CREATE TABLE trips (id TEXT PRIMARY KEY,route_id TEXT,service_id TEXT,headsign TEXT,shape_id TEXT,start INTEGER,end INTEGER);
                CREATE TABLE stop_times (trip_id TEXT,stop_id TEXT,seq INTEGER,arrival INTEGER,departure INTEGER,pickup INTEGER,dropoff INTEGER,PRIMARY KEY(trip_id,seq));
                CREATE TABLE connections (trip_id TEXT,seq INTEGER,from_id TEXT,to_id TEXT,departure INTEGER,arrival INTEGER,pickup INTEGER,dropoff INTEGER);
                CREATE TABLE calendar (service_id TEXT PRIMARY KEY,mon INTEGER,tue INTEGER,wed INTEGER,thu INTEGER,fri INTEGER,sat INTEGER,sun INTEGER,start TEXT,end TEXT);
                CREATE TABLE exceptions (service_id TEXT,date TEXT,type INTEGER,PRIMARY KEY(service_id,date));
                CREATE TABLE shapes (id TEXT,seq INTEGER,lat REAL,lon REAL,PRIMARY KEY(id,seq));');
            $this->notify('prepare', 'database', 'Timetable database prepared', 'The separate database is ready for timetable records.', ['status'=>'complete','percent'=>100], true);
            $counts = [];
            $counts['stops'] = $this->rows($zip, 'stops.txt', true, $db,
                'INSERT INTO stops VALUES (?,?,?,?)', static fn($r) => [$r['stop_id'], $r['stop_name'], (float)$r['stop_lat'], (float)$r['stop_lon']]);
            $counts['stop_details'] = $this->rows($zip, 'stops.txt', true, $db,
                'INSERT INTO stop_details VALUES (?,?,?,?,?,?)', static fn($r) => [
                    $r['stop_id'], $r['parent_station'] ?? '', self::integer($r['location_type'] ?? '', 0, 4, 'stops.txt location_type', 0),
                    $r['stop_desc'] ?? '', $r['platform_code'] ?? '', $r['supported_modes'] ?? '',
                ]);
            $counts['routes'] = $this->rows($zip, 'routes.txt', true, $db,
                'INSERT INTO routes VALUES (?,?,?)', static fn($r) => [$r['route_id'], ($r['route_short_name'] ?? '') ?: ($r['route_long_name'] ?? $r['route_id']), (int)$r['route_type']]);
            $this->rows($zip, 'routes.txt', true, $db, 'INSERT INTO route_styles VALUES (?,?,?)',
                static fn($r) => [$r['route_id'], $r['route_color'] ?? '', $r['route_text_color'] ?? '']);
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
            $counts['transfers'] = $this->rows($zip, 'transfers.txt', false, $db,
                'INSERT INTO transfers VALUES (?,?,?,?,?,?,?,?)', static function ($r): array {
                    $type=self::integer($r['transfer_type'], 0, 5, 'transfers.txt transfer_type');
                    $minimumValue=trim($r['min_transfer_time'] ?? '');
                    $minimum=$minimumValue==='' ? null : self::integer($minimumValue, 0, PHP_INT_MAX, 'transfers.txt min_transfer_time');
                    $from=$r['from_stop_id'] ?? ''; $to=$r['to_stop_id'] ?? '';
                    $fromTrip=$r['from_trip_id'] ?? ''; $toTrip=$r['to_trip_id'] ?? '';
                    if ($type===2 && $minimum===null) { throw new RuntimeException('GTFS transfers.txt type 2 needs min_transfer_time.'); }
                    if (($type<=3 && ($from==='' || $to==='')) || ($type>=4 && ($fromTrip==='' || $toTrip===''))) {
                        throw new RuntimeException('GTFS transfers.txt needs valid stop endpoints, or both trip endpoints for in-seat rules.');
                    }
                    return [$from, $to, $type, $minimum, $r['from_route_id'] ?? '', $r['to_route_id'] ?? '', $fromTrip, $toTrip];
                });
            $this->notify('validate', 'station-references', 'Checking station and transfer references', 'Checking parent stations, transfer stops and service references.', [], true);
            $this->validateStationReferences($db);
            foreach (['stops', 'routes', 'trips', 'stop_times'] as $required) {
                if (!$counts[$required]) {
                    throw new RuntimeException('GTFS has no ' . $required . '.');
                }
            }
            if (!(int)$db->query('SELECT (SELECT COUNT(*) FROM calendar)+(SELECT COUNT(*) FROM exceptions)')->fetchColumn()) {
                throw new RuntimeException('GTFS needs calendar.txt or calendar_dates.txt.');
            }
            $this->notify('validate', 'station-references', 'Station and transfer references checked', 'Required timetable records and station references are valid.', ['status'=>'complete','percent'=>100], true);
            // Stop sequences need not be consecutive; LEAD is safer than seq + 1.
            $this->notify('connections', 'segments', 'Building journey connections', 'Linking consecutive stops into timetable journey segments.', [], true);
            $connections=$db->exec('INSERT INTO connections SELECT trip_id,seq,stop_id,next_stop,departure,next_arrival,pickup,next_dropoff FROM
                (SELECT *, LEAD(stop_id) OVER w next_stop, LEAD(arrival) OVER w next_arrival, LEAD(dropoff) OVER w next_dropoff
                 FROM stop_times WINDOW w AS (PARTITION BY trip_id ORDER BY seq)) WHERE next_stop IS NOT NULL AND next_arrival>=departure;');
            $this->notify('connections', 'segments', 'Journey connections built', number_format((int)$connections).' journey segments prepared.', ['status'=>'complete','completed'=>(int)$connections,'total'=>(int)$connections,'unit'=>'connections','percent'=>100], true);
            $this->notify('connections', 'trip-times', 'Calculating trip times', 'Finding the first departure and final arrival for each trip.', [], true);
            $db->exec('UPDATE trips SET start=(SELECT MIN(departure) FROM stop_times WHERE trip_id=trips.id),end=(SELECT MAX(arrival) FROM stop_times WHERE trip_id=trips.id);');
            $this->notify('connections', 'trip-times', 'Trip times calculated', number_format($counts['trips']).' trip time ranges prepared.', ['status'=>'complete','completed'=>$counts['trips'],'total'=>$counts['trips'],'unit'=>'trips','percent'=>100], true);
            $indexes=[
                'stops_location'=>['stops(lat,lon)','stop locations'],
                'stop_details_parent'=>['stop_details(parent_station)','station groups'],
                'transfers_stops'=>['transfers(from_stop_id,to_stop_id)','transfer origins'],
                'transfers_reverse'=>['transfers(to_stop_id,from_stop_id)','transfer destinations'],
                'trips_service'=>['trips(service_id,start,end)','active services'],
                'connections_time'=>['connections(departure)','journey departures'],
                'times_stop'=>['stop_times(stop_id,departure)','stop departure boards'],
                'exceptions_date'=>['exceptions(date)','calendar exceptions'],
            ];
            $indexed=0;
            foreach ($indexes as $name=>[$definition,$description]) {
                $this->notify('index', 'indexes', 'Indexing timetable searches', 'Building the index for '.$description.'.', ['completed'=>$indexed,'total'=>count($indexes),'unit'=>'indexes','percent'=>(int)floor($indexed/count($indexes)*100)], true);
                $db->exec('CREATE INDEX '.$name.' ON '.$definition);
                $indexed++;
            }
            $this->notify('index', 'indexes', 'Timetable indexes built', 'All timetable search indexes are ready.', ['status'=>'complete','completed'=>$indexed,'total'=>$indexed,'unit'=>'indexes','percent'=>100], true);
            $this->notify('index', 'statistics', 'Optimizing timetable searches', 'Calculating database search statistics.', [], true);
            $db->exec('ANALYZE;');
            $this->notify('index', 'statistics', 'Timetable searches optimized', 'Database search statistics are ready.', ['status'=>'complete','percent'=>100], true);
            $this->notify('validate', 'timetable', 'Validating the new timetable', 'Checking service dates and database integrity before publication.', [], true);
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
            $this->notify('validate', 'timetable', 'New timetable validated', 'Service dates and database integrity checks passed.', ['status'=>'complete','percent'=>100], true);
            $db = null;
            chmod($staging, 0600);
            $this->notify('publish', 'database', 'Publishing the new timetable', 'Replacing the installed timetable with the validated database.', [], true);
            if (!rename($staging, APP_VAR . '/transit.sqlite')) {
                throw new RuntimeException('Could not publish the imported database.');
            }
            $this->notify('publish', 'database', 'New timetable published', 'The validated timetable is now installed.', ['status'=>'complete','percent'=>100], true);
            $result=['counts' => $counts, 'validFrom' => $bounds[0], 'validUntil' => $bounds[1], 'importedAt' => date(DATE_ATOM)];
            // Geometry is persistent; positions and personal journeys are never cached here.
            // A cache failure must not discard an already validated, published timetable.
            try {
                $this->notify('route-cache', 'geometry', 'Building cached map routes', 'Preparing route geometry so moving the map does not download it again.', [], true);
                $result['routeCache']=RouteCache::build();
                $lines=(int)($result['routeCache']['lines'] ?? 0);
                $this->notify('route-cache', 'geometry', 'Cached map routes ready', number_format($lines).' route variants cached.', ['status'=>'complete','completed'=>$lines,'total'=>$lines,'unit'=>'route variants','percent'=>100], true);
            } catch (\Throwable $cacheError) {
                $result['cacheWarning']='Timetable imported; route cache will retry on its next request. '.$cacheError->getMessage();
                $this->notify('route-cache', 'geometry', 'Route cache will retry', 'The timetable is installed; route geometry will retry on its next request.', ['status'=>'warning'], true);
            }
            $this->notify('complete', 'timetable', 'Timetable ready', 'The timetable import is complete.', ['status'=>'complete','percent'=>100], true);
            return $result;
        } catch (\Throwable $error) {
            $this->notify('failed', 'timetable', 'Timetable import failed', 'The import stopped; the previously installed timetable was retained.', ['status'=>'failed'], true);
            throw $error;
        } finally {
            $db = null;
            if ($opened) { $zip->close(); }
            if (is_file($staging)) { unlink($staging); }
            if ($download && is_file($download)) { unlink($download); }
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->progress=null;
            $this->progressTimes=[];
        }
    }

    private function rows(ZipArchive $zip, string $name, bool $required, PDO $db, string $sql, callable $map): int
    {
        preg_match('/^INSERT INTO ([a-z_]+)/',$sql,$table);
        $key=$table[1] ?? $name;
        $this->notify('import', $key, 'Importing '.$name, 'Reading '.$name.' into the new timetable database.', ['file'=>$name,'completed'=>0,'total'=>null,'unit'=>'rows','percent'=>0], true);
        $stream = $zip->getStream($name);
        if (!$stream) {
            if ($required) { throw new RuntimeException('Missing GTFS file: ' . $name); }
            $this->notify('import', $key, $name.' not supplied', 'This optional timetable file is not present.', ['file'=>$name,'status'=>'skipped','completed'=>0,'total'=>0,'unit'=>'rows','percent'=>100], true);
            return 0;
        }
        $size=(int)($zip->statName($name)['size'] ?? 0);
        $headerLine = fgets($stream);
        if ($headerLine === false) { fclose($stream); throw new RuntimeException('Empty GTFS file: ' . $name); }
        // Strip a BOM before CSV parsing so a quoted first header is recognized too.
        $header = str_getcsv(preg_replace('/^\xEF\xBB\xBF/', '', $headerLine), ',', '"', '');
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
            'transfers.txt' => ['transfer_type'],
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
                if ($this->progress!==null && $count%1000===0) {
                    $position=ftell($stream);
                    $bytes=$position===false ? null : max(0,(int)$position);
                    $this->notify('import', $key, 'Importing '.$name, number_format($count).' rows imported from '.$name.'.',
                        ['file'=>$name,'completed'=>$count,'total'=>null,'unit'=>'rows','bytesCompleted'=>$bytes,'bytesTotal'=>$size ?: null,'percent'=>$size>0 && $bytes!==null ? min(99,(int)floor($bytes/$size*100)) : null]);
                }
            }
            $db->commit();
        } finally {
            if ($db->inTransaction()) { $db->rollBack(); }
            fclose($stream);
        }
        $this->notify('import', $key, $name.' imported', number_format($count).' rows imported from '.$name.'.', ['file'=>$name,'status'=>'complete','completed'=>$count,'total'=>$count,'unit'=>'rows','bytesCompleted'=>$size,'bytesTotal'=>$size,'percent'=>100], true);
        return $count;
    }

    public static function seconds(string $time): int
    {
        if (!preg_match('/^(\d{1,3}):([0-5]\d):([0-5]\d)$/', $time, $parts)) {
            throw new RuntimeException('GTFS has a missing/invalid stop time. Interpolate untimed stops before import.');
        }
        return (int)$parts[1] * 3600 + (int)$parts[2] * 60 + (int)$parts[3];
    }

    /** Optional station fields must not silently turn invalid text into zero. */
    private static function integer(string $value, int $minimum, int $maximum, string $field, ?int $empty = null): int
    {
        $value=trim($value);
        if ($value==='') {
            if ($empty!==null) { return $empty; }
            throw new RuntimeException('GTFS has a missing/invalid '.$field.'.');
        }
        if (!preg_match('/^\d+$/D',$value)) { throw new RuntimeException('GTFS has a missing/invalid '.$field.'.'); }
        $normalized=ltrim($value,'0');
        $normalized=$normalized==='' ? '0' : $normalized;
        if (strlen($normalized)>strlen((string)$maximum) || (strlen($normalized)===strlen((string)$maximum) && strcmp($normalized,(string)$maximum)>0)) {
            throw new RuntimeException('GTFS has an out-of-range '.$field.'.');
        }
        $number=(int)$normalized;
        if ($number<$minimum || $number>$maximum) { throw new RuntimeException('GTFS has an out-of-range '.$field.'.'); }
        return $number;
    }

    private function validateStationReferences(PDO $db): void
    {
        if ((int)$db->query("SELECT COUNT(*) FROM stop_details d LEFT JOIN stops p ON p.id=d.parent_station
            WHERE d.parent_station<>'' AND (p.id IS NULL OR d.parent_station=d.stop_id)")->fetchColumn()) {
            throw new RuntimeException('GTFS stops.txt contains an unknown or self-referencing parent_station.');
        }
        $parents=$db->query("SELECT stop_id,parent_station FROM stop_details WHERE parent_station<>''")->fetchAll(PDO::FETCH_KEY_PAIR);
        $done=[];
        foreach ($parents as $id=>$parent) {
            $path=[]; $cursor=$id;
            while (isset($parents[$cursor]) && !isset($done[$cursor])) {
                if (isset($path[$cursor])) { throw new RuntimeException('GTFS stops.txt contains a parent_station cycle.'); }
                $path[$cursor]=true; $cursor=$parents[$cursor];
            }
            $done+=$path;
        }
        foreach (['from_stop_id'=>'stops','to_stop_id'=>'stops','from_route_id'=>'routes','to_route_id'=>'routes','from_trip_id'=>'trips','to_trip_id'=>'trips'] as $column=>$table) {
            if ((int)$db->query("SELECT COUNT(*) FROM transfers t LEFT JOIN $table r ON r.id=t.$column WHERE t.$column<>'' AND r.id IS NULL")->fetchColumn()) {
                throw new RuntimeException('GTFS transfers.txt contains an unknown '.$column.'.');
            }
        }
        foreach (['from','to'] as $direction) {
            if ((int)$db->query("SELECT COUNT(*) FROM transfers t JOIN trips p ON p.id=t.{$direction}_trip_id
                WHERE t.{$direction}_route_id<>'' AND t.{$direction}_route_id<>p.route_id")->fetchColumn()) {
                throw new RuntimeException('GTFS transfers.txt route and trip qualifiers do not match.');
            }
        }
    }

    /** Progress is advisory: a closed terminal or failed observer cannot damage an import. */
    private function notify(string $stage,string $key,string $label,string $message,array $details=[],bool $force=false): void
    {
        if ($this->progress===null) { return; }
        $now=hrtime(true)/1_000_000_000;
        $identity=$stage.':'.$key;
        if (!$force && isset($this->progressTimes[$identity]) && $now-$this->progressTimes[$identity]<1.0) { return; }
        $this->progressTimes[$identity]=$now;
        try {
            ($this->progress)(array_merge(['stage'=>$stage,'key'=>$key,'label'=>$label,'message'=>$message,'status'=>'running'],$details));
        } catch (\Throwable) {
            // Observers may report status, but do not control publication or rollback.
        }
    }

    private function download(string $target): void
    {
        if (!function_exists('curl_init')) { throw new RuntimeException('Enable PHP cURL or import a local ZIP using the CLI.'); }
        $out = fopen($target, 'wb');
        $bytes = 0;
        $downloadTotal=0;
        $this->notify('download', 'gtfs', 'Downloading the official timetable', 'Connecting to the official Transperth timetable source.', ['completed'=>0,'total'=>null,'unit'=>'bytes','percent'=>null], true);
        $curl = curl_init(self::SOURCE);
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 240,
            CURLOPT_USERAGENT => 'PerthMove/0.1 GTFS timetable importer', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_NOPROGRESS=>false,
            CURLOPT_XFERINFOFUNCTION=>function ($curl,float $total,float $received,float $uploadTotal,float $uploadNow) use (&$downloadTotal): int {
                $downloadTotal=max(0,(int)$total);
                $received=max(0,(int)$received);
                $this->notify('download', 'gtfs', 'Downloading the official timetable', number_format($received/1048576,1).' MB received from Transperth.',
                    ['completed'=>$received,'total'=>$downloadTotal>0 ? $downloadTotal : null,'unit'=>'bytes','percent'=>$downloadTotal>0 ? min(99,(int)floor($received/$downloadTotal*100)) : null]);
                return 0;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($out, &$bytes): int {
                $bytes += strlen($chunk);
                return $bytes <= self::MAX_DOWNLOAD ? fwrite($out, $chunk) : 0;
            }]);
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        $curl = null;
        fclose($out);
        if ($ok === false || $status !== 200) {
            throw new RuntimeException('Official Transperth download failed (HTTP ' . $status . '). Check hosting outbound HTTPS access; alternatively import an official local ZIP. ' . $error);
        }
        $this->notify('download', 'gtfs', 'Official timetable downloaded', number_format($bytes/1048576,1).' MB downloaded from Transperth.',
            ['status'=>'complete','completed'=>$bytes,'total'=>$downloadTotal>0 ? $downloadTotal : $bytes,'unit'=>'bytes','percent'=>100], true);
    }
}
