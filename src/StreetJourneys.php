<?php
declare(strict_types=1);
namespace App;

/** Verify pedestrian links before offering a timetable journey or leave-by time. */
final class StreetJourneys
{
    private const MAX_ROUNDS = 3;
    private const MAX_LOOKUPS = 8;
    private const MAX_SECONDS = 15.0;
    private const ROUTER_TIMEOUT_SECONDS = 6.0;
    private const BOARDING_BUFFER = 120;

    /**
     * The injected provider/monotonic clock are local dependencies, never HTTP inputs.
     * Street paths containing personal endpoints are memoized only in this request.
     */
    public static function plan(object $service, array $from, array $to, int $now, ?callable $router = null, ?callable $clock = null): array
    {
        if ($service instanceof Demo) {
            $response=$service->plan($from,$to,$now);
            $response['walkRouting']='demo';
            foreach ($response['journeys'] as &$journey) {
                $journey['walkRouting']='demo'; $journey['leaveAt']=null;
            }
            unset($journey);
            return $response;
        }
        $router ??= static fn(array $a,array $b): array => WalkingRouter::route($a,$b);
        $clock ??= static fn(): float => hrtime(true)/1_000_000_000;
        $started=(float)$clock(); $lookups=0; $paths=[]; $durations=[]; $failed=[]; $pool=[];
        $response=['mode'=>'timetable','journeys'=>[],'message'=>'No upcoming journey was found.'];
        $limited=false; $providerFailed=false;
        for ($round=0;$round<self::MAX_ROUNDS;$round++) {
            if ((float)$clock()-$started>=self::MAX_SECONDS) { $limited=true; break; }
            $planningNow=$now+max(0,(int)floor((float)$clock()-$started));
            $response=$service->plan($from,$to,$planningNow,$durations);
            $candidates=array_slice(is_array($response['journeys'] ?? null) ? $response['journeys'] : [],0,2);
            if (!$candidates) { break; }
            foreach ($candidates as $journey) { $pool[self::candidateKey($journey)]=$journey; }
            $added=0;
            foreach ($candidates as $journey) {
                foreach ($journey['legs'] ?? [] as $leg) {
                    if (($leg['mode'] ?? '')!=='walk' || self::zeroWalk($leg)) { continue; }
                    $key=Geo::walkingKey($leg['from'],$leg['to']);
                    if (isset($paths[$key]) || isset($failed[$key])) { continue; }
                    $remaining=self::MAX_SECONDS-((float)$clock()-$started);
                    if ($lookups>=self::MAX_LOOKUPS || $remaining<self::ROUTER_TIMEOUT_SECONDS) {
                        $limited=true; continue;
                    }
                    $lookups++;
                    try {
                        $path=$router($leg['from'],$leg['to']);
                        if (!self::validPath($path)) { throw new \RuntimeException('The walking service did not return a usable pedestrian route.'); }
                        $path['duration']=(int)ceil((float)$path['duration']);
                        $path['distance']=(float)$path['distance'];
                        $paths[$key]=$path; $durations[$key]=$path['duration']; $added++;
                    } catch (\Throwable $error) {
                        // Do not persist personal coordinates, provider bodies, or raw errors.
                        $failed[$key]=true; $providerFailed=true;
                    }
                }
            }
            if ($added===0) { break; }
        }
        $verified=[];
        $readyNow=$now+max(0,(int)floor((float)$clock()-$started));
        foreach ($pool as $journey) {
            $ready=self::finish($journey,$paths,$readyNow);
            if ($ready!==null) { $verified[self::candidateKey($ready)]=$ready; }
        }
        $verified=array_values($verified);
        usort($verified,static fn($a,$b)=>strtotime($a['arrival'])<=>strtotime($b['arrival']));
        $response['journeys']=array_slice($verified,0,2);
        $response['walkRouting']=$verified ? 'street' : 'unavailable';
        if ($verified) {
            $response['message']='';
            if ($limited || $providerFailed) { $response['walkingLimited']=true; }
        } elseif ($pool) {
            $response['message']=$providerFailed
                ? 'Walking directions are temporarily unavailable. Please try again shortly.'
                : 'A safe walking connection could not be checked in time. Try a nearby stop or try again.';
        }
        return $response;
    }

