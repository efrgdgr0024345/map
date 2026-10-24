<?php
declare(strict_types=1);
namespace App;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Read the bundled, attributed OSM station extracts; never download on a map pan. */
final class StationMap
{
    private const KINDS=['platform','building','footway','steps','rail','entrance','lift','crossing','bus_stop'];
    private const PLANS=[
        '83'=>'Canning Bridge', '96'=>'Murdoch', '64'=>'Perth Underground',
        '56'=>'Perth', '87'=>'Fremantle', '94'=>'Mandurah',
    ];

    /** Last two arguments are local test dependencies, never HTTP parameters. */
    public static function forStops(string $fromId,string $toId,?string $database=null,?string $catalogRoot=null): array
    {
        foreach ([$fromId,$toId] as $id) {
            if ($id==='' || strlen($id)>200 || preg_match('/[\x00-\x1f\x7f]/',$id)) {
                throw new InvalidArgumentException('Choose valid station stops.');
            }
        }
        $database ??= APP_VAR.'/transit.sqlite';
        if (!is_file($database) || !is_readable($database) || is_link($database)) {
            throw new RuntimeException('Import the timetable before opening station detail.');
        }
        $db=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=3000;');
        $points=[];
        $query=$db->prepare('SELECT id,name,lat,lon FROM stops WHERE id=?');
        foreach ([$fromId,$toId] as $id) {
            $query->execute([$id]); $stop=$query->fetch();
            if (!$stop || !is_numeric($stop['lat']) || !is_numeric($stop['lon'])) {
                throw new InvalidArgumentException('This station stop was not found in the timetable.');
            }
            $stop['lat']=(float)$stop['lat']; $stop['lon']=(float)$stop['lon']; $points[]=$stop;
        }
        if (Geo::distance($points[0],$points[1])>2000) {
            throw new InvalidArgumentException('Choose stops within the same local transfer area.');
        }
        $result=['available'=>false,'stale'=>false,'source'=>'OpenStreetMap','sourceUrl'=>'https://www.openstreetmap.org/copyright',
            'attribution'=>'© OpenStreetMap contributors','license'=>'ODbL 1.0','updatedAt'=>null,
            'stations'=>[],'levels'=>[],'featureCollection'=>['type'=>'FeatureCollection','features'=>[]]];
        $catalogRoot ??= APP_ROOT.'/assets/station-maps';
        if (is_link($catalogRoot) || !is_dir($catalogRoot)) { return $result; }
        try { $manifest=self::read($catalogRoot.'/manifest.json',1024*1024); }
        catch (\Throwable) { return $result; }
        if (($manifest['version'] ?? null)!==1 || !is_array($manifest['stations'] ?? null)
            || !is_array($manifest['stopToStation'] ?? null)) { return $result; }
        $source=is_array($manifest['source'] ?? null) ? $manifest['source'] : [];
        $result['source']=self::text($source['name'] ?? 'Geofabrik / OpenStreetMap');
        $result['sourceUrl']='https://download.geofabrik.de/australia-oceania/australia/western-australia.html';
        $updated=$source['updatedAt'] ?? null;
        if (is_string($updated) && strtotime($updated)!==false) {
            $result['updatedAt']=date(DATE_ATOM,strtotime($updated));
            $result['stale']=time()-strtotime($updated)>90*86400;
        }
        $ids=[];
        foreach ([$fromId,$toId] as $id) {
            $parent=$manifest['stopToStation'][$id] ?? (isset($manifest['stations'][$id]) ? $id : null);
            if (is_scalar($parent) && isset($manifest['stations'][(string)$parent])) { $ids[(string)$parent]=true; }
        }
        // A nearby standalone bus sign can be outside the GTFS parent group.
        // Include only station extracts whose actual recorded bounds contain it.
        foreach ($points as $point) {
            if (count($ids)>=2) { break; }
            $covered=false;
            foreach (array_keys($ids) as $id) {
                $bounds=$manifest['stations'][$id]['bounds'] ?? null;
                if (self::bounds($bounds) && Geo::inside($point,$bounds)) { $covered=true; break; }
            }
            if ($covered) { continue; }
            $candidates=[];
            foreach ($manifest['stations'] as $id=>$station) {
                $bounds=$station['bounds'] ?? null;
                if (!self::bounds($bounds) || !Geo::inside($point,$bounds)) { continue; }
                $centre=['lat'=>($bounds[1]+$bounds[3])/2,'lon'=>($bounds[0]+$bounds[2])/2];
                $candidates[(string)$id]=Geo::distance($point,$centre);
            }
            if ($candidates) { asort($candidates,SORT_NUMERIC); $ids[(string)array_key_first($candidates)]=true; }
        }
        $features=[]; $levels=[]; $vertices=0;
        foreach (array_slice(array_keys($ids),0,2) as $id) {
            $station=$manifest['stations'][$id]; $file=$station['file'] ?? '';
            if (!is_string($file) || !preg_match('/^[a-zA-Z0-9_-]{1,100}\.(?:json|geojson)$/',$file)) { continue; }
            try { $collection=self::read($catalogRoot.'/'.$file,4*1024*1024); }
            catch (\Throwable) { continue; }
            if (($collection['type'] ?? '')!=='FeatureCollection' || !is_array($collection['features'] ?? null)) { continue; }
            $entry=['id'=>(string)$id,'name'=>self::text($station['name'] ?? ''),'bounds'=>$station['bounds'] ?? null];
            if (isset(self::PLANS[$id])) {
                $entry['officialPlanUrl']='https://www.transperth.wa.gov.au/timetablePDFs/BusStationMaps/'.rawurlencode(self::PLANS[$id].' Station').'.pdf';
            }
            $result['stations'][]=$entry;
            foreach ($collection['features'] as $feature) {
                if (count($features)>=6000 || $vertices>=250000) { break; }
                $clean=self::feature($feature,$vertices);
                if ($clean===null) { continue; }
                $features[$clean['id']]=$clean;
                foreach (preg_split('/[;,]/',$clean['properties']['level'] ?? '') ?: [] as $level) {
                    $level=trim($level);
                    if (preg_match('/^-?\d+(?:\.\d+)?$/',$level)) { $levels[$level]=true; }
                }
            }
        }
        $result['featureCollection']['features']=array_values($features);
        $result['available']=(bool)$features;
        $result['levels']=array_map('strval',array_keys($levels));
        usort($result['levels'],static fn($a,$b)=>(float)$a<=>(float)$b);
        return $result;
    }

