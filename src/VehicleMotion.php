<?php
declare(strict_types=1);
namespace App;

use PDO;

/** Short, bounded timetable predictions. These positions are estimates, never vehicle GPS. */
final class VehicleMotion
{
    private const DURATION = 15;
    private const MAX_FRAMES = 32;

    /**
     * Reuse the current stop pair and shape. At most one indexed query is made,
     * only when the next stop's dwell ends inside the prediction window.
     *
     * @return array{duration:int,geometry:array,endBearing:?float,frames:array}
     */
    public static function predict(PDO $db, array $trip, array $before, array $after, array $shape, int $seconds, array $current, array $segment): array
    {
        $finish = $seconds + self::DURATION;
        $tripEnd = (int) ($trip['end'] ?? $after['arrival']);
        $current = self::safePosition($current, $before);
        // An impossible current stop clock must not turn into a negative speed.
        if ((int)$after['seq'] > (int)$before['seq'] && (int)$after['arrival'] < (int)$before['departure']) {
            return self::stationary($current);
        }
        $stops = [$before];
        if ((int)$after['seq'] > (int)$before['seq']) { $stops[] = $after; }
        if (count($stops) > 1 && (int)$after['arrival'] < min($finish, $tripEnd)
            && (int)$after['departure'] < min($finish, $tripEnd)) {
            $query = $db->prepare('SELECT s.id,s.lat,s.lon,st.arrival,st.departure,st.seq
                FROM stop_times st JOIN stops s ON s.id=st.stop_id
                WHERE st.trip_id=? AND st.seq>? ORDER BY st.seq LIMIT 32');
            $query->execute([$trip['id'], $after['seq']]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $stops[] = $row; }
        }
        $legs = [];
        for ($i = 0; $i + 1 < count($stops); $i++) {
            $from = $stops[$i]; $to = $stops[$i + 1];
            $departure = (int)$from['departure'];
            $arrival = (int)$to['arrival'];
            if ($arrival < $departure) { break; }
            $line = $i === 0 ? $segment : Geo::slice($shape, $from, $to);
            $legs[] = ['from'=>$from, 'to'=>$to, 'departure'=>$departure, 'arrival'=>$arrival,
                'path'=>self::path($line, $from, $to)];
            // Stops beyond the bounded window are not needed, even if fetched.
            if ($arrival >= min($finish, $tripEnd)) { break; }
        }
        if (!$legs || $seconds >= $tripEnd) {
            return self::stationary($current, $seconds >= $tripEnd);
        }
        // Keep regular samples, dwell boundaries, and exact shape-corner times.
        // Higher priority events displace regular samples if the frame cap is hit.
        $events = [];
        for ($offset = 0; $offset <= self::DURATION; $offset++) {
            self::event($events, (float)$offset, $offset === 0 || $offset === self::DURATION ? 100000.0 : 1.0);
        }
        foreach ($legs as $leg) {
            foreach ([$leg['departure'], $leg['arrival'], (int)$leg['to']['departure']] as $time) {
                self::event($events, (float)($time - $seconds), 10000.0);
            }
            $span = $leg['arrival'] - $leg['departure'];
            $path = $leg['path'];
            if ($span <= 0 || $path['total'] <= 0.01) { continue; }
            for ($i = 1; $i + 1 < count($path['points']); $i++) {
                $time = $leg['departure'] + $span * $path['lengths'][$i] / $path['total'];
                if ($time <= $seconds || $time >= min($finish, $tripEnd)) { continue; }
                $turn = self::turn($path['points'][$i-1], $path['points'][$i], $path['points'][$i+1]);
                // Near-collinear vertices do not improve a short animation.
                if ($turn > 1.0) { self::event($events, $time - $seconds, 10.0 + $turn); }
            }
        }
        self::event($events, (float)($tripEnd - $seconds), 10000.0);
        if (count($events) > self::MAX_FRAMES) {
            uasort($events, static fn($a, $b) => $b['priority'] <=> $a['priority']);
            $events = array_slice($events, 0, self::MAX_FRAMES, true);
        }
        usort($events, static fn($a, $b) => $a['offset'] <=> $b['offset']);
        $frames = []; $geometry = [];
        foreach ($events as $event) {
            $position = $event['offset'] === 0.0 ? $current : self::at($legs, $seconds + $event['offset'], $tripEnd);
            if ($event['offset'] !== 0.0) {
                // Centimetre-scale coordinates are already finer than timetable
                // accuracy; avoid sending long floating-point tails per frame.
                $position['lat'] = round($position['lat'], 7);
                $position['lon'] = round($position['lon'], 7);
                if ($position['bearing'] !== null) { $position['bearing'] = fmod(round($position['bearing'], 1) + 360.0, 360.0); }
            }
            $frame = ['offset'=>$event['offset'], 'lat'=>$position['lat'], 'lon'=>$position['lon'], 'bearing'=>$position['bearing']];
            $frames[] = $frame;
            $point = [$position['lat'], $position['lon']];
            if (!$geometry || end($geometry) !== $point) { $geometry[] = $point; }
        }
        return ['duration'=>self::DURATION, 'geometry'=>$geometry,
            'endBearing'=>$frames[count($frames)-1]['bearing'], 'frames'=>$frames];
    }

    private static function event(array &$events, float $offset, float $priority): void
    {
        if (!is_finite($offset) || $offset < 0 || $offset > self::DURATION) { return; }
        $offset = round($offset, 6);
        $key = (string)$offset;
        if (!isset($events[$key]) || $priority > $events[$key]['priority']) {
            $events[$key] = ['offset'=>$offset, 'priority'=>$priority];
        }
    }

    /** Precompute one cumulative distance table, rather than scanning it per frame. */
    private static function path(array $line, array $from, array $to): array
    {
        if (count($line) < 2) { $line = [[$from['lat'], $from['lon']], [$to['lat'], $to['lon']]]; }
        $points = []; $lengths = []; $total = 0.0;
        foreach ($line as $point) {
            if (!isset($point[0], $point[1]) || !is_numeric($point[0]) || !is_numeric($point[1])) { continue; }
            $point = [(float)$point[0], (float)$point[1]];
            if (!is_finite($point[0]) || !is_finite($point[1])) { continue; }
            if ($points) {
                $last = end($points);
                $distance = Geo::distance(['lat'=>$last[0], 'lon'=>$last[1]], ['lat'=>$point[0], 'lon'=>$point[1]]);
                if ($distance < 0.01) { continue; }
                $total += $distance;
            }
            $points[] = $point;
            $lengths[] = $total;
        }
        if (!$points) { $points = [[(float)$from['lat'], (float)$from['lon']]]; $lengths = [0.0]; }
        return ['points'=>$points, 'lengths'=>$lengths, 'total'=>$total];
    }

    private static function along(array $path, float $fraction): array
    {
        $points = $path['points']; $last = count($points)-1;
        if ($last < 1 || $path['total'] <= 0.01) {
            return ['lat'=>$points[0][0], 'lon'=>$points[0][1], 'bearing'=>null];
        }
        $distance = max(0.0, min(1.0, $fraction)) * $path['total'];
        $low = 1; $high = $last;
        // The outgoing segment supplies heading at an exact corner.
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($path['lengths'][$middle] <= $distance) { $low = $middle + 1; } else { $high = $middle; }
        }
        $index = $low;
        $a = $points[$index-1]; $b = $points[$index];
        $fraction = max(0.0, min(1.0, ($distance - $path['lengths'][$index-1]) / max(0.01, $path['lengths'][$index] - $path['lengths'][$index-1])));
        return ['lat'=>$a[0]+($b[0]-$a[0])*$fraction, 'lon'=>$a[1]+($b[1]-$a[1])*$fraction,
            'bearing'=>Geo::bearing(['lat'=>$a[0],'lon'=>$a[1]], ['lat'=>$b[0],'lon'=>$b[1]])];
    }

