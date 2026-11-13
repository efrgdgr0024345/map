<?php
declare(strict_types=1);
namespace App;

use InvalidArgumentException;
use RuntimeException;

/** Live National Public Toilet Map records, with short public-data caches only. */
final class Toilets
{
    public const SOURCE='National Public Toilet Map';
    public const SOURCE_URL='https://toiletmap.gov.au/';
    private const API='https://toiletmap.gov.au/api/AppAPI/';
    private const GUARD="<?php http_response_code(404); exit; ?>\n";
    private const TTL=120;
    private const FLAGS=[1=>'Male toilets',2=>'Female toilets',4=>'Unisex toilets',8=>'All-gender toilets',16=>'Accessible toilets',
        32=>'Dump point',256=>'Parking',512=>'Accessible parking',1024=>'Baby change',2048=>'Sharps disposal',4096=>'Showers',
        8192=>'Sanitary disposal',16384=>'Drinking water',32768=>'Key required',65536=>'MLAK key access',131072=>'MLAK after hours',
        16777216=>'Ambulant toilets',33554432=>'Left-hand transfer',67108864=>'Right-hand transfer',134217728=>'Adult change',
        268435456=>'Bring your own sling',536870912=>'Accessible shower',1073741824=>'Men’s pad disposal',2147483648=>'Changing Places',
        4294967296=>'Baby care room',1099511627776=>'Payment required'];

    public static function inBounds(array $bbox, ?callable $fixture=null): array
    {
        self::fixture($fixture);
        if (count($bbox)!==4 || count(array_filter($bbox,'is_numeric'))!==4) { throw new InvalidArgumentException('Invalid toilet map bounds.'); }
        $bbox=array_map('floatval',$bbox);
        if ($bbox[0]>=$bbox[2] || $bbox[1]>=$bbox[3] || !is_finite(array_sum($bbox))) { throw new InvalidArgumentException('Invalid toilet map bounds.'); }
        $coverage=Payphones::COVERAGE;
        if ($bbox[2]<$coverage[0] || $bbox[0]>$coverage[2] || $bbox[3]<$coverage[1] || $bbox[1]>$coverage[3]) {
            return ['toilets'=>[],'source'=>self::SOURCE,'sourceUrl'=>self::SOURCE_URL,'stale'=>false,'updatedAt'=>null,'message'=>'Toilet overlay coverage is limited to Perth metro.'];
        }
        $west=max($bbox[0],$coverage[0]); $east=min($bbox[2],$coverage[2]);
        $south=max($bbox[1],$coverage[1]); $north=min($bbox[3],$coverage[3]);
        // Share coarse map-centre cache keys; never send a device GPS fix to the provider.
        $lat=round(($south+$north)/2,3); $lon=round(($west+$east)/2,3);
        $latSpan=max(.02,round($north-$south+.02,2)); $lonSpan=max(.02,round($east-$west+.02,2));
        $params=['xIdiom'=>'Desktop','lat'=>$lat,'lon'=>$lon,'latDegrees'=>$latSpan,'lonDegrees'=>$lonSpan,'clat'=>$lat,'clon'=>$lon,
            'cDate'=>date('d M Y H:i:s'),'openNow'=>'false','mask'=>0,'enforcePrefs'=>'false','appType'=>'Mobile'];
        $key='area-'.hash('sha256',json_encode([$lat,$lon,$latSpan,$lonSpan]));
        $data=self::cached($key,static function() use ($params,$fixture): array {
            $payload=$fixture ? $fixture('area',$params) : self::fetch('GetFacilitysInBoundingBox?'.http_build_query($params));
            if (!is_array($payload['Facilitys'] ?? null) || !is_numeric($payload['Ct'] ?? null) || count($payload['Facilitys'])>500) { throw new RuntimeException('The toilet source returned an invalid list.'); }
            $items=[];
            foreach ($payload['Facilitys'] as $raw) { $row=self::normalize($raw); if ($row) { $items[]=$row; } }
            if ($payload['Ct']>0 && !$items) { throw new RuntimeException('The toilet source returned no usable records.'); }
            return ['toilets'=>$items,'limited'=>(int)$payload['Ct']>=36 || (int)$payload['Ct']>count($items),
                'providerMessage'=>self::text($payload['Msg'] ?? '',500)];
        });
        $data['toilets']=array_values(array_filter($data['toilets'],static fn($t)=>$t['lon']>=$west && $t['lon']<=$east && $t['lat']>=$south && $t['lat']<=$north));
        $data['message']='Live source queried for nearby locations around the map centre. Pan or zoom for more; this is not a complete metro inventory. Opening hours and availability can change.';
        return $data;
    }

