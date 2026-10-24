<?php
declare(strict_types=1);

/** Offline station-map fixtures only: no production timetable or provider request. */
namespace App {
    function file_get_contents(string $filename,mixed ...$arguments): string|false {
        if (preg_match('~^(?:https?|ftp)://~i',$filename)) {
            $GLOBALS['stationMapNetworkAttempts']++;
            throw new \RuntimeException('Station map tests must not make network requests.');
        }
        return \file_get_contents($filename,...$arguments);
    }
    function curl_init(?string $url=null): never {
        $GLOBALS['stationMapNetworkAttempts']++;
        throw new \RuntimeException('Station map tests must not make network requests.');
    }
}

namespace {
    use App\StationMap;

    $project=dirname(__DIR__);
    $isolated=sys_get_temp_dir().'/nextstop-station-map-test-'.bin2hex(random_bytes(6));
    mkdir($isolated.'/src',0700,true); mkdir($isolated.'/var',0700,true);
    copy($project.'/bootstrap.php',$isolated.'/bootstrap.php');
    foreach (['Geo.php','StationMap.php'] as $file) { copy($project.'/src/'.$file,$isolated.'/src/'.$file); }
    require $isolated.'/bootstrap.php';
    $GLOBALS['stationMapNetworkAttempts']=0;
    $checks=0; $catalogNumber=0;

