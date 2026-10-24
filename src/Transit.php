<?php
declare(strict_types=1);
namespace App;

use PDO;
use RuntimeException;

final class Transit
{
    private PDO $db;
    private array $shapes=[];

    public function __construct(?string $path = null)
    {
        $this->db=new PDO('sqlite:'.($path ?? APP_VAR.'/transit.sqlite'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=3000;');
    }

    public function status(): array
    {
        $meta=$this->db->query('SELECT key,value FROM meta')->fetchAll(PDO::FETCH_KEY_PAIR);
        $expired=($meta['end_date'] ?? '')<date('Ymd');
        return ['mode'=>'timetable','timezone'=>'Australia/Perth','updatedAt'=>$meta['imported_at'] ?? null,'validUntil'=>$meta['end_date'] ?? null,'expired'=>$expired,'realtime'=>false,
            'message'=>$expired ? 'Timetable expired. Import a current feed; current services cannot be estimated.' : 'Positions are estimated from the timetable, not live vehicle GPS. Walking paths are approximate straight lines.'];
    }

    /** Service days include yesterday for trips whose GTFS clocks pass 24:00. */
    public function serviceDays(int $now, int $horizon=0): array
    {
        $days=[];
        $midnight=strtotime(date('Y-m-d',$now));
        foreach ([-86400,0,86400] as $offset) {
            $base=$midnight+$offset;
            if ($base>$now+$horizon) { continue; }
            $date=date('Ymd',$base);
            $col=['mon','tue','wed','thu','fri','sat','sun'][(int)date('N',$base)-1];
            $q=$this->db->prepare("SELECT service_id FROM calendar WHERE start<=? AND end>=? AND $col=1");
            $q->execute([$date,$date]);
            $ids=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);
            $q=$this->db->prepare('SELECT service_id,type FROM exceptions WHERE date=?'); $q->execute([$date]);
            foreach ($q as $row) {
                if ((int)$row['type']===1) { $ids[$row['service_id']]=true; } else { unset($ids[$row['service_id']]); }
            }
            if ($ids) { $days[]=['base'=>$base,'date'=>$date,'ids'=>array_keys($ids)]; }
        }
        return $days;
    }

    private function activeGuard(): void
    {
        if ($this->status()['expired']) { throw new RuntimeException('The imported timetable has expired. Refresh it in loader.php.'); }
    }

    private static function mode(int $type): string
    {
        return in_array($type,[0,1,2,100,109],true) || ($type>=100 && $type<200) ? 'train' : ($type===4 || ($type>=1000 && $type<1100) ? 'ferry' : 'bus');
    }

    private function shape(string $id): array
    {
        if (!$id) { return []; }
        if (!isset($this->shapes[$id])) {
            $q=$this->db->prepare('SELECT lat,lon FROM shapes WHERE id=? ORDER BY seq'); $q->execute([$id]);
            $this->shapes[$id]=array_map(static fn($r)=>[(float)$r['lat'],(float)$r['lon']],$q->fetchAll());
        }
        return $this->shapes[$id];
    }

    private function stop(string $id): array
    {
        $q=$this->db->prepare('SELECT id,name,lat,lon FROM stops WHERE id=?'); $q->execute([$id]);
        $r=$q->fetch();
        if (!$r) { throw new RuntimeException('Timetable refers to an unknown stop. Re-import the feed.'); }
        $r['lat']=(float)$r['lat']; $r['lon']=(float)$r['lon'];
        return $r;
    }

    public function vehicles(array $bbox,array $routes=[],string $selected=''): array
    {
        $this->activeGuard();
        $now=time(); $vehicles=[]; $lines=[]; $truncated=false;
        foreach ($this->serviceDays($now,3600) as $day) {
            $marks=implode(',',array_fill(0,count($day['ids']),'?'));
            $sql="SELECT t.*,r.name route_name,r.type FROM trips t JOIN routes r ON r.id=t.route_id WHERE t.service_id IN ($marks) AND ((t.start<=? AND t.end>=?) OR t.id=?)";
            $args=array_merge($day['ids'],[$now-$day['base'],$now-$day['base'],$selected]);
            if ($routes) { $sql.=' AND t.route_id IN ('.implode(',',array_fill(0,count($routes),'?')).')'; $args=array_merge($args,$routes); }
            $q=$this->db->prepare($sql); $q->execute($args);
            foreach ($q as $trip) {
                if (count($vehicles)>=700 && $trip['id']!==$selected) { $truncated=true; continue; }
                $seconds=$now-$day['base'];
                // Selected upcoming vehicles wait at their first stop; never invent earlier GPS movement.
                if ($seconds<$trip['start'] && $trip['start']-$seconds>3600) { continue; }
                if ($seconds>$trip['end']) { continue; }
                $a=$this->db->prepare('SELECT s.*,st.arrival,st.departure,st.seq FROM stop_times st JOIN stops s ON s.id=st.stop_id WHERE st.trip_id=? AND st.arrival<=? ORDER BY st.seq DESC LIMIT 1');
                $a->execute([$trip['id'],$seconds]); $before=$a->fetch();
                if (!$before) {
                    $a=$this->db->prepare('SELECT s.*,st.arrival,st.departure,st.seq FROM stop_times st JOIN stops s ON s.id=st.stop_id WHERE st.trip_id=? ORDER BY st.seq LIMIT 1');
                    $a->execute([$trip['id']]); $before=$a->fetch();
                }
                if (!$before) { continue; }
                $a=$this->db->prepare('SELECT s.*,st.arrival,st.departure,st.seq FROM stop_times st JOIN stops s ON s.id=st.stop_id WHERE st.trip_id=? AND st.seq>? ORDER BY st.seq LIMIT 1');
                $a->execute([$trip['id'],$before['seq']]); $after=$a->fetch() ?: $before;
                $f=max(0,min(1,($seconds-(int)$before['departure'])/max(1,(int)$after['arrival']-(int)$before['departure'])));
                $shape=$this->shape($trip['shape_id']);
                [$lat,$lon]=Geo::point(Geo::slice($shape,$before,$after),$f);
                if (!Geo::inside(['lat'=>$lat,'lon'=>$lon],$bbox) && $trip['id']!==$selected) { continue; }
                $vehicles[]=['id'=>$day['date'].'-'.$trip['id'],'tripId'=>$trip['id'],'routeId'=>$trip['route_id'],'routeName'=>$trip['route_name'],'mode'=>self::mode((int)$trip['type']),'lat'=>$lat,'lon'=>$lon,'bearing'=>0,'headsign'=>$trip['headsign'],'estimated'=>true,'nextStop'=>$after['name'],'progress'=>max(0,min(1,($seconds-$trip['start'])/max(1,$trip['end']-$trip['start']))),'upcoming'=>$seconds<$trip['start']];
                $key=$trip['route_id'].'-'.$trip['shape_id'];
                if (count($lines)<100 && !isset($lines[$key]) && $shape) {
                    $step=max(1,(int)ceil(count($shape)/600));
                    $display=[];
                    for ($i=0;$i<count($shape);$i+=$step) { $display[]=$shape[$i]; }
                    $display[]=end($shape);
                    $lines[$key]=['routeId'=>$trip['route_id'],'routeName'=>$trip['route_name'],'mode'=>self::mode((int)$trip['type']),'geometry'=>$display];
                }
            }
        }
        $q=$this->db->prepare('SELECT id,name,lat,lon FROM stops WHERE lon BETWEEN ? AND ? AND lat BETWEEN ? AND ? LIMIT 400');
        $q->execute([$bbox[0],$bbox[2],$bbox[1],$bbox[3]]);
        $stops=$q->fetchAll();
        foreach ($stops as &$s) { $s['lat']=(float)$s['lat']; $s['lon']=(float)$s['lon']; } unset($s);
        return ['vehicles'=>$vehicles,'stops'=>$stops,'lines'=>array_values($lines),'generatedAt'=>date(DATE_ATOM),'estimated'=>true,'truncated'=>$truncated,'mode'=>'timetable','message'=>$this->status()['message']];
    }

    private function nearby(array $point,float $radius=1500): array
    {
        $q=$this->db->prepare('SELECT id,name,lat,lon FROM stops WHERE lat BETWEEN ? AND ? AND lon BETWEEN ? AND ?');
        $dlat=$radius/111000; $dlon=$radius/(111000*cos(deg2rad($point['lat'])));
        $q->execute([$point['lat']-$dlat,$point['lat']+$dlat,$point['lon']-$dlon,$point['lon']+$dlon]);
        $out=[];
        foreach ($q as $row) {
            $row['lat']=(float)$row['lat']; $row['lon']=(float)$row['lon'];
            $dist=Geo::distance($point,$row);
            if ($dist<=$radius) { $row['walk']=(int)ceil($dist*1.3/1.25); $out[]=$row; }
        }
        usort($out,static fn($a,$b)=>$a['walk']<=>$b['walk']);
        return array_slice($out,0,30);
    }

    public function plan(array $from,array $to,int $now): array
    {
        $this->activeGuard();
        $journeys=[];
        $first=$this->scan($from,$to,$now);
        if ($first) { $journeys[]=$first; }
        // A later first boarding offers a meaningful "skip this one" choice.
        if ($first) {
            foreach ($first['legs'] as $leg) {
                if ($leg['mode']!=='walk') {
                    $later=$this->scan($from,$to,$now,$leg['tripId']);
                    if ($later && $later['id']!==$first['id']) { $journeys[]=$later; }
                    break;
                }
            }
        }
        return ['journeys'=>$journeys,'mode'=>'timetable','message'=>$journeys ? 'Timetable journeys within the next 3 hours. Walking links are approximate; confirm safe access and crossings.' : 'No journey found within 3 hours and 1.5 km of each endpoint. Try another nearby stop or refresh the timetable.'];
    }

    /** Earliest-arrival connection scan, with nearby walking transfers and immutable predecessor paths. */
    private function scan(array $from,array $to,int $now,string $skipTrip=''): ?array
    {
        $horizon=10800; $end=$now+$horizon; $queries=[]; $args=[];
        foreach ($this->serviceDays($now,$horizon) as $day) {
            $marks=implode(',',array_fill(0,count($day['ids']),'?'));
            $queries[]="SELECT c.trip_id,c.seq,c.from_id,c.to_id,c.departure+? departure,c.arrival+? arrival,c.pickup,c.dropoff,t.route_id,t.headsign,t.shape_id,r.name route_name,r.type,? || '-' || c.trip_id AS key FROM connections c JOIN trips t ON t.id=c.trip_id JOIN routes r ON r.id=t.route_id WHERE t.service_id IN ($marks) AND c.departure>=? AND c.departure<=? AND c.trip_id<>?";
            $args=array_merge($args,[$day['base'],$day['base'],$day['date']],$day['ids'],[$now-$day['base'],$end-$day['base'],$skipTrip]);
        }
        // Let SQLite sort on disk; don't load a metro-wide time window into PHP memory.
        $connections=[];
        if ($queries) {
            $connections=$this->db->prepare(implode(' UNION ALL ',$queries).' ORDER BY departure,seq');
            $connections->execute($args);
        }
        $labels=[]; $onboard=[]; $nodes=[]; $nearCache=[];
        foreach ($this->nearby($from) as $stop) {
            $idx=count($nodes);
            $nodes[]=['time'=>$now+$stop['walk'],'prev'=>null,'leg'=>Demo::walk($from,$stop,$now,$now+$stop['walk']),'initial'=>true,'boardings'=>0];
            $labels[$stop['id']]=$idx;
        }
        $dest=[];
        foreach ($this->nearby($to) as $stop) { $dest[$stop['id']]=$stop['walk']; }
        $bestArrival=PHP_INT_MAX; $bestIdx=null; $bestStop=null;
        // Walking-only is allowed locally, never fabricated as a bus journey.
        $direct=(int)ceil(Geo::distance($from,$to)*1.3/1.25);
        if ($direct<=2400) { $bestArrival=$now+$direct; }
        foreach ($connections as $c) {
            if ($c['departure']>$bestArrival) { break; }
            $prev=$onboard[$c['key']] ?? null;
            $boarding=false;
            if ($prev===null && (int)$c['pickup']===0 && isset($labels[$c['from_id']])) {
                $candidate=$labels[$c['from_id']];
                $buffer=$nodes[$candidate]['initial'] ? 0 : 120;
                if ($nodes[$candidate]['time']+$buffer<=$c['departure'] && $nodes[$candidate]['boardings']<4) { $prev=$candidate; $boarding=true; }
            }
            if ($prev===null) { continue; }
            $a=$this->stop($c['from_id']); $b=$this->stop($c['to_id']);
            $idx=count($nodes);
            $nodes[]=['time'=>$c['arrival'],'prev'=>$prev,'initial'=>false,'boardings'=>$nodes[$prev]['boardings']+($boarding?1:0),
                'leg'=>['mode'=>self::mode((int)$c['type']),'routeId'=>$c['route_id'],'routeName'=>$c['route_name'],'tripId'=>$c['trip_id'],'headsign'=>$c['headsign'],'shapeId'=>$c['shape_id'],'from'=>$a,'to'=>$b,'departure'=>date(DATE_ATOM,$c['departure']),'arrival'=>date(DATE_ATOM,$c['arrival'])]];
            $onboard[$c['key']]=$idx;
            if ((int)$c['dropoff']!==0) { continue; }
            if (isset($dest[$b['id']]) && $c['arrival']+$dest[$b['id']]<$bestArrival) { $bestArrival=$c['arrival']+$dest[$b['id']]; $bestIdx=$idx; $bestStop=$b; }
            if (!isset($labels[$b['id']]) || $c['arrival']<$nodes[$labels[$b['id']]]['time']) {
                $labels[$b['id']]=$idx;
                $nearCache[$b['id']] ??= $this->nearby($b,250);
                foreach ($nearCache[$b['id']] as $transfer) {
                    if ($transfer['id']===$b['id']) { continue; }
                    $arr=$c['arrival']+$transfer['walk'];
                    if (!isset($labels[$transfer['id']]) || $arr<$nodes[$labels[$transfer['id']]]['time']) {
                        $w=count($nodes);
                        $nodes[]=['time'=>$arr,'prev'=>$idx,'leg'=>Demo::walk($b,$transfer,$c['arrival'],$arr),'initial'=>false,'boardings'=>$nodes[$idx]['boardings']];
                        $labels[$transfer['id']]=$w;
                    }
                }
            }
        }
        if ($bestIdx===null) {
            if ($direct>2400) { return null; }
            $legs=[Demo::walk($from,$to,$now,$now+$direct)];
        } else {
            $legs=[]; $cursor=$bestIdx;
            while ($cursor!==null) { array_unshift($legs,$nodes[$cursor]['leg']); $cursor=$nodes[$cursor]['prev']; }
            if ($dest[$bestStop['id']]>0) { $legs[]=Demo::walk($bestStop,$to,$nodes[$bestIdx]['time'],$bestArrival); }
        }
        $merged=[];
        foreach ($legs as $leg) {
            $last=count($merged)-1;
            if ($last>=0 && $leg['mode']!=='walk' && ($merged[$last]['tripId'] ?? '')===($leg['tripId'] ?? '')) {
                $merged[$last]['to']=$leg['to']; $merged[$last]['arrival']=$leg['arrival'];
            } elseif ($leg['mode']!=='walk' || strtotime($leg['arrival'])>strtotime($leg['departure'])) { $merged[]=$leg; }
        }
        $rides=0; $walk=0; $ids=[];
        foreach ($merged as &$leg) {
            if ($leg['mode']==='walk') { $walk+=strtotime($leg['arrival'])-strtotime($leg['departure']); }
            else { $rides++; $ids[]=$leg['tripId']; $leg['geometry']=Geo::slice($this->shape($leg['shapeId']),$leg['from'],$leg['to']); unset($leg['shapeId']); }
        } unset($leg);
        return ['id'=>substr(hash('sha256',implode('|',$ids).'-'.$bestArrival),0,16),'departure'=>date(DATE_ATOM,$now),'arrival'=>date(DATE_ATOM,$bestArrival),'duration'=>$bestArrival-$now,'walkMinutes'=>(int)ceil($walk/60),'transfers'=>max(0,$rides-1),'legs'=>$merged];
    }
}
