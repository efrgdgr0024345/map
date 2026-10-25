<?php
declare(strict_types=1);
namespace App;

use RuntimeException;

final class Geocoder
{
    private const CACHE_PREFIX='geocode-v2-';
    public static function search(string $query): array
    {
        $cache=APP_VAR.'/'.self::CACHE_PREFIX.hash('sha256',strtolower($query)).'.php';
        if (is_file($cache) && !is_link($cache) && filesize($cache)<2*1024*1024 && filemtime($cache)>time()-86400) {
            $text=file_get_contents($cache);
            $data=json_decode(substr($text,strpos($text,"\n")+1),true);
            if (is_array($data)) { return $data; }
        }
        if (!function_exists('curl_init')) { throw new RuntimeException('Address search needs the PHP cURL extension. You can still tap the map.'); }
        $url='https://photon.komoot.io/api/?'.http_build_query(['q'=>$query,'lat'=>-31.9523,'lon'=>115.8613,'limit'=>8,'bbox'=>'114.5,-33.5,117.5,-30.5','lang'=>'en']);
        $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_USERAGENT=>'PerthMove/0.1 (Perth address lookup)',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        $body=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); $curl=null;
        if ($body===false || $status!==200) { throw new RuntimeException('Address search is temporarily unavailable. Tap your destination on the map instead.'); }
        $result=self::parseResponse($body);
        if (is_dir(APP_VAR) && is_writable(APP_VAR) && !is_link($cache)) { file_put_contents($cache,"<?php http_response_code(404); exit; ?>\n".json_encode($result),LOCK_EX); }
        return $result;
    }

    /** Parse Photon results without HTTP; the catalog argument is a local test dependency. */
    public static function parseResponse(string $body,?string $accessCatalog=null): array
    {
        $data=strlen($body)<=2*1024*1024 ? json_decode($body,true) : null;
        if (!is_array($data) || !is_array($data['features'] ?? null) || !array_is_list($data['features'])) { throw new RuntimeException('Address search returned an unexpected response. Tap the map instead.'); }
        $results=[];
        $text=static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '';
        foreach ($data['features'] as $feature) {
            if (!is_array($feature)) { continue; }
            $p=$feature['properties'] ?? []; $coords=$feature['geometry']['coordinates'] ?? [];
            if (!is_array($p) || !is_array($coords) || count($coords)!==2 || !is_numeric($coords[0]) || !is_numeric($coords[1])
                || !is_finite((float)$coords[0]) || !is_finite((float)$coords[1]) || $coords[0]<114.5 || $coords[0]>117.5 || $coords[1]<-33.5 || $coords[1]>-30.5) { continue; }
            $address=trim($text($p['housenumber'] ?? '').' '.$text($p['street'] ?? ''));
            $name=implode(', ',array_unique(array_filter([$text($p['name'] ?? ''),$address,$text($p['suburb'] ?? $p['city'] ?? ''),$text($p['state'] ?? '')])));
            if (!$name) { continue; }
            $place=['name'=>$name,'lat'=>(float)$coords[1],'lon'=>(float)$coords[0]];
            foreach (['osm_type'=>'osmType','osm_id'=>'osmId','osm_key'=>'osmKey','osm_value'=>'osmValue'] as $source=>$target) {
                if (isset($p[$source]) && is_scalar($p[$source])) { $place[$target]=(string)$p[$source]; }
            }
            $access=PlaceAccess::resolve($p,$place,$accessCatalog);
            if ($access) {
                $place=array_merge($place,$access);
                $label=$access['venueName'].' ('.$access['accessName'].')';
                $place['name']=implode(', ',array_unique(array_filter([$label,$address,$text($p['suburb'] ?? $p['city'] ?? ''),$text($p['state'] ?? '')])));
            }
            $results[]=$place;
        }
        $result=['results'=>$results,'attribution'=>'Address search: Photon / OpenStreetMap contributors'];
        return $result;
    }
}