    function smCheck(bool $condition,string $message): void {
        global $checks;
        if (!$condition) { throw new RuntimeException('FAIL: '.$message); }
        $checks++; echo 'PASS: '.$message.PHP_EOL;
    }
    function smReject(callable $operation,string $message,string $type=InvalidArgumentException::class): void {
        $rejected=false;
        try { $operation(); } catch (Throwable $error) { $rejected=$error instanceof $type; }
        smCheck($rejected,$message);
    }
    function smFeature(string $id,string $kind,string $type,array $coordinates,array $properties=[]): array {
        return ['type'=>'Feature','id'=>$id,'geometry'=>['type'=>$type,'coordinates'=>$coordinates],
            'properties'=>['kind'=>$kind]+$properties];
    }
    function smWrite(string $file,array $value): void {
        file_put_contents($file,json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }
    function smCatalog(array $manifest,array $collections): string {
        global $catalogNumber;
        $directory=APP_ROOT.'/catalog-'.++$catalogNumber;
        mkdir($directory,0700);
        smWrite($directory.'/manifest.json',$manifest);
        foreach ($collections as $filename=>$collection) { smWrite($directory.'/'.$filename,$collection); }
        return $directory;
    }
    function smCleanup(string $directory): void {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($directory);
    }

    try {
        $database=APP_VAR.'/transit.sqlite';
        $db=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE stops(id TEXT PRIMARY KEY,name TEXT,lat REAL,lon REAL)');
        $insert=$db->prepare('INSERT INTO stops VALUES(?,?,?,?)');
        foreach ([['A','Fixture bus stand',-31.95,115.86],['B','Fixture platform 2',-31.9504,115.8605],
            ['C','Fixture second station',-31.951,115.861],['UNLINKED','Unlinked bus sign',-31.9511,115.8611],
            ['OUTSIDE','No station catalog here',-31.96,115.87],['FAR','Distant station',-32.1,116.1],
            ['83','Fixture parent station',-31.95,115.86],['0','Numeric-looking child stop',-31.9501,115.8602]] as $row) { $insert->execute($row); }
        $db=null; chmod($database,0400);
        $databaseHash=hash_file('sha256',$database);
        $fresh=gmdate(DATE_ATOM,time()-86400);
        $manifest=['version'=>1,'source'=>['name'=>'Geofabrik / OpenStreetMap','url'=>'https://download.geofabrik.de/australia-oceania/australia/western-australia-latest.osm.pbf',
                'updatedAt'=>$fresh,'license'=>'ODbL 1.0','attribution'=>'© OpenStreetMap contributors'],
            'stations'=>[
                '83'=>['name'=>'Fixture station A','file'=>'station-83.json','bounds'=>[115.8595,-31.9507,115.8607,-31.9495]],
                '64'=>['name'=>'Fixture station B','file'=>'station-64.json','bounds'=>[115.8608,-31.9514,115.8614,-31.9508]]],
            'stopToStation'=>['A'=>'83','B'=>'83','C'=>'64','0'=>'83']];
        $platform=smFeature('way/101','platform','Polygon',[[[115.8601,-31.9501],[115.8604,-31.9501],[115.8604,-31.9504],[115.8601,-31.9501]]],
            ['name'=>'Platform 2','ref'=>'2','ptaRef'=>'B','level'=>'-2','gtfsStopId'=>'B','unwantedUser'=>'Discard this contributor field']);
        $path=smFeature('way/102','footway','LineString',[[115.86,-31.95],[115.8602,-31.9502],[115.8605,-31.9504]],
            ['foot'=>'yes','wheelchair'=>'yes','bridge'=>'yes']);
        $lift=smFeature('node/103','lift','Point',[115.8602,-31.9502],['name'=>'Station lift','level'=>'-2;0;1','wheelchair'=>'yes']);
        $private=smFeature('way/104','footway','LineString',[[115.8601,-31.9501],[115.8602,-31.9502]],['access'=>'private']);
        $stairs=smFeature('way/105','steps','LineString',[[115.8602,-31.9502],[115.8603,-31.9503]],['level'=>'0;1']);
        $building=smFeature('way/106','building','MultiPolygon',[[[[115.8599,-31.9499],[115.86,-31.9499],[115.86,-31.95],[115.8599,-31.9499]]]]);
        $entrance=smFeature('node/107','entrance','Point',[115.8601,-31.9501],['ref'=>'Entrance A']);
        $crossing=smFeature('node/108','crossing','Point',[115.8603,-31.9503]);
        $rail=smFeature('way/109','rail','LineString',[[115.8602,-31.9501],[115.8605,-31.9505]],['tunnel'=>'yes','layer'=>'-1']);
        $stand=smFeature('node/110','bus_stop','Point',[115.86,-31.95],['ref'=>'A','gtfsStopIds'=>['A','0']]);
        $second=smFeature('node/201','entrance','Point',[115.8611,-31.9511],['name'=>'Second station entrance']);
        $collections=['station-83.json'=>['type'=>'FeatureCollection','features'=>[$platform,$path,$lift,$private,$stairs,$building,$entrance,$crossing,$rail,$stand]],
            'station-64.json'=>['type'=>'FeatureCollection','features'=>[$path,$second]]];
        $catalog=smCatalog($manifest,$collections);
        $read=static fn(string $a='A',string $b='B',?string $root=null): array => StationMap::forStops($a,$b,$database,$root ?? $catalog);
        $result=$read();
        $features=array_column($result['featureCollection']['features'],null,'id');
        smCheck($result['available'] && count($result['stations'])===1 && $result['stations'][0]['id']==='83','public stop IDs select their exact station group without a nearest-platform guess');
        smCheck($result['featureCollection']['type']==='FeatureCollection' && count($features)===10,'station detail returns the complete supported fixture feature collection');
        smCheck($features['way/101']['geometry']===$platform['geometry'] && $features['way/102']['geometry']===$path['geometry'],'platform outline and bent walking geometry retain GeoJSON longitude/latitude order');
        smCheck($features['way/101']['properties']['ref']==='2' && $features['way/101']['properties']['ptaRef']==='B'
            && $features['way/101']['properties']['gtfsStopId']==='B','exact platform and public-stop references survive without reassigning nearby features');
        smCheck($features['node/103']['properties']['level']==='-2;0;1' && $result['levels']===['-2','0','1'],'multi-level station features retain their levels in numeric display order');
        smCheck($features['way/104']['properties']['restricted']===true && $features['way/102']['properties']['restricted']===false,'private access is explicitly distinguished from mapped public walking context');
        smCheck($features['way/102']['properties']['wheelchair']==='yes' && $features['way/109']['properties']['tunnel']==='yes','mapped access and structure tags remain descriptive feature metadata');
        smCheck(!isset($features['way/101']['properties']['unwantedUser']),'incidental contributor fields are not republished in the station response');
        smCheck($features['node/110']['properties']['gtfsStopIds']===['A','0'],'multiple exact public-stop references preserve numeric-looking identifiers');
        smCheck($result['source']==='Geofabrik / OpenStreetMap' && str_contains($result['attribution'],'OpenStreetMap')
            && $result['license']==='ODbL 1.0' && str_contains($result['sourceUrl'],'geofabrik.de'),'station detail exposes its public map source, attribution and data licence');
        smCheck(strtotime($result['updatedAt'])===strtotime($fresh) && !$result['stale'],'source snapshot date stays distinct from request time and a fresh snapshot is not stale');
        smCheck($result['stations'][0]['officialPlanUrl']==='https://www.transperth.wa.gov.au/timetablePDFs/BusStationMaps/Canning%20Bridge%20Station.pdf','a known station links its official plan as a reference without pretending PDF pixels are GPS coordinates');
        $merged=$read('A','C');
        smCheck(count($merged['stations'])===2 && count($merged['featureCollection']['features'])===11,'a transfer spanning two station groups merges their geometry and deduplicates overlapping OSM features');
        smCheck(str_contains($merged['stations'][1]['officialPlanUrl'],'Perth%20Underground'),'second station retains its own official plan reference');
        smCheck($read('83','83')['available'] && $read('0','0')['available'],'parent-station and numeric-looking child identifiers both resolve normally');
        $unlinked=$read('UNLINKED','UNLINKED');
        smCheck($unlinked['available'] && $unlinked['stations'][0]['id']==='64','an unlinked public sign can use the station extract whose recorded bounds actually contain it');
        $mixedEndpoints=$read('A','UNLINKED');
        smCheck(array_column($mixedEndpoints['stations'],'id')===['83','64']
            && in_array('node/201',array_column($mixedEndpoints['featureCollection']['features'],'id'),true),
            'a mapped first station does not suppress detail for an unlinked endpoint inside a second station area');
        $none=$read('OUTSIDE','OUTSIDE');
        smCheck(!$none['available'] && $none['featureCollection']['features']===[],'a stop outside supplied station areas receives no invented station geometry');

        $staleManifest=$manifest; $staleManifest['source']['updatedAt']=gmdate(DATE_ATOM,time()-100*86400);
        $stale=$read('A','B',smCatalog($staleManifest,$collections));
        smCheck($stale['available'] && $stale['stale'],'an older map snapshot remains useful while being explicitly marked stale');
        $invalidDate=$manifest; $invalidDate['source']['updatedAt']='not a source date';
        smCheck($read('A','B',smCatalog($invalidDate,$collections))['updatedAt']===null,'an invalid source date is not replaced by a misleading fresh timestamp');
        $missing=APP_ROOT.'/missing-catalog';
        smCheck(!$read('A','B',$missing)['available'] && !file_exists($missing),'missing optional detail falls back without creating a catalog');
        $broken=smCatalog($manifest,$collections); file_put_contents($broken.'/manifest.json','{broken');
        smCheck(!$read('A','B',$broken)['available'],'a malformed manifest cannot break the transfer map fallback');
        $badVersion=$manifest; $badVersion['version']=999;
        smCheck(!$read('A','B',smCatalog($badVersion,$collections))['available'],'an unsupported catalog version is ignored safely');
        $brokenCollection=smCatalog($manifest,$collections); file_put_contents($brokenCollection.'/station-83.json','{broken');
        smCheck(!$read('A','B',$brokenCollection)['available'],'a corrupt station collection leaves an explicit empty context instead of fabricating geometry');
        $noCollection=smCatalog($manifest,[]);
        smCheck(!$read('A','B',$noCollection)['available'],'a missing station file leaves the existing transfer route usable');

        $escaped=$manifest; $escaped['stations']['83']['file']='../outside.json';
        smWrite(APP_ROOT.'/outside.json',$collections['station-83.json']);
        smCheck(!$read('A','B',smCatalog($escaped,[]))['available'],'manifest filenames cannot traverse outside the station catalog');
        $linkedFile=smCatalog($manifest,[]); symlink($catalog.'/station-83.json',$linkedFile.'/station-83.json');
        smCheck(!$read('A','B',$linkedFile)['available'],'a symlinked collection cannot redirect reads outside its catalog');
        $linkedManifest=smCatalog($manifest,[]); unlink($linkedManifest.'/manifest.json'); symlink($catalog.'/manifest.json',$linkedManifest.'/manifest.json');
        smCheck(!$read('A','B',$linkedManifest)['available'],'a symlinked manifest is rejected');
        symlink($catalog,APP_ROOT.'/catalog-link');
        smCheck(!$read('A','B',APP_ROOT.'/catalog-link')['available'],'a symlinked catalog root is rejected');

        $invalid=[
            smFeature('node/301','lift','Point',[200,-31.95]),
            smFeature('node/302','entrance','Point',[115.86,100]),
            smFeature('way/303','footway','LineString',[115.86,-31.95]),
            smFeature('way/304','platform','Polygon',[[[115.86,-31.95],[115.861,-31.95],[115.861,-31.951]]]),
            smFeature('way/305','building','Polygon',[[[115.86,-31.95],[115.861,-31.95],[115.861,-31.951],[115.862,-31.952]]]),
            smFeature('node/306','not-a-station-layer','Point',[115.86,-31.95]),
            smFeature('../307','entrance','Point',[115.86,-31.95]),
            smFeature('way/308','footway','LineString',[[115.86,-31.95]]),
        ];
        $mixed=['station-83.json'=>['type'=>'FeatureCollection','features'=>array_merge([$path],$invalid)]];
        $filtered=$read('A','B',smCatalog($manifest,$mixed));
        smCheck(array_column($filtered['featureCollection']['features'],'id')===['way/102'],'malformed positions, unclosed outlines, invalid line nesting and unsupported layers are omitted without losing valid paths');
        $denied=smFeature('way/401','footway','LineString',[[115.86,-31.95],[115.861,-31.951]],['foot'=>'no']);
        $footNo=$read('A','B',smCatalog($manifest,['station-83.json'=>['type'=>'FeatureCollection','features'=>[$denied]]]));
        smCheck($footNo['featureCollection']['features'][0]['properties']['restricted'],'foot=no remains visibly restricted even without an access tag');
        $denied['properties']['foot']='private';
        $privateFoot=$read('A','B',smCatalog($manifest,['station-83.json'=>['type'=>'FeatureCollection','features'=>[$denied]]]));
        smCheck($privateFoot['featureCollection']['features'][0]['properties']['restricted'],'private pedestrian access is not drawn as an ordinary public station footpath');

        smReject(static fn()=>$read('UNKNOWN','B'),'unknown public stop IDs are rejected before catalog lookup');
        smReject(static fn()=>$read('A','FAR'),'distant endpoints are rejected rather than loading unrelated station areas');
        smReject(static fn()=>$read('','B'),'empty station identifiers are rejected');
        smReject(static fn()=>$read("A\n",'B'),'control characters cannot be used as station identifiers');
        smReject(static fn()=>$read(str_repeat('A',201),'B'),'oversized public stop identifiers are rejected');
        $missingDb=APP_VAR.'/missing.sqlite';
        smReject(static fn()=>StationMap::forStops('A','B',$missingDb,$catalog),'missing timetable reports unavailable without creating a SQLite database',RuntimeException::class);
        smCheck(!file_exists($missingDb),'failed reads leave no empty timetable file');
        symlink($database,APP_VAR.'/linked.sqlite');
        smReject(static fn()=>StationMap::forStops('A','B',APP_VAR.'/linked.sqlite',$catalog),'a symlinked timetable cannot redirect station lookups',RuntimeException::class);
        smCheck(hash_file('sha256',$database)===$databaseHash,'station lookups leave the read-only timetable byte-for-byte unchanged');
        smCheck($GLOBALS['stationMapNetworkAttempts']===0,'all station detail comes from local fixtures with no external request');
        smCheck(glob(APP_VAR.'/*')===[APP_VAR.'/linked.sqlite',$database],'station lookups create no private cache or incidental state');
        echo PHP_EOL.$checks.' station-map checks passed. Synthetic geometry and stops only; no live provider calls.'.PHP_EOL;
    } finally {
        chmod($database ?? APP_VAR.'/transit.sqlite',0600);
        smCleanup($isolated);
    }
}
