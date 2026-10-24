<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/** Persistent, simplified network geometry. Vehicle positions are never cached here. */
final class RouteCache
{
    private const FORMAT = 1;
    private const TOLERANCE_METRES = 20.0;
    private const MESSAGE = 'All imported route variants, simplified for browsing. These lines do not indicate which services are running now; vehicle positions remain timetable estimates.';

    /** Build now, retaining the previous complete cache if any step fails. */
    public static function build(?string $database = null): array
    {
        self::prepareStorage();
        $lock = self::lock();
        try {
            return self::generate(self::databasePath($database));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Ensure a cache exists; callers can readfile() this path without decoding JSON. */
    public static function path(?string $database = null): string
    {
        $database = self::databasePath($database);
        $revision = self::revision($database);
        $path = APP_VAR . '/routes.json';
        if (self::current($revision)) { return $path; }
        self::prepareStorage();
        $lock = self::lock(true);
        if ($lock === null) {
            // One worker refreshes; concurrent requests can use the last complete network.
            if (is_file($path) && !is_link($path) && filesize($path) > 0) { return $path; }
            throw new RuntimeException('The network route cache is being prepared. Try again shortly.');
        }
        try {
            if (!self::current(self::revision($database))) { self::generate($database); }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $path;
    }

    public static function read(?string $database = null): array
    {
        $body = file_get_contents(self::path($database));
        if ($body === false) { throw new RuntimeException('Cannot read the network route cache.'); }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /** No database content hash or checkout path: a staging-file rename keeps its revision. */
    public static function revision(?string $database = null): string
    {
        $path = self::databasePath($database);
        $db = self::open($path);
        return self::fingerprint($db, $path);
    }

    private static function databasePath(?string $database): string
    {
        $path = $database ?? APP_VAR . '/transit.sqlite';
        if (!is_file($path) || !is_readable($path)) { throw new RuntimeException('Import a timetable before building the network route cache.'); }
        return $path;
    }

    private static function open(string $path): PDO
    {
        $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=3000;');
        return $db;
    }

    private static function fingerprint(PDO $db, string $path): string
    {
        $meta = $db->query('SELECT key,value FROM meta')->fetchAll(PDO::FETCH_KEY_PAIR);
        if (empty($meta['imported_at'])) { throw new RuntimeException('The timetable is missing its import revision. Import it again.'); }
        ksort($meta);
        clearstatcache(true, $path);
        $stat = stat($path);
        if ($stat === false) { throw new RuntimeException('Cannot inspect the timetable revision.'); }
        return hash('sha256', json_encode([self::FORMAT, $meta, $stat['size'], $stat['mtime'], $stat['ino']], JSON_THROW_ON_ERROR));
    }

    private static function current(string $revision): bool
    {
        $path = APP_VAR . '/routes.json';
        $metaPath = APP_VAR . '/routes.meta.json';
        if (!is_file($path) || is_link($path) || !is_file($metaPath) || is_link($metaPath)) { return false; }
        $meta = json_decode((string)file_get_contents($metaPath), true);
        return is_array($meta) && ($meta['revision'] ?? '') === $revision && ($meta['bytes'] ?? -1) === filesize($path);
    }

    private static function prepareStorage(): void
    {
        if (is_link(APP_VAR) || (!is_dir(APP_VAR) && !mkdir(APP_VAR, 0700, true)) || !is_writable(APP_VAR)) {
            throw new RuntimeException('The private var folder must be a writable real directory.');
        }
        foreach (['routes.json', 'routes.json.gz', 'routes.meta.json', 'routes.lock'] as $file) {
            if (is_link(APP_VAR . '/' . $file)) { throw new RuntimeException('A network cache path is an unsafe symbolic link.'); }
        }
    }

    /** @return resource|null */
    private static function lock(bool $nonblocking = false)
    {
        $lock = fopen(APP_VAR . '/routes.lock', 'c');
        if (!$lock) { throw new RuntimeException('Cannot open the network cache lock.'); }
        chmod(APP_VAR . '/routes.lock', 0600);
        if (!flock($lock, LOCK_EX | ($nonblocking ? LOCK_NB : 0))) {
            fclose($lock);
            if ($nonblocking) { return null; }
            throw new RuntimeException('Cannot lock the network route cache.');
        }
        return $lock;
    }

    private static function generate(string $database): array
    {
        $started = microtime(true);
        $db = self::open($database);
        $db->beginTransaction();
        $revision = self::fingerprint($db, $database);
        $generatedAt = date(DATE_ATOM);
        $temporary = APP_VAR . '/routes-' . bin2hex(random_bytes(8));
        $plain = $temporary . '.json';
        $compressed = $temporary . '.json.gz';
        $metaFile = $temporary . '.meta.json';
        $output = null;
        $gzip = null;
        try {
            $output = fopen($plain, 'xb');
            if (!$output) { throw new RuntimeException('Cannot stage the network route cache.'); }
            chmod($plain, 0600);
            $hasStyles = (bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='route_styles'")->fetchColumn();
            $styleColumns = $hasStyles ? ',rs.color,rs.text_color' : ",'' AS color,'' AS text_color";
            $styleJoin = $hasStyles ? ' LEFT JOIN route_styles rs ON rs.route_id=r.id' : '';
            // Grouping removes repeated trips without losing any route/shape variant.
            // Sorting by shape means a shape shared by routes is simplified just once.
            $pairs = $db->query('SELECT t.route_id,COALESCE(t.shape_id,\'\') AS shape_id,MIN(t.id) AS trip_id,r.name AS route_name,r.type' . $styleColumns .
                ' FROM trips t JOIN routes r ON r.id=t.route_id' . $styleJoin .
                ' GROUP BY t.route_id,COALESCE(t.shape_id,\'\') ORDER BY shape_id,t.route_id');
            $shapeQuery = $db->prepare('SELECT lat,lon FROM shapes WHERE id=? ORDER BY seq');
            $stopQuery = $db->prepare('SELECT s.lat,s.lon FROM stop_times st JOIN stops s ON s.id=st.stop_id WHERE st.trip_id=? ORDER BY st.seq');
            $header = ['revision' => $revision, 'generatedAt' => $generatedAt, 'mode' => 'timetable', 'approximate' => true, 'message' => self::MESSAGE];
            self::write($output, substr(json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 0, -1) . ',"lines":[');
            $lastShape = null;
            $shapeGeometry = [];
            $shapeBounds = [];
            $lines = 0;
            $points = 0;
            $sourcePoints = 0;
            $omitted = 0;
            foreach ($pairs as $pair) {
                $id = (string)$pair['shape_id'];
                if ($id !== $lastShape) {
                    $lastShape = $id;
                    $shapeGeometry = [];
                    $shapeBounds = [];
                    if ($id !== '') {
                        $shapeQuery->execute([$id]);
                        $raw = self::coordinates($shapeQuery);
                        $sourcePoints += count($raw);
                        if (count($raw) >= 2) {
                            $shapeBounds = self::bounds($raw);
                            $shapeGeometry = self::simplify($raw);
                        }
                    }
                }
                $geometry = $shapeGeometry;
                $bbox = $shapeBounds;
                if (count($geometry) < 2) {
                    $stopQuery->execute([$pair['trip_id']]);
                    $raw = self::coordinates($stopQuery);
                    $sourcePoints += count($raw);
                    if (count($raw) < 2) { $omitted++; continue; }
                    $geometry = self::simplify($raw);
                    $bbox = self::bounds($raw);
                }
                $style = NetworkStyle::forRoute((string)$pair['route_name'], (int)$pair['type'], $pair['color'], $pair['text_color']);
                $line = ['routeId' => (string)$pair['route_id'], 'routeName' => (string)$pair['route_name'],
                    'shapeId' => $id, 'mode' => self::mode((int)$pair['type']),
                    'routeColor' => $style['color'], 'routeTextColor' => $style['textColor'], 'lineName' => $style['lineName'],
                    'bbox' => $bbox, 'geometry' => $geometry];
                self::write($output, ($lines ? ',' : '') . json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $lines++;
                $points += count($geometry);
            }
            if (!$lines) { throw new RuntimeException('The timetable has no usable route geometry. The previous route cache was retained.'); }
            self::write($output, ']}');
            fflush($output);
            fclose($output);
            $output = null;
            $db->commit();
            $bytes = filesize($plain);
            $gzipBytes = null;
            if (function_exists('gzopen')) {
                $input = fopen($plain, 'rb');
                $gzip = gzopen($compressed, 'wb6');
                if (!$input || !$gzip) { throw new RuntimeException('Cannot compress the network route cache.'); }
                try {
                    while (!feof($input)) {
                        $chunk = fread($input, 65536);
                        if ($chunk === false || gzwrite($gzip, $chunk) !== strlen($chunk)) { throw new RuntimeException('Cannot write the compressed route cache.'); }
                    }
                } finally {
                    fclose($input);
                    gzclose($gzip);
                    $gzip = null;
                }
                chmod($compressed, 0600);
                $gzipBytes = filesize($compressed);
            }
            $result = ['revision' => $revision, 'generatedAt' => $generatedAt, 'lines' => $lines, 'points' => $points,
                'sourcePoints' => $sourcePoints, 'omittedLines' => $omitted, 'bytes' => $bytes, 'gzipBytes' => $gzipBytes,
                'durationSeconds' => round(microtime(true) - $started, 3)];
            if (file_put_contents($metaFile, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
                throw new RuntimeException('Cannot stage the network cache metadata.');
            }
            chmod($metaFile, 0600);
            // Only complete files become visible. Metadata is the final commit marker.
            if (is_file($compressed) && !rename($compressed, APP_VAR . '/routes.json.gz')) { throw new RuntimeException('Cannot publish the compressed route cache.'); }
            if (!rename($plain, APP_VAR . '/routes.json')) { throw new RuntimeException('Cannot publish the route cache.'); }
            if (!rename($metaFile, APP_VAR . '/routes.meta.json')) { throw new RuntimeException('Cannot publish route cache metadata.'); }
            if ($gzipBytes === null && is_file(APP_VAR . '/routes.json.gz')) { unlink(APP_VAR . '/routes.json.gz'); }
            return $result;
        } finally {
            if (is_resource($output)) { fclose($output); }
            if (is_resource($gzip)) { gzclose($gzip); }
            foreach ([$pairs ?? null, $shapeQuery ?? null, $stopQuery ?? null] as $query) {
                if ($query instanceof \PDOStatement) { $query->closeCursor(); }
            }
            if ($db->inTransaction()) { $db->rollBack(); }
            foreach ([$plain, $compressed, $metaFile] as $file) { if (is_file($file)) { unlink($file); } }
        }
    }

    private static function write($stream, string $text): void
    {
        if (fwrite($stream, $text) !== strlen($text)) { throw new RuntimeException('Cannot write the network route cache; check free disk space.'); }
    }

    private static function coordinates(\PDOStatement $query): array
    {
        $points = [];
        try {
            foreach ($query as $row) {
                if (!is_numeric($row['lat']) || !is_numeric($row['lon'])) { throw new RuntimeException('Route geometry contains an invalid coordinate.'); }
                $lat = (float)$row['lat']; $lon = (float)$row['lon'];
                if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180) { throw new RuntimeException('Route geometry contains an invalid coordinate.'); }
                $last = $points[count($points) - 1] ?? null;
                if ($last === null || $last[0] !== $lat || $last[1] !== $lon) { $points[] = [$lat, $lon]; }
            }
        } finally {
            // Also release read locks when an exception retains this statement in its trace.
            $query->closeCursor();
        }
        return $points;
    }

    private static function bounds(array $points): array
    {
        $west = INF; $south = INF; $east = -INF; $north = -INF;
        foreach ($points as [$lat, $lon]) {
            $west = min($west, $lon); $south = min($south, $lat);
            $east = max($east, $lon); $north = max($north, $lat);
        }
        return [round(floor($west * 1e5) / 1e5, 5), round(floor($south * 1e5) / 1e5, 5),
            round(ceil($east * 1e5) / 1e5, 5), round(ceil($north * 1e5) / 1e5, 5)];
    }

    /** Iterative Ramer-Douglas-Peucker in a local metre projection, about 20 m tolerance. */
    private static function simplify(array $points): array
    {
        $last = count($points) - 1;
        $keep = array_fill(0, $last + 1, false);
        $keep[0] = true; $keep[$last] = true;
        $scaleX = 111320 * cos(deg2rad(($points[0][0] + $points[$last][0]) / 2));
        $scaleY = 110540.0;
        $squaredTolerance = self::TOLERANCE_METRES ** 2;
        $stack = [[0, $last]];
        while ($stack) {
            [$start, $end] = array_pop($stack);
            if ($end <= $start + 1) { continue; }
            $x = ($points[$end][1] - $points[$start][1]) * $scaleX;
            $y = ($points[$end][0] - $points[$start][0]) * $scaleY;
            $length = $x * $x + $y * $y;
            $maximum = $squaredTolerance; $index = null;
            for ($i = $start + 1; $i < $end; $i++) {
                $px = ($points[$i][1] - $points[$start][1]) * $scaleX;
                $py = ($points[$i][0] - $points[$start][0]) * $scaleY;
                $fraction = $length > 0 ? max(0.0, min(1.0, ($px * $x + $py * $y) / $length)) : 0.0;
                $distance = ($px - $fraction * $x) ** 2 + ($py - $fraction * $y) ** 2;
                if ($distance > $maximum) { $maximum = $distance; $index = $i; }
            }
            if ($index !== null) { $keep[$index] = true; $stack[] = [$start, $index]; $stack[] = [$index, $end]; }
        }
        $result = [];
        foreach ($points as $i => [$lat, $lon]) { if ($keep[$i]) { $result[] = [round($lat, 5), round($lon, 5)]; } }
        return $result;
    }

    private static function mode(int $type): string
    {
        return in_array($type, [0, 1, 2], true) || ($type >= 100 && $type < 200) ? 'train'
            : ($type === 4 || ($type >= 1000 && $type < 1100) ? 'ferry' : 'bus');
    }
}
