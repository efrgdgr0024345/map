<?php
declare(strict_types=1);
namespace App;

/** Deliberately synthetic, relative-to-now examples. Never labelled a real service. */
final class Demo
{
    public const MESSAGE = 'Demo only — illustrative vehicles and journeys, not the current Perth timetable. Import official GTFS to enable timetable estimates.';
    private array $lines = [
        ['routeId'=>'demo-950','routeName'=>'950','mode'=>'bus','headsign'=>'UWA · QEII', 'geometry'=>[[-31.9494,115.8555],[-31.9516,115.8560],[-31.9550,115.8533],[-31.9580,115.8490],[-31.9616,115.8441],[-31.9668,115.8355],[-31.9744,115.8226],[-31.9797,115.8173]]],
        ['routeId'=>'demo-blue','routeName'=>'Blue CAT','mode'=>'bus','headsign'=>'Elizabeth Quay','geometry'=>[[-31.9392,115.8580],[-31.9458,115.8586],[-31.9508,115.8598],[-31.9548,115.8606],[-31.9589,115.8567]]],
        ['routeId'=>'demo-fremantle','routeName'=>'Fremantle','mode'=>'train','headsign'=>'Fremantle','geometry'=>[[-31.9513,115.8604],[-31.9487,115.8545],[-31.9442,115.8425],[-31.9405,115.8317],[-31.9350,115.8188],[-31.9324,115.8077],[-31.9367,115.7905],[-31.9538,115.7627]]],
    ];

    public function __construct()
    {
        foreach ($this->lines as &$line) {
            $style=NetworkStyle::forRoute($line['routeName'],$line['mode']==='train' ? 2 : 3);
            $line+=['routeColor'=>$style['color'],'routeTextColor'=>$style['textColor'],'lineName'=>$style['lineName'],'colorSource'=>$style['colorSource']];
        }
        unset($line);
    }

    public function status(): array
    {
        return ['mode'=>'demo','timezone'=>'Australia/Perth','updatedAt'=>null,'realtime'=>false,'message'=>self::MESSAGE];
    }

    public function vehicles(array $bbox, array $routes = [], string $journey = '',bool $includeLines=true): array
    {
        $vehicles=[]; $stops=[]; $lines=[];
        foreach ($this->lines as $line) {
            if ($routes && !in_array($line['routeId'], $routes, true)) { continue; }
            if ($includeLines) { $lines[]=$line; }
            for ($i=0; $i<5; $i++) {
                $progress=fmod(time()/2400+$i/5,1);
                $chosen=false;
                if ($journey && preg_match('/^demo-trip-(\d+)-(\d+)-(\d+)-(\d+)$/',$journey,$match) && isset($this->lines[(int)$match[1]]) && $line['routeId']===$this->lines[(int)$match[1]]['routeId'] && $i===0) {
                    $chosen=true;
                    $position=max(0,min(count($line['geometry'])-1,(int)$match[2]+(time()-(int)$match[4])/150));
                    $segment=min(count($line['geometry'])-2,(int)floor($position));
                    $heading=Geo::position(array_slice($line['geometry'],$segment,2),$position-$segment);
                    $progress=$position/(count($line['geometry'])-1);
                } else {
                    $heading=Geo::position($line['geometry'],$progress);
                }
                $lat=$heading['lat']; $lon=$heading['lon'];
                if (!$chosen && !Geo::inside(['lat'=>$lat,'lon'=>$lon],$bbox)) { continue; }
                $vehicles[]=['id'=>$line['routeId'].'-'.$i,'tripId'=>$chosen ? $journey : $line['routeId'].'-trip-'.$i,'serviceDate'=>date('Ymd'),
                    'routeId'=>$line['routeId'],'routeName'=>$line['routeName'],'routeColor'=>$line['routeColor'],'routeTextColor'=>$line['routeTextColor'],'lineName'=>$line['lineName'],'mode'=>$line['mode'],'lat'=>$lat,'lon'=>$lon,'bearing'=>$heading['bearing'],'headsign'=>$line['headsign'],'estimated'=>true,'nextStop'=>'Illustrative stop','progress'=>$progress];
            }
            foreach ($line['geometry'] as $i=>$p) {
                if (Geo::inside(['lat'=>$p[0],'lon'=>$p[1]],$bbox)) { $stops[]=['id'=>$line['routeId'].'-stop-'.$i,'name'=>'Demo stop '.$i,'lat'=>$p[0],'lon'=>$p[1]]; }
            }
        }
        return ['vehicles'=>$vehicles,'stops'=>$stops,'lines'=>$lines,'generatedAt'=>date(DATE_ATOM),'estimated'=>true,'mode'=>'demo','message'=>self::MESSAGE];
    }

