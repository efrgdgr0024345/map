<?php
declare(strict_types=1);
namespace App;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Public stop-area guidance; no station floor plans or indoor paths are invented. */
final class TransferGuide
{
    private const NEARBY_METRES = 350.0;
    private const MAX_TRANSFER_METRES = 2000.0;
    private const MAX_STOPS = 250;

    /**
     * The database/provider arguments are local dependencies for testing, never
     * request parameters. Production callers supply only the first four values.
     */
    public static function guide(string $fromId,string $toId,?string $tripId=null,?string $serviceDate=null,?string $database=null,?callable $router=null): array
    {
        self::identifier($fromId); self::identifier($toId);
        if ($tripId!==null) { self::identifier($tripId); }
        if ($serviceDate!==null) {
            $date=\DateTimeImmutable::createFromFormat('!Ymd',$serviceDate,new \DateTimeZone('Australia/Perth'));
            if (!$date || $date->format('Ymd')!==$serviceDate) { throw new InvalidArgumentException('Choose a valid transfer service date (YYYYMMDD).'); }
        }
        $database ??= APP_VAR.'/transit.sqlite';
        if (!is_file($database) || !is_readable($database) || is_link($database)) {
            throw new RuntimeException('Import a timetable before opening station guidance.');
        }
        $db=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=3000;');
        $transit=new Transit($database);
        if ($transit->status()['expired']) { throw new RuntimeException('The timetable has expired. Refresh it before using current transfer guidance.'); }
        $from=self::stop($db,$fromId); $to=self::stop($db,$toId);
        $straightDistance=Geo::distance($from,$to);
        if ($straightDistance>self::MAX_TRANSFER_METRES) { throw new InvalidArgumentException('Choose stops within the same local transfer area (at most 2 km apart).'); }

        $trip=null;
        if ($tripId!==null) {
            $table=$transit->timetable('',$tripId,null,$serviceDate);
            $canBoard=false;
            foreach ($table['stops'] as $visit) {
                if ($visit['id']===$toId && (int)$visit['pickupType']!==1) { $canBoard=true; break; }
            }
            if (!$canBoard) { throw new InvalidArgumentException('The selected service does not pick up passengers at this boarding stop.'); }
            $trip=$table['trip'];
        }
        $stops=self::nearby($db,$from,$to);
        $result=['mode'=>'transfer','timezone'=>'Australia/Perth','generatedAt'=>date(DATE_ATOM),
            'from'=>$from,'to'=>$to,'stops'=>$stops,'geometry'=>[], 'distance'=>null,'duration'=>null,
            'routing'=>'unavailable','routingSource'=>null,'accessConnectors'=>[], 'pathAvailable'=>false,
            'station'=>['name'=>self::name($from['name'],$to['name']),'kind'=>'stop-area','indoorMap'=>false,'bounds'=>[]],
            'message'=>''];
        if ($trip!==null) { $result['trip']=$trip; $result['serviceDate']=$trip['serviceDate']; }
        if ($straightDistance<=0.5) {
            $result['geometry']=[[$to['lat'],$to['lon']]];
            $result['distance']=0.0; $result['duration']=0;
            $result['routing']='stationary'; $result['routingSource']='GTFS stop locations';
            $result['pathAvailable']=true;
        } else {
            $router ??= static fn(array $a,array $b): array => WalkingRouter::route($a,$b);
            try {
                $path=$router($from,$to);
                if (!self::usablePath($path)) { throw new RuntimeException('The pedestrian route is incomplete.'); }
                foreach (['geometry','distance','duration','routingSource','accessConnectors','snappedFrom','snappedTo'] as $key) {
                    if (array_key_exists($key,$path)) { $result[$key]=$path[$key]; }
                }
                $result['duration']=(int)ceil((float)$result['duration']);
                $result['distance']=(float)$result['distance'];
                $result['routing']='street'; $result['pathAvailable']=true;
            } catch (\Throwable) {
                // Keep the useful station/target context without a fictional chord.
                $result['message']='Walking guidance is unavailable. Check the platform or stop sign and try again.';
            }
        }
        $boundsPoints=array_map(static fn($stop)=>[$stop['lat'],$stop['lon']],$stops);
        foreach ($result['geometry'] as $point) { $boundsPoints[]=$point; }
        foreach ($result['accessConnectors'] as $connector) {
            foreach ($connector['geometry'] ?? [] as $point) { $boundsPoints[]=$point; }
        }
        $result['station']['bounds']=self::bounds($boundsPoints);
        return $result;
    }

    private static function identifier(string $id): void
    {
        if ($id==='' || strlen($id)>200 || preg_match('/[\x00-\x1f\x7f]/',$id)) {
            throw new InvalidArgumentException('Choose a valid stop or service.');
        }
    }