    public static function detail(string $id, ?callable $fixture=null): array
    {
        self::fixture($fixture);
        if (!preg_match('/^[1-9][0-9]{0,8}$/D',$id)) { throw new InvalidArgumentException('Invalid toilet facility.'); }
        return self::cached('facility-'.$id,static function() use ($id,$fixture): array {
            $payload=$fixture ? $fixture('detail',$id) : self::fetch('GetFacilityFull/'.$id);
            $record=self::normalize($payload['Info'] ?? []);
            if (!$record || $record['id']!==$id) { throw new RuntimeException('The toilet source returned a different or invalid facility.'); }
            $extra=$payload['Extras'] ?? [];
            if (!is_array($extra)) { throw new RuntimeException('Invalid toilet details.'); }
            $notes=[];
            foreach (['AdNt'=>'Location','OhNt'=>'Hours','AceNt'=>'Access','TNt'=>'Toilets','AChNt'=>'Adult change','BChNt'=>'Baby change','PkNt'=>'Parking','DPNt'=>'Dump point'] as $field=>$label) {
                $text=self::text($extra[$field] ?? '',1500); if ($text!=='') { $notes[]=['label'=>$label,'text'=>$text]; }
            }
            $record['notes']=$notes; $record['operator']=self::text($extra['PT'] ?? '',200);
            $record['contactPhone']=self::text($extra['PP2'] ?? $extra['PP'] ?? '',100);
            return ['toilet'=>$record];
        });
    }

