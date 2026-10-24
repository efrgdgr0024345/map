<?php
declare(strict_types=1);
namespace App;

use RuntimeException;

final class Geocoder
{
    public static function search(string $query): array
    {
        $cache=APP_VAR.'/geocode-'.hash('sha256',strtolower($query)).'.php';
        if (is_file($cache) && filemtime($cache)>time()-86400) {
            $text=file_get_contents($cache);
            $data=json_decode(substr($text,strpos($text,"\n")+1),true);
            if (is_array($data)) { return $data; }
        }
        if (!function_exists('curl_init')) { throw new RuntimeException('Address search needs the PHP cURL extension. You can still tap the map.'); }
        $url='https://photon.komoot.io/api/?'.http_build_query(['q'=>$query,'lat'=>-31.9523,'lon'=>115.8613,'limit'=>8,'bbox'=>'114.5,-33.5,117.5,-30.5','lang'=>'en']);
        $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_USERAGENT=>'PerthMove/0.1 (Perth address lookup)',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        $body=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
        if ($body===false || $status!==200) { throw new RuntimeException('Address search is temporarily unavailable. Tap your destination on the map instead.'); }
        $data=json_decode($body,true);
        if (!is_array($data) || !isset($data['features'])) { throw new RuntimeException('Address search returned an unexpected response. Tap the map instead.'); }
        $results=[];
        foreach ($data['features'] as $feature) {
            $p=$feature['properties'] ?? []; $coords=$feature['geometry']['coordinates'] ?? [];
            if (count($coords)!==2 || $coords[0]<114.5 || $coords[0]>117.5 || $coords[1]<-33.5 || $coords[1]>-30.5) { continue; }
            $address=trim(($p['housenumber'] ?? '').' '.($p['street'] ?? ''));
            $name=implode(', ',array_unique(array_filter([$p['name'] ?? '',$address,$p['suburb'] ?? $p['city'] ?? '',$p['state'] ?? ''])));
            if (!$name) { continue; }
            $results[]=['name'=>$name,'lat'=>(float)$coords[1],'lon'=>(float)$coords[0]];
        }
        $result=['results'=>$results,'attribution'=>'Address search: Photon / OpenStreetMap contributors'];
        if (is_dir(APP_VAR) && is_writable(APP_VAR)) { file_put_contents($cache,"<?php http_response_code(404); exit; ?>\n".json_encode($result),LOCK_EX); }
        return $result;
    }
}
