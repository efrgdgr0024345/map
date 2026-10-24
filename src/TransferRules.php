<?php
declare(strict_types=1);
namespace App;

use PDO;

/** Read-only GTFS transfer constraints. Minimum times are connection floors, not walking durations. */
final class TransferRules
{
    private array $parents=[];
    private array $rules=[];
    private array $pairs=[];
    private bool $qualified=false;

    public function __construct(PDO $db)
    {
        $tables=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('stop_details','transfers')")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('stop_details',$tables,true)) {
            foreach ($db->query('SELECT stop_id,parent_station FROM stop_details') as $row) {
                if ((string)$row['parent_station']!=='') { $this->parents[(string)$row['stop_id']]=(string)$row['parent_station']; }
            }
        }
        if (!in_array('transfers',$tables,true)) { return; }
        foreach ($db->query('SELECT * FROM transfers') as $row) {
            foreach (['from_route_id','to_route_id','from_trip_id','to_trip_id'] as $field) {
                $row[$field]=(string)($row[$field] ?? '');
                $this->qualified=$this->qualified || $row[$field]!=='';
            }
            $row['transfer_type']=(int)$row['transfer_type'];
            $row['min_transfer_time']=$row['min_transfer_time']===null ? null : max(0,(int)$row['min_transfer_time']);
            $key=self::key((string)$row['from_stop_id'],(string)$row['to_stop_id']);
            $this->rules[$key][]=$row;
        }
    }

    public function hasRules(): bool { return $this->rules!==[]; }
    public function hasQualifiedRules(): bool { return $this->qualified; }

    /**
     * Context fields: fromTripId, toTripId, fromRouteId, toRouteId.
     * Platform/stop rows override inherited parent rows at equal trip/route specificity.
     */
    public function between(string $fromId,string $toId,array $context=[]): array
    {
        $fallback=['matched'=>false,'allowed'=>true,'type'=>null,'minimumSeconds'=>0,'source'=>null,
            'fromStopId'=>$fromId,'toStopId'=>$toId,'inherited'=>false];
        if (!$this->rules || $fromId==='' || $toId==='') { return $fallback; }
        $key=self::key($fromId,$toId);
        if (!isset($this->pairs[$key])) {
            $candidates=[];
            foreach (array_merge($this->ancestors($fromId),['']) as $fromDepth=>$from) {
                foreach (array_merge($this->ancestors($toId),['']) as $toDepth=>$to) {
                    foreach ($this->rules[self::key($from,$to)] ?? [] as $row) {
                        $row['_depth']=$fromDepth+$toDepth;
                        $candidates[]=$row;
                    }
                }
            }
            // Pair lookups may cover a metro-wide scan. Bound this derived cache.
            if (count($this->pairs)>=8192) { $this->pairs=[]; }
            $this->pairs[$key]=$candidates;
        }
        $bestScore=null; $chosen=[];
        foreach ($this->pairs[$key] as $row) {
            $matches=true;
            foreach (['from_trip_id'=>'fromTripId','to_trip_id'=>'toTripId','from_route_id'=>'fromRouteId','to_route_id'=>'toRouteId'] as $field=>$value) {
                if ($row[$field]!=='' && $row[$field]!== (string)($context[$value] ?? '')) { $matches=false; break; }
            }
            if (!$matches) { continue; }
            $score=[(int)($row['from_trip_id']!=='')+(int)($row['to_trip_id']!==''),
                (int)($row['from_route_id']!=='')+(int)($row['to_route_id']!==''),-$row['_depth']];
            if ($bestScore===null || $score>$bestScore) { $bestScore=$score; $chosen=[$row]; }
            elseif ($score===$bestScore) { $chosen[]=$row; }
        }
        if (!$chosen) { return $fallback; }
        // Contradictory equally specific rows must not silently permit an unsafe connection.
        usort($chosen,static fn($a,$b)=>[in_array($b['transfer_type'],[3,4,5],true),(int)$b['min_transfer_time']]
            <=>[in_array($a['transfer_type'],[3,4,5],true),(int)$a['min_transfer_time']]);
        $row=$chosen[0]; $type=$row['transfer_type'];
        $minimum=0;
        foreach ($chosen as $item) { if ($item['transfer_type']===2) { $minimum=max($minimum,(int)$item['min_transfer_time']); } }
        // Types 4/5 require vehicle block/interlining semantics the current planner does not implement.
        return ['matched'=>true,'allowed'=>in_array($type,[0,1,2],true),'type'=>$type,'minimumSeconds'=>$minimum,
            'source'=>'Transperth GTFS transfers.txt','fromStopId'=>(string)$row['from_stop_id'],
            'toStopId'=>(string)$row['to_stop_id'],'inherited'=>$row['_depth']>0];
    }

    private function ancestors(string $id): array
    {
        $ids=[$id];
        while (isset($this->parents[$id]) && count($ids)<8) {
            $id=$this->parents[$id];
            if (in_array($id,$ids,true)) { break; }
            $ids[]=$id;
        }
        return $ids;
    }

    private static function key(string $from,string $to): string { return $from."\0".$to; }
}