    public function routes(): array
    {
        $lines=$this->lines;
        foreach ($lines as &$line) {
            $lats=array_column($line['geometry'],0); $lons=array_column($line['geometry'],1);
            $line['bbox']=[min($lons),min($lats),max($lons),max($lats)];
        }
        unset($line);
        return ['revision'=>'demo-network-2','generatedAt'=>null,'mode'=>'demo','lines'=>$lines,'approximate'=>true,'message'=>self::MESSAGE];
    }

    public function plan(array $from, array $to, int $now): array
    {
        $choices=[];
        foreach ($this->lines as $lineIndex=>$line) {
            $best=null;
            foreach ($line['geometry'] as $i=>$p) {
                foreach ($line['geometry'] as $j=>$q) {
                    if ($j <= $i) { continue; }
                    $a=['id'=>$line['routeId'].'-stop-'.$i,'name'=>'Demo boarding stop','lat'=>$p[0],'lon'=>$p[1]];
                    $b=['id'=>$line['routeId'].'-stop-'.$j,'name'=>'Demo arrival stop','lat'=>$q[0],'lon'=>$q[1]];
                    $walkA=(int)ceil(Geo::distance($from,$a)*1.3/1.25);
                    $walkB=(int)ceil(Geo::distance($b,$to)*1.3/1.25);
                    $ride=($j-$i)*150;
                    $score=$walkA+$walkB+$ride;
                    if (!$best || $score<$best['score']) { $best=compact('a','b','i','j','walkA','walkB','ride','score'); }
                }
            }
            $choices[]=[$lineIndex,$best];
        }
        usort($choices,static fn($a,$b)=>$a[1]['score']<=>$b[1]['score']);
        [$index,$best]=$choices[0]; $line=$this->lines[$index];
        $journeys=[];
        foreach ([120,720] as $wait) {
            $dep=$now+$best['walkA']+$wait;
            $arrival=$dep+$best['ride']+$best['walkB'];
            $trip='demo-trip-'.$index.'-'.$best['i'].'-'.$best['j'].'-'.$dep;
            $legs=[];
            if ($best['walkA']>0) { $legs[]=self::walk($from,$best['a'],$now,$now+$best['walkA']); }
            $legs[]=['mode'=>$line['mode'],'routeId'=>$line['routeId'],'routeName'=>$line['routeName'],'routeColor'=>$line['routeColor'],'routeTextColor'=>$line['routeTextColor'],'lineName'=>$line['lineName'],'tripId'=>$trip,'serviceDate'=>date('Ymd',$dep-$best['i']*150),'headsign'=>$line['headsign'],'from'=>$best['a'],'to'=>$best['b'],'departure'=>date(DATE_ATOM,$dep),'arrival'=>date(DATE_ATOM,$dep+$best['ride']), 'geometry'=>array_slice($line['geometry'],$best['i'],$best['j']-$best['i']+1)];
            if ($best['walkB']>0) { $legs[]=self::walk($best['b'],$to,$dep+$best['ride'],$arrival); }
            $journeys[]=['id'=>$trip,'departure'=>date(DATE_ATOM,$now),'arrival'=>date(DATE_ATOM,$arrival),'duration'=>$arrival-$now,'walkMinutes'=>(int)ceil(($best['walkA']+$best['walkB'])/60),'transfers'=>0,'legs'=>$legs];
        }
        return ['journeys'=>$journeys,'mode'=>'demo','message'=>self::MESSAGE];
    }

