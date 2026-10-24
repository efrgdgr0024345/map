<?php
declare(strict_types=1);
namespace App;

final class Geo
{
    public static function walkingKey(array $from,array $to): string
    {
        return sprintf('%.6F,%.6F>%.6F,%.6F',(float)$from['lat'],(float)$from['lon'],(float)$to['lat'],(float)$to['lon']);
    }

    public static function distance(array $a, array $b): float
    {
        $p = deg2rad($b['lat'] - $a['lat']);
        $q = deg2rad($b['lon'] - $a['lon']);
        $h = sin($p / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($q / 2) ** 2;
        return 6371000 * 2 * asin(min(1, sqrt($h)));
    }

    public static function point(array $line, float $progress): array
    {
        $position = self::position($line, $progress);
        return [$position['lat'], $position['lon']];
    }

    /** Compass bearing: north=0, east=90. Coincident points have no direction. */
    public static function bearing(array $from, array $to): ?float
    {
        if (self::distance($from, $to) < 0.01) { return null; }
        $a = deg2rad($from['lat']); $b = deg2rad($to['lat']);
        $delta = deg2rad($to['lon'] - $from['lon']);
        $y = sin($delta) * cos($b);
        $x = cos($a) * sin($b) - sin($a) * cos($b) * cos($delta);
        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    /** Position and local forward tangent along a scheduled path, never live GPS. */
    public static function position(array $line, float $progress): array
    {
        if (count($line) < 2) {
            return ['lat'=>$line[0][0] ?? 0, 'lon'=>$line[0][1] ?? 0, 'bearing'=>null];
        }
        $lengths = [0.0];
        for ($i = 1; $i < count($line); $i++) {
            $lengths[] = end($lengths) + self::distance(['lat'=>$line[$i-1][0], 'lon'=>$line[$i-1][1]], ['lat'=>$line[$i][0], 'lon'=>$line[$i][1]]);
        }
        $target = max(0, min(1, $progress)) * end($lengths);
        for ($i = 1; $i < count($line); $i++) {
            if ($lengths[$i] >= $target && $lengths[$i] - $lengths[$i-1] > 0.01) {
                $f = ($target - $lengths[$i-1]) / max(0.01, $lengths[$i] - $lengths[$i-1]);
                return ['lat'=>$line[$i-1][0] + ($line[$i][0]-$line[$i-1][0])*$f,
                    'lon'=>$line[$i-1][1] + ($line[$i][1]-$line[$i-1][1])*$f,
                    'bearing'=>self::bearing(['lat'=>$line[$i-1][0],'lon'=>$line[$i-1][1]], ['lat'=>$line[$i][0],'lon'=>$line[$i][1]])];
            }
        }
        $end = end($line);
        return ['lat'=>$end[0], 'lon'=>$end[1], 'bearing'=>null];
    }

    /** Slice a directional GTFS shape between two stops, preserving travel direction. */
    public static function slice(array $shape, array $from, array $to): array
    {
        if (count($shape) < 2) { return [[$from['lat'],$from['lon']],[$to['lat'],$to['lon']]]; }
        $start = 0; $end = 0; $min = INF;
        foreach ($shape as $i => $p) {
            $d = self::distance($from, ['lat'=>$p[0],'lon'=>$p[1]]);
            if ($d < $min) { $min=$d; $start=$i; }
        }
        $min = INF;
        for ($i=$start; $i<count($shape); $i++) {
            $d = self::distance($to, ['lat'=>$shape[$i][0],'lon'=>$shape[$i][1]]);
            if ($d < $min) { $min=$d; $end=$i; }
        }
        return array_merge([[$from['lat'],$from['lon']]], array_slice($shape,$start,max(1,$end-$start+1)), [[$to['lat'],$to['lon']]]);
    }

    public static function inside(array $point, array $bbox): bool
    {
        return $point['lon'] >= $bbox[0] && $point['lat'] >= $bbox[1] && $point['lon'] <= $bbox[2] && $point['lat'] <= $bbox[3];
    }

    /** Keep only the path ahead of a distance-based progress point. */
    public static function tail(array $line,float $progress): array
    {
        if (count($line)<2) { return $line; }
        $position=self::point($line,$progress);
        $lengths=[0.0];
        for ($i=1;$i<count($line);$i++) {
            $lengths[]=end($lengths)+self::distance(['lat'=>$line[$i-1][0],'lon'=>$line[$i-1][1]],['lat'=>$line[$i][0],'lon'=>$line[$i][1]]);
        }
        $target=max(0,min(1,$progress))*end($lengths);
        for ($i=1;$i<count($line);$i++) {
            if ($lengths[$i]>=$target && $lengths[$i]-$lengths[$i-1]>0.01) {
                return array_merge([$position],array_slice($line,$i));
            }
        }
        return [$position];
    }

    /** Associate stop visits with a monotonically advancing position on a shape. */
    public static function stopIndices(array $shape,array $stops): array
    {
        $indices=[]; $cursor=0;
        foreach ($stops as $stop) {
            $best=$cursor; $distance=INF;
            for ($i=$cursor;$i<count($shape);$i++) {
                $candidate=self::distance($stop,['lat'=>$shape[$i][0],'lon'=>$shape[$i][1]]);
                if ($candidate<$distance) { $distance=$candidate; $best=$i; }
            }
            $indices[]=$best; $cursor=$best;
        }
        return $indices;
    }

    public static function betweenStops(array $shape,array $stops,array $indices,int $from,int $to): array
    {
        $a=$stops[$from]; $b=$stops[$to];
        if (!$shape) { return [[$a['lat'],$a['lon']],[$b['lat'],$b['lon']]]; }
        return array_merge([[$a['lat'],$a['lon']]],array_slice($shape,$indices[$from],max(1,$indices[$to]-$indices[$from]+1)),[[$b['lat'],$b['lon']]]);
    }
}