    private static function zeroWalk(array $leg): bool
    {
        return isset($leg['from']['lat'],$leg['from']['lon'],$leg['to']['lat'],$leg['to']['lon'])
            && Geo::distance($leg['from'],$leg['to'])<0.5;
    }

    private static function candidateKey(array $journey): string
    {
        $parts=[];
        foreach ($journey['legs'] ?? [] as $leg) {
            $parts[]=($leg['mode'] ?? '').':'.($leg['serviceDate'] ?? '').':'.($leg['tripId'] ?? '').':'
                .Geo::walkingKey($leg['from'],$leg['to']);
        }
        return hash('sha256',implode('|',$parts));
    }

    private static function validPath(array $path): bool
    {
        if (($path['routing'] ?? '')!=='street' || !is_numeric($path['duration'] ?? null)
            || !is_finite((float)$path['duration']) || $path['duration']<=0 || $path['duration']>86400
            || !is_numeric($path['distance'] ?? null) || !is_finite((float)$path['distance']) || $path['distance']<=0
            || !is_array($path['geometry'] ?? null) || count($path['geometry'])<2) { return false; }
        foreach ($path['geometry'] as $point) {
            if (!is_array($point) || count($point)!==2 || !is_numeric($point[0]) || !is_numeric($point[1])
                || !is_finite((float)$point[0]) || !is_finite((float)$point[1])
                || abs((float)$point[0])>90 || abs((float)$point[1])>180) { return false; }
        }
        return true;
    }

    /** Re-clock walks and reject any missed boarding, including a missed transfer. */
    private static function finish(array $journey,array $paths,int $now): ?array
    {
        $legs=[]; $cursor=$now; $walkSeconds=0; $initialWalk=0; $boardings=0; $firstBoarding=null;
        foreach ($journey['legs'] ?? [] as $leg) {
            if (($leg['mode'] ?? '')==='walk') {
                if (self::zeroWalk($leg)) { continue; }
                $key=Geo::walkingKey($leg['from'],$leg['to']);
                if (!isset($paths[$key])) { return null; }
                $path=$paths[$key]; $duration=(int)$path['duration'];
                $leg['departure']=date(DATE_ATOM,$cursor); $cursor+=$duration;
                $leg['arrival']=date(DATE_ATOM,$cursor);
                foreach (['geometry','duration','distance','routingSource','accessConnectors','snappedFrom','snappedTo'] as $field) {
                    if (array_key_exists($field,$path)) { $leg[$field]=$path[$field]; }
                }
                $leg['routing']='street'; $leg['approximate']=false; $leg['estimated']=true;
                $walkSeconds+=$duration;
                if ($boardings===0) { $initialWalk+=$duration; }
                $legs[]=$leg;
                continue;
            }
            $departure=strtotime((string)($leg['departure'] ?? ''));
            $arrival=strtotime((string)($leg['arrival'] ?? ''));
            if ($departure===false || $arrival===false || $arrival<$departure) { return null; }
            $buffer=$boardings>0 || $initialWalk>0 ? self::BOARDING_BUFFER : 0;
            if ($departure<$cursor+$buffer) { return null; }
            $firstBoarding ??= $departure;
            $boardings++; $cursor=$arrival; $legs[]=$leg;
        }
        if (!$legs || $cursor<$now) { return null; }
        $journey['legs']=$legs;
        $journey['departure']=date(DATE_ATOM,$now); $journey['arrival']=date(DATE_ATOM,$cursor);
        $journey['duration']=$cursor-$now; $journey['walkMinutes']=(int)ceil($walkSeconds/60);
        $journey['transfers']=max(0,$boardings-1);
        $journey['walkRouting']='street';
        $journey['boardingAt']=$firstBoarding===null ? null : date(DATE_ATOM,$firstBoarding);
        $journey['walkToBoardSeconds']=$firstBoarding===null ? 0 : $initialWalk;
        $journey['boardingBufferSeconds']=$firstBoarding!==null && $initialWalk>0 ? self::BOARDING_BUFFER : 0;
        $journey['leaveAt']=$firstBoarding===null ? null : date(DATE_ATOM,$firstBoarding-$initialWalk-$journey['boardingBufferSeconds']);
        $journey['id']=substr(hash('sha256',self::candidateKey($journey).':'.$cursor),0,16);
        return $journey;
    }
}