    private static function read(string $path,int $maximum): array
    {
        if (!is_file($path) || is_link($path) || filesize($path)>$maximum) { throw new RuntimeException('Station data is unavailable.'); }
        $content=file_get_contents($path);
        if (!is_string($content) || strlen($content)>$maximum) { throw new RuntimeException('Station data is unavailable.'); }
        $data=json_decode($content,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new RuntimeException('Station data is unavailable.'); }
        return $data;
    }

    private static function feature(mixed $feature,int &$vertices): ?array
    {
        if (!is_array($feature) || ($feature['type'] ?? '')!=='Feature' || !is_array($feature['properties'] ?? null)
            || !is_string($feature['id'] ?? null) || !preg_match('~^(?:node|way|relation)/[0-9]+$~',$feature['id'])
            || !in_array($feature['properties']['kind'] ?? '',self::KINDS,true)) { return null; }
        $geometry=$feature['geometry'] ?? null;
        if (!is_array($geometry) || !in_array($geometry['type'] ?? '',['Point','LineString','Polygon','MultiPolygon'],true)) { return null; }
        $coordinates=$geometry['coordinates'] ?? null; $count=0;
        if (!self::coordinates($coordinates,$count) || $count===0 || $count>30000) { return null; }
        if ($geometry['type']==='Point' && (!is_array($coordinates) || count($coordinates)!==2 || !is_numeric($coordinates[0]))) { return null; }
        if ($geometry['type']==='LineString' && !self::line($coordinates,2)) { return null; }
        if ($geometry['type']==='Polygon' && !self::polygon($coordinates)) { return null; }
        if ($geometry['type']==='MultiPolygon') {
            foreach ($coordinates as $polygon) { if (!self::polygon($polygon)) { return null; } }
        }
        $vertices+=$count;
        $properties=[];
        foreach (['kind','name','ref','ptaRef','localRef','level','layer','access','foot','wheelchair','bridge','tunnel','indoor','gtfsStopId'] as $key) {
            if (isset($feature['properties'][$key]) && is_scalar($feature['properties'][$key])) { $properties[$key]=self::text($feature['properties'][$key]); }
        }
        if (is_array($feature['properties']['gtfsStopIds'] ?? null)) {
            $properties['gtfsStopIds']=array_map([self::class,'text'],array_slice($feature['properties']['gtfsStopIds'],0,10));
        }
        $properties['restricted']=in_array($properties['access'] ?? '',['no','private'],true) || in_array($properties['foot'] ?? '',['no','private'],true);
        return ['type'=>'Feature','id'=>$feature['id'],'geometry'=>['type'=>$geometry['type'],'coordinates'=>$coordinates],'properties'=>$properties];
    }

    private static function coordinates(mixed $coordinates,int &$count,int $depth=0): bool
    {
        if (!is_array($coordinates) || !array_is_list($coordinates) || !$coordinates || $depth>4 || $count>30000) { return false; }
        if (count($coordinates)===2 && is_numeric($coordinates[0]) && is_numeric($coordinates[1])) {
            if (!is_finite((float)$coordinates[0]) || !is_finite((float)$coordinates[1]) || abs((float)$coordinates[0])>180 || abs((float)$coordinates[1])>90) { return false; }
            $count++; return true;
        }
        foreach ($coordinates as $item) { if (!self::coordinates($item,$count,$depth+1)) { return false; } }
        return true;
    }

    private static function bounds(mixed $bounds): bool
    {
        return is_array($bounds) && array_is_list($bounds) && count($bounds)===4
            && count(array_filter($bounds,'is_numeric'))===4 && $bounds[0]<$bounds[2] && $bounds[1]<$bounds[3];
    }

    private static function line(mixed $line,int $minimum): bool
    {
        if (!is_array($line) || !array_is_list($line) || count($line)<$minimum) { return false; }
        foreach ($line as $point) {
            if (!is_array($point) || !array_is_list($point) || count($point)!==2 || !is_numeric($point[0]) || !is_numeric($point[1])) { return false; }
        }
        return true;
    }

    private static function polygon(mixed $rings): bool
    {
        if (!is_array($rings) || !array_is_list($rings) || !$rings) { return false; }
        foreach ($rings as $ring) {
            if (!self::line($ring,4) || $ring[0]!==$ring[count($ring)-1]) { return false; }
        }
        return true;
    }

    private static function text(mixed $value): string { return is_scalar($value) ? substr((string)$value,0,256) : ''; }
}
