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

    public function status(): array
    {
        return ['mode'=>'demo','timezone'=>'Australia/Perth','updatedAt'=>null,'realtime'=>false,'message'=>self::MESSAGE];
    }

    public function vehicles(array $bbox, array $routes = [], string $journey = ''): array
    {
        $vehicles=[]; $stops=[]; $lines=[];
        foreach ($this->lines as $line) {
            if ($routes && !in_array($line['routeId'], $routes, true)) { continue; }
            $lines[]=$line;
            for ($i=0; $i<5; $i++) {
                $progress=fmod(time()/2400+$i/5,1);
                $chosen=false;
                if ($journey && preg_match('/^demo-trip-(\d+)-(\d+)-(\d+)-(\d+)$/',$journey,$match) && isset($this->lines[(int)$match[1]]) && $line['routeId']===$this->lines[(int)$match[1]]['routeId'] && $i===0) {
                    $chosen=true;
                    $position=max(0,min(count($line['geometry'])-1,(int)$match[2]+(time()-(int)$match[4])/150));
                    $segment=min(count($line['geometry'])-2,(int)floor($position));
                    [$lat,$lon]=Geo::point(array_slice($line['geometry'],$segment,2),$position-$segment);
                    $progress=$position/(count($line['geometry'])-1);
                } else {
                    [$lat,$lon]=Geo::point($line['geometry'],$progress);
                }
                if (!$chosen && !Geo::inside(['lat'=>$lat,'lon'=>$lon],$bbox)) { continue; }
                $vehicles[]=['id'=>$line['routeId'].'-'.$i,'tripId'=>$chosen ? $journey : $line['routeId'].'-trip-'.$i,
                    'routeId'=>$line['routeId'],'routeName'=>$line['routeName'],'mode'=>$line['mode'],'lat'=>$lat,'lon'=>$lon,'bearing'=>0,'headsign'=>$line['headsign'],'estimated'=>true,'nextStop'=>'Illustrative stop','progress'=>$progress];
            }
            foreach ($line['geometry'] as $i=>$p) {
                if (Geo::inside(['lat'=>$p[0],'lon'=>$p[1]],$bbox)) { $stops[]=['id'=>$line['routeId'].'-stop-'.$i,'name'=>'Demo stop '.$i,'lat'=>$p[0],'lon'=>$p[1]]; }
            }
        }
        return ['vehicles'=>$vehicles,'stops'=>$stops,'lines'=>$lines,'generatedAt'=>date(DATE_ATOM),'estimated'=>true,'mode'=>'demo','message'=>self::MESSAGE];
    }

    public function plan(array $from, array $to, int $now): array
    {
        $choices=[];
        foreach ($this->lines as $lineIndex=>$line) {
            $best=null;
            foreach ($line['geometry'] as $i=>$p) {
                foreach ($line['geometry'] as $j=>$q) {
                    if ($j <= $i) { continue; }
                    $a=['name'=>'Demo boarding stop','lat'=>$p[0],'lon'=>$p[1]];
                    $b=['name'=>'Demo arrival stop','lat'=>$q[0],'lon'=>$q[1]];
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
            $legs[]=['mode'=>$line['mode'],'routeId'=>$line['routeId'],'routeName'=>$line['routeName'],'tripId'=>$trip,'headsign'=>$line['headsign'],'from'=>$best['a'],'to'=>$best['b'],'departure'=>date(DATE_ATOM,$dep),'arrival'=>date(DATE_ATOM,$dep+$best['ride']), 'geometry'=>array_slice($line['geometry'],$best['i'],$best['j']-$best['i']+1)];
            if ($best['walkB']>0) { $legs[]=self::walk($best['b'],$to,$dep+$best['ride'],$arrival); }
            $journeys[]=['id'=>$trip,'departure'=>date(DATE_ATOM,$now),'arrival'=>date(DATE_ATOM,$arrival),'duration'=>$arrival-$now,'walkMinutes'=>(int)ceil(($best['walkA']+$best['walkB'])/60),'transfers'=>0,'legs'=>$legs];
        }
        return ['journeys'=>$journeys,'mode'=>'demo','message'=>self::MESSAGE];
    }

    public static function walk(array $a,array $b,int $dep,int $arr): array
    {
        return ['mode'=>'walk','from'=>$a,'to'=>$b,'departure'=>date(DATE_ATOM,$dep),'arrival'=>date(DATE_ATOM,$arr),'geometry'=>[[$a['lat'],$a['lon']],[$b['lat'],$b['lon']]],'estimated'=>true];
    }
}
