<?php
declare(strict_types=1);
namespace App;

final class Geo
{
    public static function distance(array $a, array $b): float
    {
        $p = deg2rad($b['lat'] - $a['lat']);
        $q = deg2rad($b['lon'] - $a['lon']);
        $h = sin($p / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($q / 2) ** 2;
        return 6371000 * 2 * asin(min(1, sqrt($h)));
    }

    public static function point(array $line, float $progress): array
    {
        if (count($line) < 2) { return $line[0] ?? [0, 0]; }
        $lengths = [0.0];
        for ($i = 1; $i < count($line); $i++) {
            $lengths[] = end($lengths) + self::distance(['lat'=>$line[$i-1][0], 'lon'=>$line[$i-1][1]], ['lat'=>$line[$i][0], 'lon'=>$line[$i][1]]);
        }
        $target = max(0, min(1, $progress)) * end($lengths);
        for ($i = 1; $i < count($line); $i++) {
            if ($lengths[$i] >= $target) {
                $f = ($target - $lengths[$i-1]) / max(0.01, $lengths[$i] - $lengths[$i-1]);
                return [$line[$i-1][0] + ($line[$i][0]-$line[$i-1][0])*$f, $line[$i-1][1] + ($line[$i][1]-$line[$i-1][1])*$f];
            }
        }
        return end($line);
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
}