    private static function at(array $legs, float $time, int $tripEnd): array
    {
        $last = $legs[count($legs)-1];
        foreach ($legs as $i => $leg) {
            if ($time < $leg['departure']) {
                $position = self::along($leg['path'], 0.0);
                return ['lat'=>(float)$leg['from']['lat'], 'lon'=>(float)$leg['from']['lon'], 'bearing'=>$position['bearing']];
            }
            if ($time < $leg['arrival'] && $leg['arrival'] > $leg['departure']) {
                return self::along($leg['path'], ($time - $leg['departure']) / ($leg['arrival'] - $leg['departure']));
            }
            $next = $legs[$i+1] ?? null;
            if ($time <= (int)$leg['to']['departure'] || !$next) {
                $heading = $next ? self::along($next['path'], 0.0)['bearing'] : self::along($leg['path'], 1.0)['bearing'];
                return ['lat'=>(float)$leg['to']['lat'], 'lon'=>(float)$leg['to']['lon'], 'bearing'=>$time >= $tripEnd ? null : $heading];
            }
        }
        return ['lat'=>(float)$last['to']['lat'], 'lon'=>(float)$last['to']['lon'], 'bearing'=>null];
    }

    private static function turn(array $a, array $b, array $c): float
    {
        $before = Geo::bearing(['lat'=>$a[0],'lon'=>$a[1]], ['lat'=>$b[0],'lon'=>$b[1]]);
        $after = Geo::bearing(['lat'=>$b[0],'lon'=>$b[1]], ['lat'=>$c[0],'lon'=>$c[1]]);
        if ($before === null || $after === null) { return 0.0; }
        return abs(fmod($after - $before + 540.0, 360.0) - 180.0);
    }

    private static function safePosition(array $position, array $fallback): array
    {
        $lat = (float)($position['lat'] ?? $fallback['lat']);
        $lon = (float)($position['lon'] ?? $fallback['lon']);
        $bearing = $position['bearing'] ?? null;
        return ['lat'=>is_finite($lat) ? $lat : (float)$fallback['lat'], 'lon'=>is_finite($lon) ? $lon : (float)$fallback['lon'],
            'bearing'=>is_numeric($bearing) && is_finite((float)$bearing) ? (float)$bearing : null];
    }

    private static function stationary(array $position, bool $terminal = false): array
    {
        if ($terminal) { $position['bearing'] = null; }
        $point = [$position['lat'], $position['lon']];
        return ['duration'=>self::DURATION, 'geometry'=>[$point], 'endBearing'=>$position['bearing'],
            'frames'=>[['offset'=>0.0]+$position, ['offset'=>(float)self::DURATION]+$position]];
    }
}
