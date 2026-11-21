<?php
declare(strict_types=1);
namespace App;

/** One nearby query, with optional confirmed-ride context and one bounded walking verification. */
final class NearbyExplorer
{
    private \Closure $walker;

    public function __construct(private Transit $transit, ?callable $walker=null)
    {
        $this->walker=$walker ? \Closure::fromCallable($walker) : static fn($a,$b)=>WalkingRouter::route($a,$b,4);
    }

    public function explore(array $point, string $stop='', string $service='', int $radius=500,
        ?array $ride=null, bool $verify=false, ?array $place=null, ?int $now=null): array
    {
        $fixedClock=$now!==null; $now ??= time(); $access=[]; $onboard=null; $origin=$point;
        if ($ride) {
            $onboard=$this->transit->onboardContext($ride['trip'],$ride['date'],$point,$ride['after'] ?? 0,$ride['alight'] ?? 0,$now);
            if (!$onboard['nextStop']) {
                return ['stop'=>null,'departure'=>null,'services'=>[],'nearbyStops'=>[],'geometry'=>[],'vehicle'=>null,
                    'centre'=>$point,'radius'=>$radius,'generatedAt'=>date(DATE_ATOM,$now),'onboard'=>$onboard,
                    'message'=>$onboard['message'],'estimated'=>true];
            }
            $origin=$onboard['nextStop']; $radius=300;
            $access=['fromStopId'=>$origin['id'],'fromTripId'=>$ride['trip'],'fromRouteId'=>$onboard['trip']['routeId'],
                'serviceDate'=>$ride['date'],'arrival'=>strtotime($onboard['alightAt'])];
        }
        $data=$this->transit->nearbyBus($origin,$now,$stop,$service,$radius,$access);
        $data['onboard']=$onboard;
        if ($place && !$ride) { throw new \InvalidArgumentException('Confirm your current service before exploring after alighting.'); }
        $destination=$place ?: ($data['departure']['stop'] ?? null);
        if (($verify || $place) && $destination) {
            try {
                $walk=Geo::distance($origin,$destination)<0.1
                    ? ['geometry'=>[],'duration'=>0,'distance'=>0,'accessConnectors'=>[],'routing'=>'same_stop']
                    : ($this->walker)($origin,$destination);
                if (!$place) {
                    $queryNow=$fixedClock ? $now : time();
                    $access['arrival']=max($access['arrival'] ?? $now,$queryNow);
                    $access['walkSeconds'][$destination['id']]=$walk['duration']; $access['requireKey']=true;
                    $data=$this->transit->nearbyBus($origin,$queryNow,$destination['id'],$data['departure']['key'],$radius,$access);
                    $data['onboard']=$onboard;
                } else { $data['place']=$place; $data['departure']=null; $data['vehicle']=null; $data['geometry']=[]; }
                $data['walk']=$walk+['available'=>true,'from'=>$origin,'to'=>$destination];
            } catch (\RuntimeException $e) {
                $data['walk']=['available'=>false,'message'=>'Walking route unavailable. Check access before travelling.'];
                if ($place) { $data['place']=$place; $data['departure']=null; $data['vehicle']=null; $data['geometry']=[]; }
            }
        }
        return $data;
    }
}
