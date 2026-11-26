<?php
declare(strict_types=1);
namespace App;

use RuntimeException;

/** Request-local OSM boundary/foot-network suggestion, never a verified entrance. */
final class WalkingAccess
{
    public static function resolve(array $point, ?callable $read=null): array
    {
        if (!isset($point['lat'],$point['lon']) || !is_numeric($point['lat']) || !is_numeric($point['lon'])
            || !is_finite((float)$point['lat']) || !is_finite((float)$point['lon']) || $point['lat'] < -33.5 || $point['lat'] > -30.5 || $point['lon'] < 114.5 || $point['lon'] > 117.5) {
            throw new \InvalidArgumentException('Choose an access point in the Perth region.');
        }
        $read ??= self::read(...);
        $lat=(float)$point['lat']; $lon=(float)$point['lon'];
        $bbox=sprintf('%.6F,%.6F,%.6F,%.6F',$lon-.004,$lat-.004,$lon+.004,$lat+.004);
        $map=$read('https://api.openstreetmap.org/api/0.6/map.json?bbox='.$bbox,5);
        $polygon=self::polygon($map,$point);
        if (!$polygon) { return ['available'=>false,'message'=>'No mapped building or site boundary was found here. Choose a nearby public entrance.']; }
        $boundary=self::boundaryPoint($polygon['coordinates'][0],$point);
        $url=sprintf('https://routing.openstreetmap.de/routed-foot/nearest/v1/driving/%.6F,%.6F?number=5&generate_hints=false',$boundary['lon'],$boundary['lat']);
        $nearest=$read($url,4);
        if (($nearest['code'] ?? '')!=='Ok') { throw new RuntimeException('Walking access lookup is temporarily unavailable.'); }
        $choices=[];
        foreach ($nearest['waypoints'] ?? [] as $w) {
            $p=$w['location'] ?? null;
            if (!is_array($p) || count($p)!==2 || !is_numeric($p[0]) || !is_numeric($p[1]) || !is_finite((float)$p[0]) || !is_finite((float)$p[1])) { continue; }
            $candidate=['lat'=>(float)$p[1],'lon'=>(float)$p[0]];
            $edge=self::boundaryPoint($polygon['coordinates'][0],$candidate);
            if (Geo::distance($candidate,$edge)>75 || Geo::distance($point,$candidate)>500) { continue; }
            if (self::contains($polygon['coordinates'][0],$candidate) && Geo::distance($candidate,$edge)>1) { continue; }
            // A courtyard is not an outside arrival point.
            $inHole=false; foreach (array_slice($polygon['coordinates'],1) as $hole) { if (self::contains($hole,$candidate)) { $inHole=true; break; } }
            if ($inHole) { continue; }
            $choices[]=$candidate+['name'=>is_string($w['name'] ?? null)&&$w['name']!=='' ? substr($w['name'],0,160) : 'Nearby mapped walking point','distanceFromSelection'=>round(Geo::distance($point,$candidate),1)];
        }
        usort($choices,static fn($a,$b)=>$a['distanceFromSelection']<=>$b['distanceFromSelection']);
        return ['available'=>(bool)$choices,'polygon'=>$polygon,'nearestWalkingPoint'=>$choices[0] ?? null,'requestedPoint'=>['lat'=>$lat,'lon'=>$lon],
            'approximate'=>true,'source'=>'OpenStreetMap / FOSSGIS OSRM foot','attribution'=>'© OpenStreetMap contributors',
            'message'=>$choices ? 'Suggested walking point outside the mapped boundary. Entrance, crossings and access on the site are not verified. The route will be checked when you use this point.' : 'No nearby outside walking point was found. Choose a public entrance on the map.'];
    }