    public function timetable(string $stopId='',string $tripId='',?int $now=null,?string $serviceDate=null): array
    {
        if (($stopId==='') === ($tripId==='')) { throw new \InvalidArgumentException('Choose one demo stop or service.'); }
        $now ??= time(); $line=null; $stopIndex=null; $start=$now+120;
        foreach ($this->lines as $candidate) {
            if ($stopId!=='' && str_starts_with($stopId,$candidate['routeId'].'-stop-')) {
                $suffix=substr($stopId,strlen($candidate['routeId'].'-stop-'));
                if (preg_match('/^\d+$/D',$suffix) && isset($candidate['geometry'][(int)$suffix])) { $line=$candidate; $stopIndex=(int)$suffix; }
            }
            if ($tripId!=='' && preg_match('/^'.preg_quote($candidate['routeId'],'/').'-trip-[0-4]$/',$tripId)) { $line=$candidate; }
        }
        if ($tripId!=='' && preg_match('/^demo-trip-(\d+)-(\d+)-(\d+)-(\d+)$/',$tripId,$parts) && isset($this->lines[(int)$parts[1]])) {
            $line=$this->lines[(int)$parts[1]]; $start=(int)$parts[4]-(int)$parts[2]*150;
        }
        if (!$line) { throw new \InvalidArgumentException('This illustrative demo stop or service was not found.'); }
        $result=['mode'=>'demo','timezone'=>'Australia/Perth','generatedAt'=>date(DATE_ATOM,$now),'estimated'=>true,
            'message'=>self::MESSAGE,'departures'=>[]];
        $style=['routeId'=>$line['routeId'],'routeName'=>$line['routeName'],'routeColor'=>$line['routeColor'],
            'routeTextColor'=>$line['routeTextColor'],'lineName'=>$line['lineName'],'mode'=>$line['mode'],'headsign'=>$line['headsign']];
        if ($stopIndex!==null) {
            $p=$line['geometry'][$stopIndex];
            $stop=['id'=>$stopId,'name'=>'Demo stop '.$stopIndex,'lat'=>$p[0],'lon'=>$p[1]];
            $result['stop']=$stop;
            for ($i=0;$i<5;$i++) {
                $departure=$now+120+$i*600;
                $index=array_search($line['routeId'],array_column($this->lines,'routeId'),true);
                $id='demo-trip-'.$index.'-'.$stopIndex.'-'.(count($line['geometry'])-1).'-'.$departure;
                $result['departures'][]=$style+['tripId'=>$id,'serviceDate'=>date('Ymd',$departure-$stopIndex*150),
                    'departure'=>date(DATE_ATOM,$departure),'arrival'=>date(DATE_ATOM,$departure),'stopId'=>$stopId,'stopName'=>$stop['name'],'pickupType'=>0];
            }
        } else {
            $result['serviceDate']=date('Ymd',$start);
            $result['trip']=$style+['id'=>$tripId,'serviceDate'=>$result['serviceDate']]; $result['stops']=[];
            foreach ($line['geometry'] as $i=>$p) {
                $result['stops'][]=['id'=>$line['routeId'].'-stop-'.$i,'name'=>'Demo stop '.$i,'lat'=>$p[0],'lon'=>$p[1],
                    'arrival'=>date(DATE_ATOM,$start+$i*150),'departure'=>date(DATE_ATOM,$start+$i*150),'pickupType'=>0,'dropoffType'=>0];
            }
        }
        return $result;
    }

    public function approach(string $tripId,string $stopId,?int $now=null,?string $serviceDate=null,?string $boardingAt=null): array
    {
        $now ??= time(); $table=$this->timetable('',$tripId,$now,$serviceDate); $stops=$table['stops'];
        $target=array_search($stopId,array_column($stops,'id'),true);
        if ($target===false) { throw new \InvalidArgumentException('Choose a boarding stop on this demo service.'); }
        $line=null;
        foreach ($this->lines as $candidate) { if ($candidate['routeId']===$table['trip']['routeId']) { $line=$candidate; break; } }
        $arrival=strtotime($stops[$target]['arrival']); $departure=strtotime($stops[$target]['departure']);
        $start=strtotime($stops[0]['departure']); $at=max(0,min(count($stops)-1,($now-$start)/150));
        $index=min(count($stops)-2,(int)floor($at));
        $segment=array_slice($line['geometry'],$index,2); $fraction=$at-$index;
        $position=Geo::position($segment,$fraction);
        $phase=$now>$departure ? 'departed' : ($now>=$arrival ? 'at-stop' : ($now<$start ? 'not-started' : 'approaching'));
        $geometry=[];
        if ($index<$target && in_array($phase,['approaching','not-started'],true)) {
            $geometry=array_merge(Geo::tail($segment,$fraction),array_slice($line['geometry'],$index+2,max(0,$target-$index-1)));
        } elseif ($phase==='at-stop') { $geometry=[[$stops[$target]['lat'],$stops[$target]['lon']]]; }
        return $table['trip']+['tripId'=>$tripId,'stop'=>$stops[$target],'geometry'=>$geometry,'position'=>$position,
            'arrival'=>$stops[$target]['arrival'],'departure'=>$stops[$target]['departure'],'secondsToStop'=>max(0,$arrival-$now),
            'phase'=>$phase,'upcoming'=>$now<$start,'estimated'=>true,'generatedAt'=>date(DATE_ATOM,$now),'message'=>self::MESSAGE];
    }

    public static function walk(array $a,array $b,int $dep,int $arr): array
    {
        return ['mode'=>'walk','from'=>$a,'to'=>$b,'departure'=>date(DATE_ATOM,$dep),'arrival'=>date(DATE_ATOM,$arr),'geometry'=>[[$a['lat'],$a['lon']],[$b['lat'],$b['lon']]],'estimated'=>true];
    }
}
