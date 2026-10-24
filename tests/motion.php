<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Geo.php';
require_once dirname(__DIR__) . '/src/VehicleMotion.php';

use App\Geo;
use App\VehicleMotion;

/** Deterministic in-memory schedule fixtures; no files, network, or live GPS. */
final class MotionDb extends PDO
{
    public int $prepares = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepares++;
        return parent::prepare($query, $options);
    }
}
$checks = 0;
function motionCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++;
    echo 'PASS: ' . $message . PHP_EOL;
}
function motionNear(array $position, array $expected, float $tolerance = 0.000001): bool
{
    return abs($position['lat'] - $expected[0]) < $tolerance && abs($position['lon'] - $expected[1]) < $tolerance;
}
function motionAt(array $motion, float $offset): array
{
    foreach ($motion['frames'] as $frame) { if (abs($frame['offset'] - $offset) < 0.00001) { return $frame; } }
    throw new RuntimeException('Missing predicted frame at ' . $offset);
}
function motionValid(array $motion): bool
{
    $last = -1;
    foreach ($motion['frames'] as $frame) {
        if ($frame['offset'] <= $last || $frame['offset'] < 0 || $frame['offset'] > 15 || !is_finite($frame['lat']) || !is_finite($frame['lon'])) { return false; }
        if ($frame['bearing'] !== null && (!is_finite($frame['bearing']) || $frame['bearing'] < 0 || $frame['bearing'] >= 360)) { return false; }
        $last = $frame['offset'];
    }
    return $motion['duration'] === 15 && count($motion['frames']) <= 32 && $motion['frames'][0]['offset'] == 0 && $last == 15;
}
function motionStop(string $id, float $lat, float $lon, int $arrival, int $departure, int $seq): array
{
    return compact('id','lat','lon','arrival','departure','seq');
}
$db = new MotionDb('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE stops(id TEXT PRIMARY KEY,lat REAL,lon REAL);
    CREATE TABLE stop_times(trip_id TEXT,stop_id TEXT,arrival INTEGER,departure INTEGER,seq INTEGER,PRIMARY KEY(trip_id,seq));');
$a = motionStop('A',-31.95,115.85,90,100,10);
$b = motionStop('B',-31.949,115.851,120,125,20);
$c = motionStop('C',-31.949,115.852,135,135,30);
$shape = [[$a['lat'],$a['lon']],[-31.95,115.851],[$b['lat'],$b['lon']],[$c['lat'],$c['lon']]];
$segment = Geo::slice($shape,$a,$b);
foreach ([$a,$b,$c] as $stop) {
    $db->prepare('INSERT INTO stops VALUES(?,?,?)')->execute([$stop['id'],$stop['lat'],$stop['lon']]);
    $db->prepare('INSERT INTO stop_times VALUES(?,?,?,?,?)')->execute(['T',$stop['id'],$stop['arrival'],$stop['departure'],$stop['seq']]);
}
$trip = ['id'=>'T','start'=>100,'end'=>135];
$db->prepares = 0;
$current = Geo::position($segment,0.05);
$motion = VehicleMotion::predict($db,$trip,$a,$b,$shape,101,$current,$segment);
motionCheck(motionValid($motion), 'prediction frames are ordered, finite, bounded to fifteen seconds and capped at thirty-two');
motionCheck($db->prepares === 0, 'prediction within the current stop segment performs no additional database queries');
motionCheck(motionNear($motion['frames'][0],[$current['lat'],$current['lon']]), 'first frame exactly matches the currently rendered vehicle position');
$cornerFrames = array_values(array_filter($motion['frames'],static fn($frame)=>motionNear($frame,[-31.95,115.851],0.00000001)));
motionCheck(count($cornerFrames) === 1 && $cornerFrames[0]['offset'] > 0 && $cornerFrames[0]['offset'] < 15, 'prediction includes the exact timestamped route corner instead of cutting across it');
motionCheck($motion['frames'][0]['bearing'] > 89 && $motion['frames'][0]['bearing'] < 91 && $motion['endBearing'] < 1, 'heading follows the local bend from east to north');
$last = motionAt($motion,15);
$expected = Geo::point($segment,16/20);
motionCheck(motionNear($last,$expected), 'future position is interpolated from the schedule along the shape');

$db->prepares = 0;
$waitingCurrent = Geo::position($segment,0);
$waiting = VehicleMotion::predict($db,$trip,$a,$b,$shape,90,$waitingCurrent,$segment);
motionCheck(motionNear(motionAt($waiting,0),[$a['lat'],$a['lon']]) && motionNear(motionAt($waiting,10),[$a['lat'],$a['lon']]), 'upcoming service waits at its first stop until the scheduled departure');
motionCheck(!motionNear(motionAt($waiting,15),[$a['lat'],$a['lon']]), 'upcoming service begins moving when its departure enters the prediction window');
motionCheck($db->prepares === 0, 'waiting and departure within the known segment need no database queries');

$db->prepares = 0;
$crossing = VehicleMotion::predict($db,$trip,$a,$b,$shape,117,Geo::position($segment,17/20),$segment);
motionCheck($db->prepares === 1, 'crossing a stop and continuing onto the next leg makes only one indexed stop query');
motionCheck(motionNear(motionAt($crossing,3),[$b['lat'],$b['lon']]) && motionNear(motionAt($crossing,8),[$b['lat'],$b['lon']]), 'arrival and the complete scheduled dwell keep the vehicle at the stop');
motionCheck(motionAt($crossing,9)['lon'] > $b['lon'] && motionNear(motionAt($crossing,9),Geo::point(Geo::slice($shape,$b,$c),0.1)), 'vehicle resumes along the next scheduled leg only after the dwell ends');
motionCheck(motionValid($crossing), 'multi-leg predictions retain the same bounded finite frame contract');

$db->prepares = 0;
$longDwell = $b; $longDwell['departure'] = 150;
$dwell = VehicleMotion::predict($db,['id'=>'T','start'=>100,'end'=>200],$a,$longDwell,$shape,117,Geo::position($segment,17/20),$segment);
motionCheck($db->prepares === 0 && motionNear(motionAt($dwell,15),[$b['lat'],$b['lon']]), 'a long next-stop dwell holds without fetching an unnecessary later leg');

$db->exec("UPDATE stop_times SET arrival=130,departure=130 WHERE stop_id='C'");
$crossingTerminal = VehicleMotion::predict($db,['id'=>'T','start'=>100,'end'=>130],$a,$b,$shape,117,Geo::position($segment,17/20),$segment);
motionCheck(motionNear(motionAt($crossingTerminal,13),[$c['lat'],$c['lon']]) && motionNear(motionAt($crossingTerminal,15),[$c['lat'],$c['lon']]) && $crossingTerminal['endBearing'] === null, 'crossing multiple legs reaches the real terminal rather than stopping at an earlier intermediate stop');
$db->exec("UPDATE stop_times SET arrival=135,departure=135 WHERE stop_id='C'");

$terminal = VehicleMotion::predict($db,$trip,$b,$c,$shape,130,Geo::position(Geo::slice($shape,$b,$c),0.5),Geo::slice($shape,$b,$c));
motionCheck(motionNear(motionAt($terminal,5),[$c['lat'],$c['lon']]) && motionNear(motionAt($terminal,15),[$c['lat'],$c['lon']]), 'prediction stops at the terminal instead of extrapolating beyond the end of service');
motionCheck($terminal['endBearing'] === null, 'terminal hold has no invented travel direction');
$pastEnd = VehicleMotion::predict($db,$trip,$c,$c,$shape,135,['lat'=>$c['lat'],'lon'=>$c['lon'],'bearing'=>90],[]);
motionCheck(count($pastEnd['geometry']) === 1 && $pastEnd['endBearing'] === null && motionValid($pastEnd), 'completed service returns a stationary terminal prediction');

$chord = VehicleMotion::predict($db,$trip,$a,$b,[],105,['lat'=>-31.94975,'lon'=>115.85025,'bearing'=>45],[]);
motionCheck(motionNear(motionAt($chord,5),[-31.9495,115.8505]) && motionValid($chord), 'missing route geometry falls back to the known stop-to-stop chord');
$backward = $b; $backward['arrival'] = 95;
$invalid = VehicleMotion::predict($db,$trip,$a,$backward,$shape,101,$current,$segment);
motionCheck(motionValid($invalid) && count($invalid['geometry']) === 1 && motionNear(motionAt($invalid,15),[$current['lat'],$current['lon']]), 'backward scheduled clocks hold safely rather than generating negative speed or NaN');
$zero = $a; $zero['seq'] = 20; $zero['arrival'] = 120; $zero['departure'] = 120;
$stationary = VehicleMotion::predict($db,['id'=>'T','start'=>100,'end'=>120],$a,$zero,[],101,['lat'=>$a['lat'],'lon'=>$a['lon'],'bearing'=>null],[]);
motionCheck(motionValid($stationary) && count($stationary['geometry']) === 1, 'coincident stop locations stay finite without fabricated movement');

// A dense zigzag verifies that detailed provider shapes cannot grow response
// payloads without bound while both prediction endpoints remain present.
$dense = [];
for ($i=0;$i<=100;$i++) { $dense[]=[-31.95 + ($i%2)*0.00001,115.85+$i*0.00001]; }
$denseA = $a; $denseA['lat']=$dense[0][0]; $denseA['lon']=$dense[0][1];
$denseB = $b; $denseB['lat']=$dense[100][0]; $denseB['lon']=$dense[100][1]; $denseB['arrival']=115;
$denseMotion = VehicleMotion::predict($db,['id'=>'T','start'=>100,'end'=>115],$denseA,$denseB,$dense,100,Geo::position($dense,0),$dense);
motionCheck(motionValid($denseMotion) && count($denseMotion['frames']) <= 32 && motionNear(motionAt($denseMotion,15),$dense[100]), 'dense turn geometry respects the frame cap and retains the terminal endpoint');

echo PHP_EOL . $checks . ' bounded vehicle-motion checks passed. Timetable estimates only.' . PHP_EOL;