    /** Closed OSM ways and complete multipolygon rings; incomplete relations are ignored. */
    public static function polygon(array $data,array $point): ?array
    {
        $nodes=[];$ways=[];$elements=$data['elements'] ?? [];$found=[];$relationWays=[];
        if (!is_array($elements) || count($elements)>60000) { throw new RuntimeException('The access map response is too large.'); }
        foreach ($elements as $e) {
            $tags=$e['tags'] ?? [];
            if (($e['type'] ?? '')==='relation' && ($tags['type'] ?? '')==='multipolygon'
                && (isset($tags['building']) || isset($tags['shop']) || in_array($tags['amenity'] ?? '',['hospital','school','university','college'],true)
                    || in_array($tags['leisure'] ?? '',['stadium','sports_centre'],true) || ($tags['tourism'] ?? '')==='zoo')) {
                foreach ($e['members'] ?? [] as $member) { if (($member['type'] ?? '')==='way' && isset($member['ref'])) { $relationWays[(string)$member['ref']]=true; } }
            }
            if (($e['type'] ?? '')==='node' && isset($e['id'],$e['lat'],$e['lon']) && is_numeric($e['lat']) && is_numeric($e['lon']) && is_finite((float)$e['lat']) && is_finite((float)$e['lon']) && abs((float)$e['lat'])<=90 && abs((float)$e['lon'])<=180) { $nodes[(string)$e['id']]=[(float)$e['lon'],(float)$e['lat']]; }
            if (($e['type'] ?? '')==='way' && isset($e['id'])) { $ways[(string)$e['id']]=$e; }
        }
        $ring=static function(array $way)use($nodes): ?array {
            $ids=$way['nodes'] ?? []; if (!is_array($ids) || count($ids)<4 || count($ids)>10000 || reset($ids)!==end($ids)) { return null; }
            $out=[]; foreach ($ids as $id) { if (!isset($nodes[(string)$id])) { return null; } $out[]=$nodes[(string)$id]; } return $out;
        };
        foreach ($elements as $e) {
            if (($e['type'] ?? '')==='way' && isset($relationWays[(string)($e['id'] ?? '')])) { continue; }
            $tags=$e['tags'] ?? [];
            if (!isset($tags['building']) && !in_array($tags['amenity'] ?? '',['hospital','school','university','college'],true)
                && !in_array($tags['leisure'] ?? '',['stadium','sports_centre'],true) && ($tags['tourism'] ?? '')!=='zoo' && !isset($tags['shop'])) { continue; }
            $outer=[];$holes=[];
            if (($e['type'] ?? '')==='way') { $r=$ring($e); if ($r) { $outer[]=$r; } }
            elseif (($e['type'] ?? '')==='relation' && ($tags['type'] ?? '')==='multipolygon') {
                $complete=true;
                foreach ($e['members'] ?? [] as $member) {
                    if (($member['type'] ?? '')!=='way') { continue; }
                    $r=isset($ways[(string)$member['ref']]) ? $ring($ways[(string)$member['ref']]) : null;
                    if (!$r) { $complete=false; break; }
                    if (($member['role'] ?? '')==='inner') { $holes[]=$r; } else { $outer[]=$r; }
                }
                if (!$complete) { continue; }
            }
            foreach ($outer as $r) {
                if (!self::contains($r,$point)) { continue; }
                $insideHole=false; foreach ($holes as $h) { if (self::contains($h,$point)) { $insideHole=true; break; } }
                if ($insideHole) { continue; }
                $area=0.0;for($i=1;$i<count($r);$i++){$area+=$r[$i-1][0]*$r[$i][1]-$r[$i][0]*$r[$i-1][1];}
                $ownHoles=array_values(array_filter($holes,static fn($h)=>self::contains($r,['lon'=>$h[0][0],'lat'=>$h[0][1]])));
                $found[]=['type'=>'Polygon','coordinates'=>array_merge([$r],$ownHoles),'osmType'=>$e['type'],'osmId'=>(string)$e['id'],
                    'name'=>is_string($tags['name'] ?? null)?substr($tags['name'],0,160):'Mapped building or site','boundaryType'=>isset($tags['building'])?'building':'site','area'=>abs($area)];
            }
        }
        usort($found,static fn($a,$b)=>$a['area']<=>$b['area']);
        if(!$found)return null;$p=$found[0];unset($p['area']);return $p;
    }

    private static function contains(array $ring,array $p): bool
    {
        $in=false;$x=$p['lon'];$y=$p['lat'];
        for($i=0,$j=count($ring)-1;$i<count($ring);$j=$i++){
            [$a,$b]=$ring[$i];[$c,$d]=$ring[$j];
            if(($b>$y)!==($d>$y) && $x<($c-$a)*($y-$b)/($d-$b)+$a)$in=!$in;
        }return $in;
    }

    private static function boundaryPoint(array $ring,array $p): array
    {
        $scale=cos(deg2rad($p['lat']));$best=null;$distance=INF;
        for($i=1;$i<count($ring);$i++){
            [$a,$b]=$ring[$i-1];[$c,$d]=$ring[$i];$dx=($c-$a)*$scale;$dy=$d-$b;
            $t=max(0,min(1,(($p['lon']-$a)*$scale*$dx+($p['lat']-$b)*$dy)/max(1e-20,$dx*$dx+$dy*$dy)));
            $q=['lon'=>$a+($c-$a)*$t,'lat'=>$b+($d-$b)*$t];$gap=Geo::distance($p,$q);
            if($gap<$distance){$distance=$gap;$best=$q;}
        }return $best;
    }

    private static function read(string $url,int $timeout): array
    {
        $body='';$curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_CONNECTTIMEOUT=>min(3,$timeout),CURLOPT_TIMEOUT=>$timeout,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'Nextstop/1.0 (https://github.com/efrgdgr0024345/map)',CURLOPT_WRITEFUNCTION=>static function($h,string $s)use(&$body):int{
                if(strlen($body)+strlen($s)>4*1024*1024)return 0;$body.=$s;return strlen($s);
            }]);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$curl=null;
        $p=json_decode($body,true,64);
        if($ok===false||$status!==200||!is_array($p))throw new RuntimeException('Walking access lookup is temporarily unavailable.');
        return $p;
    }
}
