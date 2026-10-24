<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/** Official public Telstra register, cached once and filtered to the metro area. */
final class Payphones
{
    public const COVERAGE = [115.4, -32.75, 116.5, -31.3]; // west, south, east, north
    public const SOURCE_URL = 'https://www.telstra.com.au/content/dam/tcom/personal/consumer-advice/payphones/payphone_register.xlsx';
    public const SOURCE_PAGE = 'https://www.telstra.com.au/consumer-advice/payphones';
    private const TTL = 604800;
    private const RETRY = 900;
    private const MAX_BYTES = 8_000_000;
    private const GUARD = "<?php http_response_code(404); exit; ?>\n";
    private const ATTRIBUTION = 'Source: Telstra public payphone register';
    private const MESSAGE = 'Locations from the public Telstra payphone register. A site can contain several payphones; registered coordinates do not confirm current availability. Check on arrival.';

    public static function inBounds(array $bbox): array
    {
        $bbox = self::bounds($bbox);
        $cache = self::readCache();
        if ($bbox[2] < self::COVERAGE[0] || $bbox[0] > self::COVERAGE[2] || $bbox[3] < self::COVERAGE[1] || $bbox[1] > self::COVERAGE[3]) {
            return self::response($cache, [], false, 'Public telephone coverage is currently limited to the Perth metropolitan area.');
        }
        $status = self::readPrivate(APP_VAR . '/payphones-status.php') ?? [];
        $failed = self::failureApplies($status, $cache);
        $expired = $cache === null || ($cache['format'] ?? 1) !== 2 || (time() - strtotime($cache['updatedAt'])) >= self::TTL;
        $lastAttempt = (int)($status['attemptedAt'] ?? 0);
        $elapsed = time() - $lastAttempt;
        $backoff = ($status['sourceUrl'] ?? '') === self::SOURCE_URL && $lastAttempt > 0 && $elapsed >= 0 && $elapsed < self::RETRY;
        if (($expired || $failed) && !$backoff) {
            try {
                $cache = self::refresh();
                $failed = false;
                $expired = false;
            } catch (\Throwable) {
                // Optional map layers never hide the route map when a provider fails.
                $cache = self::readCache();
                $failed = true;
            }
        }
        if ($cache === null) {
            return self::response(null, [], true, 'Public telephone locations are temporarily unavailable. No locations have been confirmed; try again later.');
        }
        $phones = array_values(array_filter($cache['phones'], static fn($phone) =>
            $phone['lon'] >= max($bbox[0], self::COVERAGE[0]) && $phone['lon'] <= min($bbox[2], self::COVERAGE[2]) &&
            $phone['lat'] >= max($bbox[1], self::COVERAGE[1]) && $phone['lat'] <= min($bbox[3], self::COVERAGE[3])));
        $stale = $expired || $failed;
        return self::response($cache, $phones, $stale, $stale
            ? 'Showing saved ' . ($cache['source'] ?? 'public telephone') . ' locations because a refresh is unavailable or overdue. Check availability on arrival.'
            : self::MESSAGE);
    }

