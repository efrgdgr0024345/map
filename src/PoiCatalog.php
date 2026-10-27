<?php
declare(strict_types=1);
namespace App;
use InvalidArgumentException;
use RuntimeException;

/** Small, owner-managed public place catalogue. No runtime provider scraping. */
final class PoiCatalog
{
    public const MAX_BYTES=1048576;
    public static function validate(array $catalog): array
    {
        if (($catalog['schemaVersion'] ?? null)!==1 || !is_array($catalog['places'] ?? null) || !array_is_list($catalog['places']) || count($catalog['places'])>500) { throw new InvalidArgumentException('Use POI schemaVersion 1 and a list of up to 500 places.'); }
        $seen=[];
        foreach ($catalog['places'] as $p) {
            if (!is_array($p) || !is_string($p['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$p['id']) || isset($seen[$p['id']])) { throw new InvalidArgumentException('Every POI needs its own unique plain-text ID.'); }
            $seen[$p['id']]=true;
            if (!is_string($p['name'] ?? null) || trim($p['name'])==='' || strlen($p['name'])>300 || !self::point($p)) { throw new InvalidArgumentException('Each POI needs a name and valid Perth-region lat/lon numbers.'); }
            if (isset($p['navigation']) && (!is_array($p['navigation']) || !self::point($p['navigation']))) { throw new InvalidArgumentException('A POI navigation point needs valid lat/lon numbers.'); }
            if (!in_array($p['category'] ?? '',['attraction','museum','park','beach','shopping','venue','library','university','transport','landmark'],true)) { throw new InvalidArgumentException('Choose a supported POI category.'); }
            foreach (['location','address','openingHours','contact','whatsOn','accessibility','display','editorial','pricing'] as $field) {
                if (isset($p[$field]) && !is_array($p[$field])) { throw new InvalidArgumentException('POI '.$field.' must be an object or null.'); }
            }
            if (isset($p['display']['enabled']) && !is_bool($p['display']['enabled'])) { throw new InvalidArgumentException('POI display.enabled must be true or false.'); }
            if (isset($p['display']['priority']) && (!is_int($p['display']['priority']) || $p['display']['priority']<0 || $p['display']['priority']>10000)) { throw new InvalidArgumentException('POI display priority must be a whole number from zero to ten thousand.'); }
            if (isset($p['sources']) && (!is_array($p['sources']) || !array_is_list($p['sources']) || count($p['sources'])>20)) { throw new InvalidArgumentException('POI sources must be a list of up to twenty objects.'); }
            foreach ($p['sources'] ?? [] as $source) { if (!is_array($source) || (isset($source['url']) && !self::httpUrl($source['url']))) { throw new InvalidArgumentException('POI sources need valid optional HTTP/HTTPS links.'); } }
            if (isset($p['defaultStayMinutes']) && (!is_int($p['defaultStayMinutes']) || $p['defaultStayMinutes']<5 || $p['defaultStayMinutes']>720)) { throw new InvalidArgumentException('POI stay times must be 5–720 whole minutes.'); }
            if (isset($p['aliases']) && (!is_array($p['aliases']) || count($p['aliases'])>30)) { throw new InvalidArgumentException('Use up to thirty aliases per POI.'); }
            foreach ($p['aliases'] ?? [] as $alias) { if (!is_string($alias) || strlen($alias)>300) { throw new InvalidArgumentException('POI aliases must be short text.'); } }
            foreach (['website','bookingUrl'] as $field) { $url=$p['contact'][$field] ?? null; if ($url!==null && !self::httpUrl($url)) { throw new InvalidArgumentException('POI websites and booking links must use HTTP or HTTPS.'); } }
            $events=$p['whatsOn']['events'] ?? [];
            if (!is_array($events) || !array_is_list($events) || count($events)>50) { throw new InvalidArgumentException('Use a list of up to fifty events per POI.'); }
            foreach ($events as $event) {
                if (!is_array($event) || !is_string($event['title'] ?? null) || trim($event['title'])==='' || strlen($event['title'])>300) { throw new InvalidArgumentException('Each POI event needs a short title.'); }
                $start=GoogleCalendar::dateTime((string)($event['start'] ?? '')); $end=GoogleCalendar::dateTime((string)($event['end'] ?? ''));
                if ($end<=$start || (isset($event['url']) && !self::httpUrl($event['url']))) { throw new InvalidArgumentException('POI events need ordered times and a valid optional website.'); }
            }
        }
        return $catalog;
    }
    private static function point(array $p): bool
    {
        return isset($p['lat'],$p['lon']) && (is_int($p['lat']) || is_float($p['lat'])) && (is_int($p['lon']) || is_float($p['lon']))
            && is_finite((float)$p['lat']) && is_finite((float)$p['lon']) && $p['lat']>=-33.5 && $p['lat']<=-30.5 && $p['lon']>=114.5 && $p['lon']<=117.5;
    }
    public static function httpUrl(mixed $url): bool
    {
        if (!is_string($url) || strlen($url)>2048 || !filter_var($url,FILTER_VALIDATE_URL)) { return false; }
        $parts=parse_url($url);
        return in_array(strtolower($parts['scheme'] ?? ''),['http','https'],true) && !isset($parts['user']) && !isset($parts['pass']);
    }
    public static function load(?string $override=null,?string $bundled=null): array
    {
        $paths=['custom'=>$override ?? APP_VAR.'/perth-pois.json','bundled'=>$bundled ?? APP_ROOT.'/assets/perth-pois.json'];
        foreach ($paths as $origin=>$path) {
            if (!is_file($path) || is_link($path) || filesize($path)>self::MAX_BYTES) { continue; }
            try {
                $value=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
                $catalog=self::validate($value); $catalog['catalogOrigin']=$origin; return $catalog;
            } catch (\Throwable) { /* A damaged override cannot take down address search. */ }
        }
        throw new RuntimeException('The local Perth places catalogue is unavailable.');
    }
    public static function search(string $query,?array $catalog=null): array
    {
        $catalog ??= self::load();
        $tokens=preg_split('/[^\p{L}\p{N}]+/u',trim($query),-1,PREG_SPLIT_NO_EMPTY);
        if (!$tokens) { return []; }
        $matches=[];
        foreach ($catalog['places'] as $p) {
            if (($p['display']['enabled'] ?? true)!==true) { continue; }
            $names=array_merge([$p['name']],$p['aliases'] ?? []);
            $haystack=implode(' ',$names).' '.($p['address']['suburb'] ?? '');
            foreach ($tokens as $token) { if (!preg_match('/'.preg_quote($token,'/').'/iu',$haystack)) { continue 2; } }
            $exact=false; foreach ($names as $name) { if (strcasecmp($name,trim($query))===0) { $exact=true; break; } }
            $p['_score']=($exact ? 10000 : 0)+1000-(int)($p['display']['priority'] ?? 100);
            $matches[]=$p;
        }
        usort($matches,static fn($a,$b)=>$b['_score']<=>$a['_score']);
        return array_map([self::class,'place'],array_slice($matches,0,12));
    }
    public static function place(array $p): array
    {
        $point=$p['navigation'] ?? $p;
        return ['name'=>$p['name'],'lat'=>(float)$point['lat'],'lon'=>(float)$point['lon'],'poiId'=>$p['id'],'category'=>$p['category'],
            'stayMinutes'=>$p['defaultStayMinutes'] ?? 60,'requiresEntrance'=>empty($p['navigation']) && ($p['location']['kind'] ?? '')==='area-centre'];
    }
}
