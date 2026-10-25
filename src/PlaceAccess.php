<?php
declare(strict_types=1);
namespace App;

/** Explicitly reviewed venue access points, never a proximity-based guess. */
final class PlaceAccess
{
    /** The catalog argument is a local test dependency and must never come from HTTP. */
    public static function resolve(array $properties,array $originalPoint,?string $catalogFile=null): ?array
    {
        $catalogFile ??= (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)).'/assets/place-access.json';
        if (!self::point($originalPoint) || !is_file($catalogFile) || is_link($catalogFile) || filesize($catalogFile)>1024*1024) { return null; }
        $type=$properties['osm_type'] ?? null; $id=$properties['osm_id'] ?? null;
        $key=$properties['osm_key'] ?? null; $value=$properties['osm_value'] ?? null;
        if (!is_string($type) || !in_array($type,['N','W','R'],true) || !self::identifier($id)
            || !is_string($key) || !is_string($value)) { return null; }
        try { $catalog=json_decode((string)file_get_contents($catalogFile),true,32,JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
        if (!is_array($catalog) || ($catalog['version'] ?? null)!==1 || !is_array($catalog['places'] ?? null)) { return null; }
        foreach ($catalog['places'] as $entry) {
            if (!is_array($entry) || ($entry['osmType'] ?? null)!==$type || !self::identifier($entry['osmId'] ?? null)
                || (string)$entry['osmId']!==(string)$id || ($entry['osmKey'] ?? null)!==$key || ($entry['osmValue'] ?? null)!==$value) { continue; }
            $access=$entry['access'] ?? null;
            if (!is_array($access) || !self::point($access) || !is_string($access['name'] ?? null)
                || trim($access['name'])==='' || strlen($access['name'])>100 || !is_string($entry['venueName'] ?? null)
                || trim($entry['venueName'])==='' || strlen($entry['venueName'])>160
                || !in_array($access['osmType'] ?? null,['N','W','R'],true) || !self::identifier($access['osmId'] ?? null)
                || !self::sourceUrl($access['sourceUrl'] ?? null,$access['osmType'],(string)$access['osmId']) || !self::reviewDate($entry['reviewedAt'] ?? null)) { return null; }
            // An explicit record still cannot move a selected point across the city.
            $distance=self::distance($originalPoint,$access);
            if ($distance>2000) { return null; }
            return ['lat'=>(float)$access['lat'],'lon'=>(float)$access['lon'],
                'venueName'=>trim($entry['venueName']),'accessName'=>trim($access['name']),
                'venuePoint'=>['lat'=>(float)$originalPoint['lat'],'lon'=>(float)$originalPoint['lon']],
                'accessSource'=>['osmType'=>$access['osmType'],'osmId'=>(string)$access['osmId'],
                    'sourceUrl'=>$access['sourceUrl'],'reviewedAt'=>$entry['reviewedAt']]];
        }
        return null;
    }

    private static function point(array $point): bool
    {
        if (!isset($point['lat'],$point['lon']) || !is_numeric($point['lat']) || !is_numeric($point['lon'])) { return false; }
        $lat=(float)$point['lat']; $lon=(float)$point['lon'];
        return is_finite($lat) && is_finite($lon) && $lat>=-33.5 && $lat<=-30.5 && $lon>=114.5 && $lon<=117.5;
    }

    private static function identifier(mixed $id): bool
    {
        return (is_int($id) || is_string($id)) && preg_match('/^[1-9][0-9]{0,18}$/',(string)$id)===1;
    }

    private static function sourceUrl(mixed $url,string $type,string $id): bool
    {
        if (!is_string($url)) { return false; }
        $parts=parse_url($url);
        $kind=['N'=>'node','W'=>'way','R'=>'relation'][$type] ?? '';
        return is_array($parts) && ($parts['scheme'] ?? '')==='https' && ($parts['host'] ?? '')==='www.openstreetmap.org'
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']) && !isset($parts['query']) && !isset($parts['fragment'])
            && ($parts['path'] ?? '')==='/'.$kind.'/'.$id;
    }

    private static function reviewDate(mixed $date): bool
    {
        if (!is_string($date)) { return false; }
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Australia/Perth'));
        return $parsed && $parsed->format('Y-m-d')===$date;
    }

    private static function distance(array $a,array $b): float
    {
        $lat=deg2rad(((float)$a['lat']+(float)$b['lat'])/2);
        return hypot(((float)$a['lat']-(float)$b['lat'])*111320,((float)$a['lon']-(float)$b['lon'])*111320*cos($lat));
    }
}