    /**
     * Optional setup/CLI prefetch. A fixture callback is accepted only by isolated CLI tests.
     * Production callers always use the fixed HTTPS endpoint and fixed bounded query.
     */
    public static function refresh(?callable $fixtureFetcher = null): array
    {
        if ($fixtureFetcher !== null && !(PHP_SAPI === 'cli' && defined('PAYPHONES_TESTING') && PAYPHONES_TESTING === true)) {
            throw new RuntimeException('A payphone fixture fetcher is available only to isolated CLI tests.');
        }
        self::prepareStorage();
        $lock = fopen(APP_VAR . '/payphones.lock', 'c');
        if (!$lock) { throw new RuntimeException('Cannot open the public telephone cache lock.'); }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Public telephone locations are already being refreshed.');
        }
        chmod(APP_VAR . '/payphones.lock', 0600);
        $previous = self::readCache();
        try {
            $payload = $fixtureFetcher !== null ? $fixtureFetcher() : self::fetch();
            if (!is_array($payload)) { throw new RuntimeException('The telephone provider returned an invalid response.'); }
            $phones = $payload['phones'] ?? null;
            if (!is_array($phones)) { throw new RuntimeException('The payphone register has no normalized locations.'); }
            foreach ($phones as $phone) {
                if (!is_array($phone) || !str_starts_with((string)($phone['id'] ?? ''), 'telstra-') ||
                    !is_numeric($phone['lat'] ?? null) || !is_numeric($phone['lon'] ?? null)) {
                    throw new RuntimeException('The payphone register contains an invalid location.');
                }
            }
            if (!$phones) { throw new RuntimeException('The Perth public telephone dataset was empty; saved locations were retained.'); }
            $cache = ['format' => 2, 'generation' => bin2hex(random_bytes(8)), 'updatedAt' => date(DATE_ATOM),
                'sourceUpdatedAt' => self::text($payload['sourceUpdatedAt'] ?? '', 80), 'registerRecords' => (int)($payload['registerRecords'] ?? 0),
                'source' => 'Telstra public payphone register', 'sourceUrl' => self::SOURCE_PAGE, 'downloadUrl' => self::SOURCE_URL,
                'coverage' => self::COVERAGE, 'phones' => $phones];
            self::writePrivate(APP_VAR . '/payphones.php', $cache);
            self::writePrivate(APP_VAR . '/payphones-status.php', ['attemptedAt' => time(), 'sourceUrl' => self::SOURCE_URL, 'ok' => true, 'generation' => $cache['generation']]);
            return $cache;
        } catch (\Throwable $error) {
            // A failed refresh cannot replace or erase a previously useful dataset.
            try {
                self::writePrivate(APP_VAR . '/payphones-status.php', ['attemptedAt' => time(), 'sourceUrl' => self::SOURCE_URL, 'ok' => false,
                    'failedGeneration' => $previous['generation'] ?? null, 'error' => $error->getMessage()]);
            } catch (\Throwable) { /* The original fetch/storage error is more useful. */ }
            throw $error;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Pure normalizer for Overpass nodes and way/relation centres. */
    public static function normalize(array $payload): array
    {
        if (!empty($payload['remark'])) { throw new RuntimeException('The telephone provider returned an incomplete Overpass result.'); }
        if (!isset($payload['elements']) || !is_array($payload['elements'])) { throw new RuntimeException('The telephone provider response has no elements list.'); }
        if (count($payload['elements']) > 50000) { throw new RuntimeException('The telephone provider response exceeds the bounded metro limit.'); }
        $phones = [];
        foreach ($payload['elements'] as $element) {
            if (!is_array($element) || !in_array($element['type'] ?? '', ['node', 'way', 'relation'], true)) { continue; }
            $rawId = $element['id'] ?? '';
            if (!is_string($rawId) && !is_int($rawId)) { continue; }
            $id = (string)$rawId;
            if (!preg_match('/^[0-9]+$/D', $id) || (int)$id <= 0) { continue; }
            $tags = $element['tags'] ?? null;
            if (!is_array($tags) || ($tags['amenity'] ?? '') !== 'telephone') { continue; }
            if (in_array(strtolower(self::text($tags['access'] ?? '', 30)), ['private', 'no', 'customers'], true) ||
                in_array(strtolower(self::text($tags['disused'] ?? '', 30)), ['yes', 'true', '1'], true) ||
                in_array(strtolower(self::text($tags['abandoned'] ?? '', 30)), ['yes', 'true', '1'], true)) { continue; }
            $point = $element['type'] === 'node' ? $element : ($element['center'] ?? []);
            if (!is_array($point) || !is_numeric($point['lat'] ?? null) || !is_numeric($point['lon'] ?? null)) { continue; }
            $lat = (float)$point['lat']; $lon = (float)$point['lon'];
            if (!is_finite($lat) || !is_finite($lon) || $lon < self::COVERAGE[0] || $lon > self::COVERAGE[2] || $lat < self::COVERAGE[1] || $lat > self::COVERAGE[3]) { continue; }
            $operator = self::text($tags['operator'] ?? '', 120);
            $name = self::text($tags['name'] ?? '', 160);
            $phone = ['id' => 'osm-' . $element['type'] . '-' . $id,
                'name' => $name !== '' ? $name : ($operator !== '' ? $operator . ' public telephone' : 'Public telephone'),
                'lat' => $lat, 'lon' => $lon];
            if ($operator !== '') { $phone['operator'] = $operator; }
            $address = self::text($tags['addr:full'] ?? '', 240);
            if ($address === '') {
                $street = trim(self::text($tags['addr:housenumber'] ?? '', 30) . ' ' . self::text($tags['addr:street'] ?? '', 120));
                $parts = [$street, self::text($tags['addr:suburb'] ?? '', 80), self::text($tags['addr:city'] ?? '', 80), self::text($tags['addr:postcode'] ?? '', 20)];
                $address = self::text(implode(', ', array_unique(array_filter($parts, static fn($part) => $part !== ''))), 240);
            }
            if ($address !== '') { $phone['address'] = $address; }
            $ref = self::text($tags['ref:telstra'] ?? ($tags['ref'] ?? ''), 80);
            if ($ref !== '') { $phone['ref'] = $ref; }
            $phones[$phone['id']] = $phone;
        }
        ksort($phones);
        return array_values($phones);
    }

    /** Read bounded OpenXML cells without XML extensions, DTDs, or entity expansion. */
    public static function normalizeRegister(string $archive): array
    {
        if (!class_exists(\ZipArchive::class)) { throw new RuntimeException('PHP ZIP is required to read the public payphone register.'); }
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) { throw new RuntimeException('The payphone register is not a readable XLSX archive.'); }
        try {
            if ($zip->numFiles > 100) { throw new RuntimeException('The payphone workbook contains too many files.'); }
            $total = 0; $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i); $name = $entry['name'];
                if (isset($names[$name]) || preg_match('~(^/|\\\\|(^|/)\.\.(/|$))~', $name)) { throw new RuntimeException('The payphone workbook contains an unsafe archive path.'); }
                $names[$name] = true; $total += (int)$entry['size'];
            }
            if ($total > 50_000_000) { throw new RuntimeException('The payphone workbook exceeds the 50 MB expanded limit.'); }
            $strings = [];
            if (isset($names['xl/sharedStrings.xml'])) {
                $xml = self::workbookXml($zip, 'xl/sharedStrings.xml'); $offset = 0;
                while (preg_match('/<si\b[^>]*>(.*?)<\/si>/s', $xml, $match, PREG_OFFSET_CAPTURE, $offset)) {
                    $strings[] = self::xmlText($match[1][0]);
                    $offset = $match[0][1] + strlen($match[0][0]);
                    if (count($strings) > 300000) { throw new RuntimeException('The payphone workbook has too many shared strings.'); }
                }
                unset($xml);
            }
            $sheet = self::workbookXml($zip, 'xl/worksheets/sheet1.xml');
            $offset = 0; $header = null; $records = 0; $phones = []; $rows = 0;
            while (preg_match('/<row\b[^>]*>(.*?)<\/row>/s', $sheet, $match, PREG_OFFSET_CAPTURE, $offset)) {
                $offset = $match[0][1] + strlen($match[0][0]);
                $rows++;
                if ($rows > 100000) { throw new RuntimeException('The payphone workbook has too many rows.'); }
                $cells = [];
                if (strlen($match[1][0]) > 100000) { throw new RuntimeException('The payphone workbook has an oversized row.'); }
                preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $match[1][0], $cellMatches, PREG_SET_ORDER);
                foreach ($cellMatches as $cell) {
                    if (!preg_match('/\br=["\']([A-Z]+)[0-9]+["\']/', $cell[1], $column)) { continue; }
                    preg_match('/\bt=["\']([^"\']+)["\']/', $cell[1], $type);
                    $inner = $cell[2] ?? '';
                    if (($type[1] ?? '') === 'inlineStr') { $value = self::xmlText($inner); }
                    else {
                        preg_match('/<v\b[^>]*>(.*?)<\/v>/s', $inner, $valueMatch);
                        $value = self::xmlDecode($valueMatch[1] ?? '');
                        if (($type[1] ?? '') === 's') {
                            if (!preg_match('/^[0-9]+$/D', $value) || !array_key_exists((int)$value, $strings)) { throw new RuntimeException('The payphone workbook has an invalid shared string reference.'); }
                            $value = $strings[(int)$value];
                        }
                    }
                    if (isset($cells[$column[1]])) { throw new RuntimeException('The payphone workbook repeats a cell.'); }
                    $cells[$column[1]] = $value;
                }
                if (!$cells) { continue; }
                if ($header === null) {
                    $candidate = array_map(static fn($value) => strtoupper(trim($value)), $cells);
                    if (in_array('CAB_ID', $candidate, true)) {
                        $nonempty = array_values(array_filter($candidate, static fn($value) => $value !== ''));
                        if (count($nonempty) !== count(array_unique($nonempty))) { throw new RuntimeException('The payphone register has duplicate column names.'); }
                        foreach (['CAB_ID', 'STATE', 'SITE_LAT', 'SITE_LONGIT'] as $required) {
                            if (!in_array($required, $candidate, true)) { throw new RuntimeException('The payphone register is missing column ' . $required . '.'); }
                        }
                        $header = $candidate;
                    } elseif ($rows >= 50) { throw new RuntimeException('The payphone register header was not found.'); }
                    continue;
                }
                $record = [];
                foreach ($header as $column => $label) { if ($label !== '') { $record[$label] = trim($cells[$column] ?? ''); } }
                if (($record['CAB_ID'] ?? '') === '') { continue; }
                $records++;
                if (strtoupper($record['STATE']) !== 'WA' || !is_numeric($record['SITE_LAT']) || !is_numeric($record['SITE_LONGIT'])) { continue; }
                $lat = (float)$record['SITE_LAT']; $lon = (float)$record['SITE_LONGIT'];
                if (!is_finite($lat) || !is_finite($lon) || $lat < self::COVERAGE[1] || $lat > self::COVERAGE[3] || $lon < self::COVERAGE[0] || $lon > self::COVERAGE[2]) { continue; }
                $cab = $record['CAB_ID'];
                if (!preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $cab)) { continue; }
                $count = $record['PAYPHONES_AT_SITE'] ?? '';
                if ($count === '0') { continue; }
                $name = self::text($record['ADD_ADDR_INFO'] ?? '', 160);
                $phone = ['id' => 'telstra-' . $cab, 'name' => $name !== '' ? $name : 'Telstra public payphone',
                    'lat' => $lat, 'lon' => $lon, 'operator' => 'Telstra', 'ref' => $cab];
                $street = trim(implode(' ', array_filter([$record['STREET_NR'] ?? '', $record['STREET_NME'] ?? '', $record['STREET_TYP_CDE'] ?? ''], static fn($part) => $part !== '')));
                $locality = trim(($record['LOCALITY_NAME'] ?? '') . ' WA ' . ($record['POST_CDE'] ?? ''));
                $address = self::text(implode(', ', array_filter([$street, $locality], static fn($part) => $part !== '')), 240);
                if ($address !== '') { $phone['address'] = $address; }
                if (preg_match('/^[0-9]+$/D', $count) && (int)$count > 0 && (int)$count <= 100) { $phone['phoneCount'] = (int)$count; }
                if (isset($phones[$phone['id']])) {
                    if ($phones[$phone['id']]['lat'] !== $lat || $phones[$phone['id']]['lon'] !== $lon) { throw new RuntimeException('The payphone register repeats an identifier at conflicting coordinates.'); }
                    continue;
                }
                $phones[$phone['id']] = $phone;
            }
            if ($header === null) { throw new RuntimeException('The payphone register header was not found.'); }
            $modified = '';
            if (isset($names['docProps/core.xml'])) {
                $core = self::workbookXml($zip, 'docProps/core.xml');
                if (preg_match('/<dcterms:modified\b[^>]*>([^<]+)<\/dcterms:modified>/', $core, $date)) {
                    $candidate = self::xmlDecode($date[1]);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $candidate) && strtotime($candidate) !== false) { $modified = $candidate; }
                }
            }
            ksort($phones);
            return ['phones' => array_values($phones), 'sourceUpdatedAt' => $modified, 'registerRecords' => $records];
        } finally { $zip->close(); }
    }

    private static function workbookXml(\ZipArchive $zip, string $name): string
    {
        $xml = $zip->getFromName($name);
        if ($xml === false || strlen($xml) > 50_000_000) { throw new RuntimeException('The payphone workbook is missing a required XML sheet.'); }
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) { throw new RuntimeException('The payphone workbook contains prohibited XML entities or a DTD.'); }
        return $xml;
    }

    private static function xmlText(string $xml): string
    {
        $value = '';
        if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $xml, $matches)) {
            foreach ($matches[1] as $text) { $value .= self::xmlDecode($text); }
        }
        return $value;
    }

    private static function xmlDecode(string $text): string
    {
        if (preg_match('/&(?!(?:amp|lt|gt|quot|apos|#[0-9]+|#x[0-9A-Fa-f]+);)/', $text)) { throw new RuntimeException('The payphone workbook contains an unsupported XML entity.'); }
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function bounds(array $bbox): array
    {
        if (count($bbox) !== 4) { throw new RuntimeException('A map viewport needs west, south, east and north coordinates.'); }
        $bbox = array_values($bbox);
        foreach ($bbox as &$value) {
            if (!is_numeric($value) || !is_finite((float)$value)) { throw new RuntimeException('The map viewport contains an invalid coordinate.'); }
            $value = (float)$value;
        }
        unset($value);
        if ($bbox[0] < -180 || $bbox[2] > 180 || $bbox[1] < -90 || $bbox[3] > 90 || $bbox[0] >= $bbox[2] || $bbox[1] >= $bbox[3]) {
            throw new RuntimeException('The map viewport bounds are invalid.');
        }
        return $bbox;
    }

    private static function response(?array $cache, array $phones, bool $stale, string $message): array
    {
        $legacy = $cache !== null && ($cache['format'] ?? 1) === 1;
        $counted = array_filter($phones, static fn($phone) => isset($phone['phoneCount']));
        return ['phones' => $phones, 'source' => $legacy ? 'OpenStreetMap' : 'Telstra public payphone register',
            'sourceUrl' => $legacy ? 'https://www.openstreetmap.org/copyright' : self::SOURCE_PAGE,
            'downloadUrl' => $legacy ? null : self::SOURCE_URL,
            'attribution' => $legacy ? '© OpenStreetMap contributors · ODbL' : self::ATTRIBUTION,
            'updatedAt' => $cache['updatedAt'] ?? null, 'sourceUpdatedAt' => $cache['sourceUpdatedAt'] ?? null,
            'osmTimestamp' => $cache['osmTimestamp'] ?? null, 'coverage' => self::COVERAGE,
            'siteCount' => count($phones), 'totalPhones' => !$legacy && $cache !== null && count($counted) === count($phones) ? array_sum(array_column($phones, 'phoneCount')) : null,
            'stale' => $stale, 'message' => $message];
    }

    private static function failureApplies(array $status, ?array $cache): bool
    {
        return ($status['sourceUrl'] ?? '') === self::SOURCE_URL && ($status['ok'] ?? true) === false && ($status['failedGeneration'] ?? null) === ($cache['generation'] ?? null);
    }

    private static function readCache(): ?array
    {
        $cache = self::readPrivate(APP_VAR . '/payphones.php');
        if (!in_array($cache['format'] ?? null, [1, 2], true) || !is_array($cache['phones'] ?? null) || !is_string($cache['generation'] ?? null) ||
            !is_string($cache['updatedAt'] ?? null) || strtotime($cache['updatedAt']) === false) { return null; }
        if (($cache['format'] === 1 && ($cache['source'] ?? '') !== 'OpenStreetMap') ||
            ($cache['format'] === 2 && (($cache['source'] ?? '') !== 'Telstra public payphone register' || ($cache['downloadUrl'] ?? '') !== self::SOURCE_URL))) { return null; }
        return $cache;
    }

    private static function readPrivate(string $path): ?array
    {
        if (!is_file($path) || is_link($path) || filesize($path) > self::MAX_BYTES) { return null; }
        $text = file_get_contents($path);
        if ($text === false || !str_starts_with($text, self::GUARD)) { return null; }
        $data = json_decode(substr($text, strlen(self::GUARD)), true);
        return is_array($data) ? $data : null;
    }

    private static function prepareStorage(): void
    {
        if (is_link(APP_VAR) || (!is_dir(APP_VAR) && !mkdir(APP_VAR, 0700, true)) || !is_writable(APP_VAR)) {
            throw new RuntimeException('The private telephone cache folder is not writable.');
        }
        foreach (['payphones.php', 'payphones-status.php', 'payphones.lock'] as $name) {
            if (is_link(APP_VAR . '/' . $name)) { throw new RuntimeException('A telephone cache path is an unsafe symbolic link.'); }
        }
    }

    private static function writePrivate(string $path, array $data): void
    {
        if (is_link($path)) { throw new RuntimeException('A telephone cache path is an unsafe symbolic link.'); }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp.php';
        try {
            $body = self::GUARD . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (strlen($body) > self::MAX_BYTES || file_put_contents($temporary, $body, LOCK_EX) !== strlen($body)) {
                throw new RuntimeException('Cannot write the public telephone cache.');
            }
            chmod($temporary, 0600);
            if (!rename($temporary, $path)) { throw new RuntimeException('Cannot publish the public telephone cache.'); }
        } finally {
            if (is_file($temporary)) { unlink($temporary); }
        }
    }

    private static function text(mixed $value, int $limit): string
    {
        if (!is_string($value) || !preg_match('//u', $value)) { return ''; }
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($value)) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        preg_match('/^.{0,' . $limit . '}/us', $value, $match);
        return $match[0] ?? '';
    }

    private static function fetch(): array
    {
        if (!function_exists('curl_init')) { throw new RuntimeException('PHP cURL is required for public telephone data.'); }
        // Public register only: browser locations and search text never leave this server.
        $temporary = tempnam(APP_VAR, 'payphone-register-');
        if ($temporary === false) { throw new RuntimeException('Cannot stage the public payphone register.'); }
        $output = null;
        try {
            chmod($temporary, 0600);
            $output = fopen($temporary, 'wb');
            if (!$output) { throw new RuntimeException('Cannot stage the public payphone register.'); }
            $bytes = 0;
            $curl = curl_init(self::SOURCE_URL);
            curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'PerthMap/0.1 (+https://github.com/efrgdgr0024345/map)',
                CURLOPT_HTTPHEADER => ['Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($output, &$bytes): int {
                    $bytes += strlen($chunk);
                    return $bytes > self::MAX_BYTES ? 0 : fwrite($output, $chunk);
                }]);
            $ok = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $errorNumber = curl_errno($curl);
            $error = self::text(curl_error($curl), 240);
            $curl = null;
            fclose($output); $output = null;
            if ($ok === false || $status !== 200) {
                throw new RuntimeException('Public telephone provider unavailable (HTTP ' . $status . ')' . ($error !== '' ? '; cURL ' . $errorNumber . ': ' . $error : '') . '.');
            }
            return self::normalizeRegister($temporary);
        } finally {
            if (is_resource($output)) { fclose($output); }
            if (is_file($temporary)) { unlink($temporary); }
        }
    }
}
