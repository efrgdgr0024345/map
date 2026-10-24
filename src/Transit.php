<?php
declare(strict_types=1);
namespace App;

use PDO;
use RuntimeException;

final class Transit
{
    private PDO $db;
    private array $shapes=[];
    private array $routeStyles=[];

    public function __construct(?string $path = null)
    {
        $this->db=new PDO('sqlite:'.($path ?? APP_VAR.'/transit.sqlite'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=3000;');
        // Older cPanel databases work immediately; a refresh adds optional feed colours.
        if ($this->db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='route_styles'")->fetchColumn()) {
            $this->routeStyles=$this->db->query('SELECT route_id,color,text_color FROM route_styles')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        }
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
            if ($ids) { $days[]=['base'=>$base,'date'=>$date,'ids'=>array_map('strval',array_keys($ids))]; }
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

    private function routeStyle(string $id,string $name,int $type): array
    {
        $feed=$this->routeStyles[$id] ?? [];
        $style=NetworkStyle::forRoute($name,$type,$feed['color'] ?? null,$feed['text_color'] ?? null);
        return ['routeColor'=>$style['color'],'routeTextColor'=>$style['textColor'],
            'colorSource'=>$style['colorSource'],'lineName'=>$style['lineName']];
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

    public function vehicles(array $bbox,array $routes=[],string $selected='',bool $includeLines=true): array
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
                $segment=Geo::slice($shape,$before,$after);
                $position=Geo::position($segment,$f);
                $lat=$position['lat']; $lon=$position['lon'];
                if (!Geo::inside(['lat'=>$lat,'lon'=>$lon],$bbox) && $trip['id']!==$selected) { continue; }
                $style=$this->routeStyle($trip['route_id'],$trip['route_name'],(int)$trip['type']);
                $vehicles[]=array_merge(['id'=>$day['date'].'-'.$trip['id'],'tripId'=>$trip['id'],'serviceDate'=>$day['date'],'routeId'=>$trip['route_id'],'routeName'=>$trip['route_name'],'mode'=>self::mode((int)$trip['type']),'lat'=>$lat,'lon'=>$lon,'bearing'=>$position['bearing'],'headsign'=>$trip['headsign'],'estimated'=>true,'nextStop'=>$after['name'],'nextStopId'=>$after['id'],'progress'=>max(0,min(1,($seconds-$trip['start'])/max(1,$trip['end']-$trip['start']))),'upcoming'=>$seconds<$trip['start'],
                    'motion'=>VehicleMotion::predict($this->db,$trip,$before,$after,$shape,$seconds,$position,$segment)],$style);
                $key=$trip['route_id'].'-'.$trip['shape_id'];
                if ($includeLines && count($lines)<100 && !isset($lines[$key]) && $shape) {
                    $step=max(1,(int)ceil(count($shape)/600));
                    $display=[];
                    for ($i=0;$i<count($shape);$i+=$step) { $display[]=$shape[$i]; }
                    $display[]=end($shape);
                    $lines[$key]=array_merge(['routeId'=>$trip['route_id'],'routeName'=>$trip['route_name'],'mode'=>self::mode((int)$trip['type']),'geometry'=>$display],$style);
                }
            }
        }
        $stopArgs=[$bbox[0],$bbox[2],$bbox[1],$bbox[3]];
        $stopSql='SELECT id,name,lat,lon FROM stops WHERE lon BETWEEN ? AND ? AND lat BETWEEN ? AND ? LIMIT 400';
        if ($routes) {
            $tripIds=array_values(array_unique(array_filter(array_merge(array_column($vehicles,'tripId'),[$selected]))));
            if ($tripIds) {
                $marks=implode(',',array_fill(0,count($tripIds),'?'));
                $stopSql="SELECT DISTINCT s.id,s.name,s.lat,s.lon FROM stop_times st JOIN stops s ON s.id=st.stop_id
                    WHERE st.trip_id IN ($marks) AND s.lon BETWEEN ? AND ? AND s.lat BETWEEN ? AND ? LIMIT 400";
                $stopArgs=array_merge($tripIds,$stopArgs);
            } else {
                $stopSql='SELECT id,name,lat,lon FROM stops WHERE 0'; $stopArgs=[];
            }
        }
        $q=$this->db->prepare($stopSql);
        $q->execute($stopArgs);
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

    /** In-app scheduled departures or the full stop-by-stop timetable of a trip. */
    public function timetable(string $stopId='', string $tripId='', ?int $now=null, ?string $serviceDate=null): array
    {
        $this->activeGuard();
        if (($stopId==='') === ($tripId==='')) {
            throw new \InvalidArgumentException('Choose one stop or one service to view its timetable.');
        }
        $now ??= time();
        $result=['mode'=>'timetable','timezone'=>'Australia/Perth','generatedAt'=>date(DATE_ATOM,$now),
            'estimated'=>true,'departures'=>[], 'message'=>'Scheduled times in Perth time. Live delays and cancellations are not included.'];
        if ($tripId!=='') {
            $q=$this->db->prepare('SELECT t.*,r.name route_name,r.type FROM trips t JOIN routes r ON r.id=t.route_id WHERE t.id=?');
            $q->execute([$tripId]); $trip=$q->fetch();
            if (!$trip) { throw new \InvalidArgumentException('This service was not found in the imported timetable.'); }
            $reference=$now; $horizon=86400;
            if ($serviceDate!==null) {
                $date=\DateTimeImmutable::createFromFormat('!Ymd',$serviceDate,new \DateTimeZone('Australia/Perth'));
                if (!$date || $date->format('Ymd')!==$serviceDate) { throw new \InvalidArgumentException('Choose a valid service date (YYYYMMDD).'); }
                $reference=$date->setTime(12,0)->getTimestamp(); $horizon=0;
            }
            $chosen=null; $best=PHP_INT_MAX;
            foreach ($this->serviceDays($reference,$horizon) as $day) {
                if ($serviceDate!==null && $serviceDate!==$day['date']) { continue; }
                if (!in_array($trip['service_id'],$day['ids'],true)) { continue; }
                $start=$day['base']+(int)$trip['start']; $end=$day['base']+(int)$trip['end'];
                $distance=$now<=$end ? max(0,$start-$now) : 172800+$now-$end;
                if ($distance<$best) { $best=$distance; $chosen=$day; }
            }
            if (!$chosen) { throw new \InvalidArgumentException('This service does not run on the selected day.'); }
            $result['serviceDate']=$chosen['date'];
            $result['trip']=array_merge(['id'=>$trip['id'],'routeId'=>$trip['route_id'],'routeName'=>$trip['route_name'],
                'mode'=>self::mode((int)$trip['type']),'headsign'=>$trip['headsign'],'serviceDate'=>$chosen['date']],
                $this->routeStyle($trip['route_id'],$trip['route_name'],(int)$trip['type']));
            $q=$this->db->prepare('SELECT s.*,st.arrival,st.departure,st.seq,st.pickup,st.dropoff FROM stop_times st JOIN stops s ON s.id=st.stop_id WHERE st.trip_id=? ORDER BY st.seq');
            $q->execute([$tripId]); $result['stops']=[];
            foreach ($q as $row) {
                $result['stops'][]=['id'=>$row['id'],'name'=>$row['name'],'lat'=>(float)$row['lat'],'lon'=>(float)$row['lon'],
                    'arrival'=>date(DATE_ATOM,$chosen['base']+(int)$row['arrival']),
                    'departure'=>date(DATE_ATOM,$chosen['base']+(int)$row['departure']),
                    'pickupType'=>(int)$row['pickup'],'dropoffType'=>(int)$row['dropoff']];
            }
            return $result;
        }
        $q=$this->db->prepare('SELECT id,name,lat,lon FROM stops WHERE id=?'); $q->execute([$stopId]);
        $stop=$q->fetch();
        if (!$stop) { throw new \InvalidArgumentException('This stop was not found in the imported timetable.'); }
        $stop['lat']=(float)$stop['lat']; $stop['lon']=(float)$stop['lon']; $result['stop']=$stop;
        $horizon=21600;
        foreach ($this->serviceDays($now,$horizon) as $day) {
            $marks=implode(',',array_fill(0,count($day['ids']),'?'));
            $q=$this->db->prepare("SELECT st.arrival,st.departure,st.pickup,t.id trip_id,t.route_id,t.headsign,r.name route_name,r.type
                FROM stop_times st JOIN trips t ON t.id=st.trip_id JOIN routes r ON r.id=t.route_id
                WHERE st.stop_id=? AND st.pickup<>1 AND t.service_id IN ($marks) AND st.departure>=? AND st.departure<=?
                ORDER BY st.departure,st.trip_id LIMIT 20");
            $q->execute(array_merge([$stopId],$day['ids'],[$now-$day['base'],$now+$horizon-$day['base']]));
            foreach ($q as $row) {
                $result['departures'][]=array_merge(['tripId'=>$row['trip_id'],'serviceDate'=>$day['date'],'routeId'=>$row['route_id'],
                    'routeName'=>$row['route_name'],'mode'=>self::mode((int)$row['type']),'headsign'=>$row['headsign'],
                    'departure'=>date(DATE_ATOM,$day['base']+(int)$row['departure']),
                    'arrival'=>date(DATE_ATOM,$day['base']+(int)$row['arrival']),
                    'stopId'=>$stop['id'],'stopName'=>$stop['name'],'pickupType'=>(int)$row['pickup']],
                    $this->routeStyle($row['route_id'],$row['route_name'],(int)$row['type']));
            }
        }
        usort($result['departures'],static fn($a,$b)=>strtotime($a['departure'])<=>strtotime($b['departure']));
        $result['departures']=array_slice($result['departures'],0,20);
        if (!$result['departures']) { $result['message']='No scheduled departures at this stop in the next six hours. Try a nearby platform or stop.'; }
        return $result;
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
                'leg'=>array_merge(['mode'=>self::mode((int)$c['type']),'routeId'=>$c['route_id'],'routeName'=>$c['route_name'],'tripId'=>$c['trip_id'],'serviceDate'=>substr($c['key'],0,8),'headsign'=>$c['headsign'],'shapeId'=>$c['shape_id'],'from'=>$a,'to'=>$b,'departure'=>date(DATE_ATOM,$c['departure']),'arrival'=>date(DATE_ATOM,$c['arrival'])],$this->routeStyle($c['route_id'],$c['route_name'],(int)$c['type']))];
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