    private static function stop(PDO $db,string $id): array
    {
        $query=$db->prepare('SELECT id,name,lat,lon FROM stops WHERE id=?'); $query->execute([$id]);
        $stop=$query->fetch();
        if (!$stop || !is_numeric($stop['lat']) || !is_numeric($stop['lon'])) { throw new InvalidArgumentException('This transfer stop was not found in the timetable.'); }
        $stop['lat']=(float)$stop['lat']; $stop['lon']=(float)$stop['lon'];
        if (!is_finite($stop['lat']) || !is_finite($stop['lon']) || abs($stop['lat'])>90 || abs($stop['lon'])>180) {
            throw new RuntimeException('The timetable contains an invalid stop location.');
        }
        return $stop;
    }

    private static function nearby(PDO $db,array $from,array $to): array
    {
        $parts=[]; $parameters=[];
        foreach ([$from,$to] as $point) {
            $dlat=self::NEARBY_METRES/111000;
            $dlon=self::NEARBY_METRES/(111000*max(0.01,cos(deg2rad($point['lat']))));
            $parts[]='(lat BETWEEN ? AND ? AND lon BETWEEN ? AND ?)';
            array_push($parameters,$point['lat']-$dlat,$point['lat']+$dlat,$point['lon']-$dlon,$point['lon']+$dlon);
        }
        $query=$db->prepare('SELECT id,name,lat,lon FROM stops WHERE '.implode(' OR ',$parts)); $query->execute($parameters);
        $stops=[];
        foreach ($query as $stop) {
            $stop['lat']=(float)$stop['lat']; $stop['lon']=(float)$stop['lon'];
            if (!is_finite($stop['lat']) || !is_finite($stop['lon']) || abs($stop['lat'])>90 || abs($stop['lon'])>180) { continue; }
            $distance=min(Geo::distance($from,$stop),Geo::distance($to,$stop));
            if ($distance<=self::NEARBY_METRES || $stop['id']===$from['id'] || $stop['id']===$to['id']) {
                $stop['target']=$stop['id']===$to['id']; $stop['origin']=$stop['id']===$from['id'];
                $stop['_distance']=$distance; $stops[$stop['id']]=$stop;
            }
        }
        // Explicit endpoints survive any crowded-area cap and database ordering.
        foreach ([$from,$to] as $stop) {
            $stops[$stop['id']]=$stop+['target'=>$stop['id']===$to['id'],'origin'=>$stop['id']===$from['id'],'_distance'=>0.0];
        }
        $stops=array_values($stops);
        usort($stops,static fn($a,$b)=>[!$a['target'],!$a['origin'],$a['_distance'],$a['id']]<=>[!$b['target'],!$b['origin'],$b['_distance'],$b['id']]);
        $stops=array_slice($stops,0,self::MAX_STOPS);
        foreach ($stops as &$stop) { unset($stop['_distance']); } unset($stop);
        return $stops;
    }

    private static function usablePath(array $path): bool
    {
        if (($path['routing'] ?? '')!=='street' || ($path['approximate'] ?? false)!==false
            || !is_string($path['routingSource'] ?? null) || trim($path['routingSource'])===''
            || !is_numeric($path['duration'] ?? null) || !is_numeric($path['distance'] ?? null)
            || !is_finite((float)$path['duration']) || !is_finite((float)$path['distance'])
            || $path['duration']<=0 || $path['duration']>86400 || $path['distance']<=0 || $path['distance']>500000
            || !is_array($path['geometry'] ?? null) || count($path['geometry'])<2 || count($path['geometry'])>50000) { return false; }
        foreach ($path['geometry'] as $point) {
            if (!self::coordinate($point)) { return false; }
        }
        if (!is_array($path['accessConnectors'] ?? [])) { return false; }
        foreach ($path['accessConnectors'] ?? [] as $connector) {
            if (!is_array($connector) || ($connector['routing'] ?? '')!=='unrouted_access'
                || ($connector['approximate'] ?? false)!==true || !is_array($connector['geometry'] ?? null)
                || count($connector['geometry'])!==2) { return false; }
            foreach ($connector['geometry'] as $point) { if (!self::coordinate($point)) { return false; } }
        }
        return true;
    }

    private static function coordinate(mixed $point): bool
    {
        return is_array($point) && array_is_list($point) && count($point)===2 && is_numeric($point[0]) && is_numeric($point[1])
            && is_finite((float)$point[0]) && is_finite((float)$point[1]) && abs((float)$point[0])<=90 && abs((float)$point[1])<=180;
    }

    private static function name(string $from,string $to): string
    {
        $strip=static fn(string $name): string => trim(preg_replace('/\s+(?:platform|stand|bay)\s+.+$/i','',$name) ?? $name);
        $a=$strip($from); $b=$strip($to);
        return strcasecmp($a,$b)===0 ? $a : $a.' → '.$b;
    }

    private static function bounds(array $points): array
    {
        $lats=array_column($points,0); $lons=array_column($points,1);
        // A small map margin prevents endpoint markers sitting on the canvas edge.
        $dlat=25/111000; $centre=(min($lats)+max($lats))/2;
        $dlon=25/(111000*max(0.01,cos(deg2rad($centre))));
        return [max(-180,min($lons)-$dlon),max(-90,min($lats)-$dlat),min(180,max($lons)+$dlon),min(90,max($lats)+$dlat)];
    }
}
