<?php
declare(strict_types=1);

/** Direction and geometry checks; no network, filesystem writes, or real timetable access. */
require_once dirname(__DIR__) . '/src/Geo.php';

use App\Geo;

$checks = 0;
function geoCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++;
    echo 'PASS: ' . $message . PHP_EOL;
}
function angleNear(?float $actual, float $expected, float $tolerance = 0.2): bool
{
    if ($actual === null || !is_finite($actual)) { return false; }
    return abs(fmod($actual - $expected + 540.0, 360.0) - 180.0) < $tolerance;
}

$a = ['lat' => -31.95, 'lon' => 115.85];
geoCheck(angleNear(Geo::bearing($a, ['lat' => -31.94, 'lon' => 115.85]), 0), 'northbound movement points north');
geoCheck(angleNear(Geo::bearing($a, ['lat' => -31.95, 'lon' => 115.86]), 90), 'eastbound movement points east');
geoCheck(angleNear(Geo::bearing($a, ['lat' => -31.96, 'lon' => 115.85]), 180), 'southbound movement points south');
geoCheck(angleNear(Geo::bearing($a, ['lat' => -31.95, 'lon' => 115.84]), 270), 'westbound movement points west');
geoCheck(Geo::bearing($a, $a) === null, 'coincident locations have no invented heading');
geoCheck(angleNear(Geo::bearing(['lat' => 0, 'lon' => 179], ['lat' => 0, 'lon' => -179]), 90), 'bearing crosses the antimeridian in the shortest eastward direction');
geoCheck(angleNear(Geo::bearing(['lat' => 0, 'lon' => -179], ['lat' => 0, 'lon' => 179]), 270), 'reverse antimeridian bearing points west');
// A long-distance published great-circle bearing example distinguishes spherical
// bearings from a simple uncorrected difference in latitude and longitude.
geoCheck(angleNear(Geo::bearing(['lat' => 50.06632, 'lon' => -5.71475], ['lat' => 58.64402, 'lon' => -3.07009]), 9.119368, 0.001), 'bearing uses spherical geometry rather than an uncorrected planar angle');

$line = [[-31.95, 115.85], [-31.95, 115.86], [-31.94, 115.86]];
$early = Geo::position($line, 0.2);
$late = Geo::position($line, 0.8);
geoCheck(angleNear($early['bearing'], 90) && abs($early['lat'] + 31.95) < 0.000001 && $early['lon'] > 115.85 && $early['lon'] < 115.86, 'early marker follows the eastbound segment of a bent shape');
geoCheck(angleNear($late['bearing'], 0) && abs($late['lon'] - 115.86) < 0.000001 && $late['lat'] > -31.95 && $late['lat'] < -31.94, 'later marker follows the northbound segment instead of the route endpoint chord');
$reverse = Geo::position(array_reverse($line), 0.8);
geoCheck(abs($reverse['lat'] - $early['lat']) < 0.000001 && abs($reverse['lon'] - $early['lon']) < 0.000001 && angleNear($reverse['bearing'], 270), 'reversing travel preserves location and reverses the local direction');
$plain = Geo::point($line, 0.2);
geoCheck(abs($plain[0] - $early['lat']) < 0.000001 && abs($plain[1] - $early['lon']) < 0.000001, 'legacy point interpolation agrees with position coordinates');
$before = Geo::position($line, -1);
$after = Geo::position($line, 2);
geoCheck(abs($before['lat'] + 31.95) < 0.000001 && abs($before['lon'] - 115.85) < 0.000001 && abs($after['lat'] + 31.94) < 0.000001 && abs($after['lon'] - 115.86) < 0.000001, 'out-of-range progress is clamped to the actual shape endpoints');
$duplicates = Geo::position([[-31.95,115.85], [-31.95,115.85], [-31.95,115.86], [-31.95,115.86]], 0.4);
geoCheck(angleNear($duplicates['bearing'], 90) && $duplicates['lon'] > 115.85, 'duplicate vertices do not erase a valid vehicle heading');
$single = Geo::position([[-31.95,115.85]], 0.5);
geoCheck($single['bearing'] === null && $single['lat'] === -31.95 && $single['lon'] === 115.85, 'single-point geometry preserves location and has no heading');
$stationary = Geo::position([[-31.95,115.85], [-31.95,115.85]], 0.5);
geoCheck($stationary['bearing'] === null && is_finite($stationary['lat']) && is_finite($stationary['lon']), 'zero-length shapes do not produce NaN or a fabricated direction');

$loop = [[-31.95,115.85], [-31.95,115.86], [-31.94,115.86], [-31.94,115.85], [-31.95,115.85]];
geoCheck(angleNear(Geo::position($loop, 0.1)['bearing'], 90) && angleNear(Geo::position($loop, 0.6)['bearing'], 270), 'closed-loop service changes direction locally even though route endpoints coincide');
$slice = Geo::slice($loop, ['lat' => -31.94, 'lon' => 115.86], $a);
geoCheck(in_array([-31.94,115.85], $slice, true) && angleNear(Geo::position($slice, 0.15)['bearing'], 270), 'loop stop-to-stop geometry preserves forward travel around intermediate vertices');

echo PHP_EOL . $checks . ' geometry checks passed.' . PHP_EOL;