    public static function normalize(array $raw): ?array
    {
        $id=(string)($raw['ID'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,8}$/D',$id) || !is_numeric($raw['LA'] ?? null) || !is_numeric($raw['LO'] ?? null)) { return null; }
        $lat=(float)$raw['LA']; $lon=(float)$raw['LO'];
        if (!is_finite($lat) || !is_finite($lon) || $lat<-90 || $lat>90 || $lon<-180 || $lon>180) { return null; }
        $mask=is_numeric($raw['FM'] ?? null) ? (int)$raw['FM'] : 0; $amenities=[];
        foreach (self::FLAGS as $flag=>$label) { if (($mask & $flag)===$flag) { $amenities[]=$label; } }
        // O is the source's schedule description, not sensor-verified occupancy.
        $hours=self::text($raw['O'] ?? '',1000);
        $hours=preg_replace('/^(?:OPEN|CLOSED):\s*/i','',$hours);
        return ['id'=>$id,'name'=>self::text($raw['T'] ?? 'Public toilet',200),'lat'=>$lat,'lon'=>$lon,
            'address'=>self::text(trim(($raw['A1'] ?? '').' '.($raw['L'] ?? '')),300),'type'=>self::text($raw['F'] ?? '',100),
            'hours'=>$hours!=='' ? $hours : 'Hours not supplied','listedClosed'=>($raw['C'] ?? false)===true || ($mask & 1048576)===1048576,
            'amenities'=>$amenities,'sourceUrl'=>'https://toiletmap.gov.au/facility/'.$id];
    }

    private static function fixture(?callable $fixture): void
    {
        if ($fixture && !(PHP_SAPI==='cli' && defined('TOILETS_TESTING') && TOILETS_TESTING===true)) { throw new RuntimeException('Fixtures are restricted to CLI tests.'); }
    }
    private static function text(mixed $value,int $limit): string
    {
        return is_scalar($value) ? substr(trim(preg_replace('/[\x00-\x1F\x7F]/',' ',strip_tags((string)$value))),0,$limit) : '';
    }
    private static function fetch(string $path): array
    {
        $body=''; $curl=curl_init(self::API.$path);
        curl_setopt_array($curl,[CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'Nextstop-Public-Toilet-Map/1.0',CURLOPT_HTTPHEADER=>['Accept: application/json'],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body): int {
                if (strlen($body)+strlen($chunk)>2_000_000) { return 0; } $body.=$chunk; return strlen($chunk);
            }]);
        $ok=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        if ($ok===false || $status!==200) { throw new RuntimeException('Public toilet API unavailable (HTTP '.$status.').'); }
        $result=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($result)) { throw new RuntimeException('Invalid public toilet API response.'); }
        return $result;
    }
    private static function read(string $path): ?array
    {
        if (is_link($path) || !is_file($path)) { return null; }
        $body=file_get_contents($path);
        if (!is_string($body) || !str_starts_with($body,self::GUARD)) { return null; }
        try { $data=json_decode(substr($body,strlen(self::GUARD)),true,32,JSON_THROW_ON_ERROR); return is_array($data) ? $data : null; }
        catch (\Throwable) { return null; }
    }
    private static function cached(string $key,callable $load): array
    {
        $dir=APP_VAR.'/toilets';
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir,0700,true))) { throw new RuntimeException('Cannot prepare public toilet cache.'); }
        $path=$dir.'/'.$key.'.php'; $cached=self::read($path); $now=time();
        if ($cached && $now-(int)($cached['fetchedAt'] ?? 0)<self::TTL) { return self::response($cached,false); }
        $lockPath=$dir.'/refresh.lock';
        if (is_link($lockPath)) { throw new RuntimeException('Unsafe toilet cache lock.'); }
        $lock=fopen($lockPath,'c');
        if (!$lock) { throw new RuntimeException('Cannot open toilet cache lock.'); }
        chmod($lockPath,0600);
        try {
            if (!flock($lock,LOCK_EX | LOCK_NB)) {
                if ($cached) { return self::response($cached,true); }
                throw new RuntimeException('Toilet data is refreshing. Try again shortly.');
            }
            $status=self::read($dir.'/'.$key.'-status.php');
            if (($status['retryAt'] ?? 0)>$now) {
                if ($cached) { return self::response($cached,true); }
                throw new RuntimeException('Toilet source is temporarily unavailable. Retry shortly.');
            }
            try {
                $data=$load(); $data['fetchedAt']=$now;
                self::write($path,$data);
                @unlink($dir.'/'.$key.'-status.php');
                // Bound disk use; cache eviction never deletes source/application files.
                $entries=glob($dir.'/*.php') ?: [];
                if (count($entries)>256) {
                    usort($entries,static fn($a,$b)=>filemtime($a)<=>filemtime($b));
                    foreach (array_slice($entries,0,count($entries)-256) as $old) { if (!is_link($old)) { @unlink($old); } }
                }
                return self::response($data,false);
            } catch (\Throwable $error) {
                self::write($dir.'/'.$key.'-status.php',['retryAt'=>$now+30]);
                if ($cached) { return self::response($cached,true); }
                throw $error;
            }
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private static function write(string $path,array $data): void
    {
        if (is_link($path)) { throw new RuntimeException('Unsafe toilet cache destination.'); }
        $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (file_put_contents($tmp,self::GUARD.json_encode($data,JSON_THROW_ON_ERROR),LOCK_EX)===false) { throw new RuntimeException('Cannot save toilet cache.'); }
        chmod($tmp,0600);
        if (!rename($tmp,$path)) { @unlink($tmp); throw new RuntimeException('Cannot publish toilet cache.'); }
    }
    private static function response(array $data,bool $stale): array
    {
        return array_merge($data,['source'=>self::SOURCE,'sourceUrl'=>self::SOURCE_URL,'updatedAt'=>date(DATE_ATOM,(int)$data['fetchedAt']),
            'cacheSeconds'=>self::TTL,'stale'=>$stale,'attribution'=>'Source: Australian Government National Public Toilet Map']);
    }
}
