<?php
declare(strict_types=1);

/** Isolated fixture tests. No live HTTP requests or production data mutations. */
$project = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/perth-payphone-test-' . bin2hex(random_bytes(6));
mkdir($temporary . '/var', 0700, true);
define('APP_VAR', $temporary . '/var');
define('PAYPHONES_TESTING', true);
require $project . '/src/Payphones.php';

use App\Payphones;

$checks = 0;
function pp_check(bool $passed, string $message): void
{
    global $checks;
    if (!$passed) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++;
    echo 'PASS: ' . $message . PHP_EOL;
}

function pp_private(string $path): array
{
    return json_decode(explode("\n", file_get_contents($path), 2)[1], true, 512, JSON_THROW_ON_ERROR);
}

function pp_write_private(string $path, array $data): void
{
    file_put_contents($path, "<?php http_response_code(404); exit; ?>\n" . json_encode($data, JSON_THROW_ON_ERROR));
}

function pp_remove(string $directory): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($directory);
}

function pp_workbook(string $path, array $headers, array $rows): void
{
    $strings=[];$indexes=[];
    $cell=static function(string $value,string $ref,bool $numeric=false)use(&$strings,&$indexes):string{
        if($numeric&&is_numeric($value)){return '<c r="'.$ref.'"><v>'.$value.'</v></c>';}
        if(!array_key_exists($value,$indexes)){$indexes[$value]=count($strings);$strings[]=$value;}
        return '<c r="'.$ref.'" t="s"><v>'.$indexes[$value].'</v></c>';
    };
    $sheet='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach(array_merge([$headers],$rows)as$rowIndex=>$row){
        $sheet.='<row r="'.($rowIndex+1).'">';
        foreach($row as$i=>$value){$label=strtoupper(trim($headers[$i]??''));$sheet.=$cell((string)$value,chr(65+$i).($rowIndex+1),$rowIndex>0&&in_array($label,['SITE_LAT','SITE_LONGIT','PAYPHONES_AT_SITE'],true));}
        $sheet.='</row>';
    }
    $sheet.='</sheetData></worksheet>';
    $shared='<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    foreach($strings as$value){$shared.='<si><t>'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></si>';}
    $shared.='</sst>';
    $zip=new ZipArchive();$zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->addFromString('xl/sharedStrings.xml',$shared);$zip->addFromString('xl/worksheets/sheet1.xml',$sheet);
    $zip->addFromString('docProps/core.xml','<cp:coreProperties xmlns:cp="urn:core" xmlns:dcterms="http://purl.org/dc/terms/"><dcterms:modified>2026-10-11T23:34:56Z</dcterms:modified></cp:coreProperties>');
    $zip->close();
}

try {
    $outside = Payphones::inBounds([120, -35, 121, -34]);
    pp_check($outside['phones'] === [] && $outside['updatedAt'] === null && str_contains($outside['message'], 'limited to the Perth'), 'outside-coverage viewport returns locally without querying a provider');
    pp_check(glob(APP_VAR . '/*') === [], 'an outside-coverage request creates no provider request or cache state');
    foreach ([[1,2,3], [116,-32,115,-31], [115,-32,116,INF], [-181,-32,116,-31], [115,-91,116,-31], ['bad',-32,116,-31]] as $bbox) {
        $invalid = false;
        try { Payphones::inBounds($bbox); } catch (RuntimeException) { $invalid = true; }
        pp_check($invalid, 'invalid viewport is rejected before any external request');
    }

    $node = ['type'=>'node','id'=>1,'lat'=>-31.95,'lon'=>115.86,'tags'=>['amenity'=>'telephone','name'=>'Central phone','operator'=>'Telstra','addr:full'=>'10 Test Street, Perth','ref'=>'WA001']];
    $way = ['type'=>'way','id'=>2,'center'=>['lat'=>-31.96,'lon'=>115.87],'tags'=>['amenity'=>'telephone','operator'=>'Telstra','addr:housenumber'=>'20','addr:street'=>'Example Road','addr:suburb'=>'Perth','addr:postcode'=>'6000','ref:telstra'=>'WA002']];
    $relation = ['type'=>'relation','id'=>3,'center'=>['lat'=>-31.94,'lon'=>115.88],'tags'=>['amenity'=>'telephone','name'=>"<b>Accessible</b>\nphone\0",'access'=>'yes']];
    $anonymous = ['type'=>'node','id'=>13,'lat'=>-31.93,'lon'=>115.89,'tags'=>['amenity'=>'telephone']];
    $elements = [$node,$way,$relation,$anonymous,$node]; // duplicate OSM identity
    foreach (['private','no','customers'] as $i=>$access) {
        $copy=$node; $copy['id']=100+$i; $copy['tags']['access']=$access; $elements[]=$copy;
    }
    foreach (['disused','abandoned'] as $i=>$tag) {
        $copy=$node; $copy['id']=110+$i; $copy['tags'][$tag]='yes'; $elements[]=$copy;
    }
    $invalid=$node; $invalid['id']=120; $invalid['lat']=-35; $elements[]=$invalid;
    $invalid=$node; $invalid['id']=121; unset($invalid['lon']); $elements[]=$invalid;
    $invalid=$node; $invalid['id']=122; $invalid['tags']['amenity']='bank'; $elements[]=$invalid;
    $invalid=$node; $invalid['id']=123; $invalid['lat']=INF; $elements[]=$invalid;
    $invalid=$node; $invalid['id']=['not','an','id']; $elements[]=$invalid;
    $payload=['osm3s'=>['timestamp_osm_base'=>'2026-10-24T00:00:00Z'],'elements'=>$elements];
    $normalized=Payphones::normalize($payload);
    pp_check(count($normalized)===4, 'normalization deduplicates identities and excludes private, removed, invalid and out-of-area phones');
    $byId=array_column($normalized,null,'id');
    pp_check($byId['osm-way-2']['lat']===-31.96 && $byId['osm-relation-3']['lon']===115.88, 'ways and relations use their provided geographic centres');
    pp_check($byId['osm-node-1']['address']==='10 Test Street, Perth' && $byId['osm-way-2']['address']==='20 Example Road, Perth, 6000', 'addresses use only supplied OSM address metadata');
    pp_check($byId['osm-way-2']['ref']==='WA002' && $byId['osm-node-1']['operator']==='Telstra', 'operator and reference metadata remain available without claiming official inventory status');
    pp_check($byId['osm-relation-3']['name']==='Accessible phone' && $byId['osm-node-13']['name']==='Public telephone', 'display text removes markup and controls and supplies a neutral missing-name fallback');
    $long=$node; $long['tags']['name']=str_repeat('x',1000);
    pp_check(strlen(Payphones::normalize(['elements'=>[$long]])[0]['name'])===160, 'untrusted provider display names are bounded');
    foreach ([['remark'=>'runtime error: timeout','elements'=>[$node]], ['unrelated'=>'data']] as $bad) {
        $rejected=false;
        try { Payphones::normalize($bad); } catch (RuntimeException) { $rejected=true; }
        pp_check($rejected, 'partial or structurally invalid provider responses are rejected');
    }

    $headers=['cab_id',' ADD_ADDR_INFO',' STREET_NR',' STREET_NME',' STREET_TYP_CDE',' LOCALITY_NAME',' STATE',' POST_CDE',' SITE_LAT',' SITE_LONGIT',' payphones_at_site'];
    $records=[
        ['001X','Central phone','10','TEST','ST','PERTH','WA','6000','-31.95','115.86','1'],
        ['002X','Near station','20','EXAMPLE','RD','PERTH','WA','6000','-31.96','115.87','2'],
        ['003X','Fish & Chips <kiosk>','','SAMPLE','ST','PERTH','WA','6000','-31.94','115.88','1'],
        ['0004X','','40','FINAL','AVE','PERTH','WA','6000','-31.93','115.89','3'],
    ];
    $rows=$records;$rows[]=$records[0];
    $missing=$records[0];$missing[0]='MISSING';$missing[8]='';$rows[]=$missing;
    $invalid=$records[0];$invalid[0]='INVALID';$invalid[9]='not-a-number';$rows[]=$invalid;
    $otherState=$records[0];$otherState[0]='OTHER';$otherState[6]='NSW';$rows[]=$otherState;
    $outsideMetro=$records[0];$outsideMetro[0]='REGIONAL';$outsideMetro[8]='-34';$rows[]=$outsideMetro;
    $removed=$records[0];$removed[0]='ZERO';$removed[10]='0';$rows[]=$removed;
    pp_workbook($temporary.'/register.xlsx',$headers,$rows);
    $register=Payphones::normalizeRegister($temporary.'/register.xlsx');
    $registered=array_column($register['phones'],null,'id');
    pp_check(count($registered)===4&&$register['registerRecords']===10,'official workbook parsing filters metro WA rows and skips missing, invalid and zero-phone sites');
    pp_check($registered['telstra-001X']['ref']==='001X'&&$registered['telstra-0004X']['ref']==='0004X','text CAB_ID references preserve leading zeros');
    pp_check($registered['telstra-001X']['lat']===-31.95&&$registered['telstra-002X']['lon']===115.87,'official SITE_LAT and SITE_LONGIT numeric cells provide exact positions');
    pp_check($registered['telstra-002X']['phoneCount']===2&&array_sum(array_column($registered,'phoneCount'))===7,'per-site payphone counts remain distinct from the number of map markers');
    pp_check($registered['telstra-003X']['name']==='Fish & Chips'&&$registered['telstra-001X']['address']==='10 TEST ST, PERTH WA 6000','XML entities decode correctly and source address fields are assembled safely');
    pp_check($registered['telstra-001X']['operator']==='Telstra'&&$register['sourceUpdatedAt']==='2026-10-11T23:34:56Z','official source operator and workbook modification date retain correct provenance');
    $conflict=$records;$conflicting=$records[0];$conflicting[8]='-31.96';$conflict[]=$conflicting;
    pp_workbook($temporary.'/conflict.xlsx',$headers,$conflict);
    $badHeaders=$headers;$badHeaders[9]='wrong_longitude';pp_workbook($temporary.'/missing-header.xlsx',$badHeaders,$records);
    $duplicateHeaders=$headers;$duplicateHeaders[]=' SITE_LAT';$duplicateRows=array_map(static fn($row)=>array_merge($row,['-31.95']),$records);pp_workbook($temporary.'/duplicate-header.xlsx',$duplicateHeaders,$duplicateRows);
    foreach(['conflict.xlsx'=>'conflicting coordinates','missing-header.xlsx'=>'missing column SITE_LONGIT','duplicate-header.xlsx'=>'duplicate column names']as$file=>$expected){
        $rejected=false;try{Payphones::normalizeRegister($temporary.'/'.$file);}catch(RuntimeException$error){$rejected=str_contains($error->getMessage(),$expected);}
        pp_check($rejected,'unsafe or ambiguous official workbook is rejected: '.$expected);
    }
    copy($temporary.'/register.xlsx',$temporary.'/xxe.xlsx');$zip=new ZipArchive();$zip->open($temporary.'/xxe.xlsx');$sheet=$zip->getFromName('xl/worksheets/sheet1.xml');$zip->addFromString('xl/worksheets/sheet1.xml','<!DOCTYPE worksheet [<!ENTITY xxe SYSTEM "file:///unread-fixture">]>'.$sheet);$zip->close();
    $rejected=false;try{Payphones::normalizeRegister($temporary.'/xxe.xlsx');}catch(RuntimeException$error){$rejected=str_contains($error->getMessage(),'prohibited XML');}
    pp_check($rejected,'DTD and external entity declarations are rejected without XML expansion');
    copy($temporary.'/register.xlsx',$temporary.'/many-files.xlsx');$zip=new ZipArchive();$zip->open($temporary.'/many-files.xlsx');for($i=0;$i<101;$i++){$zip->addFromString('extra/'.$i.'.txt','fixture');}$zip->close();
    $rejected=false;try{Payphones::normalizeRegister($temporary.'/many-files.xlsx');}catch(RuntimeException$error){$rejected=str_contains($error->getMessage(),'too many files');}
    pp_check($rejected,'workbook file-count limits reject expanded attack surfaces before parsing');
    copy($temporary.'/register.xlsx',$temporary.'/oversized.xlsx');$large=fopen($temporary.'/expanded-limit.xml','wb');fseek($large,50_000_000);fwrite($large,'x');fclose($large);
    $zip=new ZipArchive();$zip->open($temporary.'/oversized.xlsx');$zip->addFile($temporary.'/expanded-limit.xml','extra/large.xml');$zip->close();
    $rejected=false;try{Payphones::normalizeRegister($temporary.'/oversized.xlsx');}catch(RuntimeException$error){$rejected=str_contains($error->getMessage(),'50 MB expanded limit');}
    pp_check($rejected,'expanded ZIP limits reject compressed oversized content before reading XML');
    copy($temporary.'/register.xlsx',$temporary.'/entity.xlsx');$zip=new ZipArchive();$zip->open($temporary.'/entity.xlsx');$shared=$zip->getFromName('xl/sharedStrings.xml');$zip->addFromString('xl/sharedStrings.xml',str_replace('<t>cab_id</t>','<t>cab_id &unknown;</t>',$shared));$zip->close();
    $rejected=false;try{Payphones::normalizeRegister($temporary.'/entity.xlsx');}catch(RuntimeException$error){$rejected=str_contains($error->getMessage(),'unsupported XML entity');}
    pp_check($rejected,'unknown XML entities cannot enter register metadata');
    copy($temporary.'/register.xlsx',$temporary.'/inline.xlsx');$zip=new ZipArchive();$zip->open($temporary.'/inline.xlsx');$sheet=$zip->getFromName('xl/worksheets/sheet1.xml');$sheet=preg_replace('/<c r="A1" t="s"><v>[^<]*<\/v><\/c>/','<c r="A1" t="inlineStr"><is><t>cab_id</t></is></c>',$sheet,1);$zip->addFromString('xl/worksheets/sheet1.xml',$sheet);$zip->close();
    pp_check(count(Payphones::normalizeRegister($temporary.'/inline.xlsx')['phones'])===4,'valid inline-string cells work without a DOM or SimpleXML dependency');
    $payload=$register;

    $fetches=0;
    $saved=Payphones::refresh(static function()use($payload,&$fetches){$fetches++;return $payload;});
    pp_check($fetches===1 && count($saved['phones'])===4 && is_file(APP_VAR.'/payphones.php'), 'one bounded refresh saves a complete metro dataset');
    pp_check(str_starts_with(file_get_contents(APP_VAR.'/payphones.php'),'<?php http_response_code(404); exit; ?>') && (fileperms(APP_VAR.'/payphones.php')&0777)===0600, 'cache data is guarded PHP plus JSON with private file permissions');
    $hash=hash_file('sha256',APP_VAR.'/payphones.php');
    $one=Payphones::inBounds([115.859,-31.951,115.861,-31.949]);
    pp_check(count($one['phones'])===1 && $one['phones'][0]['id']==='telstra-001X' && $one['stale']===false, 'viewport filtering uses stored coordinates without another provider request');
    $all=Payphones::inBounds([-180,-90,180,90]);
    pp_check(count($all['phones'])===4 && $all['source']==='Telstra public payphone register' && !str_contains($all['attribution'],'ODbL') && $all['sourceUrl']===Payphones::SOURCE_PAGE && $all['totalPhones']===7 && $all['siteCount']===4, 'official register response provides correct source attribution and separates sites from phone counts');
    Payphones::inBounds([115.85,-31.97,115.9,-31.92]);
    pp_check(hash_file('sha256',APP_VAR.'/payphones.php')===$hash && $fetches===1, 'repeated pans reuse the same dataset instead of fetching per viewport');

    $old=pp_private(APP_VAR.'/payphones.php');
    $old['updatedAt']=date(DATE_ATOM,time()-8*86400);
    pp_write_private(APP_VAR.'/payphones.php',$old);
    $oldHash=hash_file('sha256',APP_VAR.'/payphones.php');
    $failed=false;
    try { Payphones::refresh(static function(){throw new RuntimeException('Provider HTTP 429');}); } catch(RuntimeException){$failed=true;}
    $stale=Payphones::inBounds(Payphones::COVERAGE);
    pp_check($failed && hash_file('sha256',APP_VAR.'/payphones.php')===$oldHash && count($stale['phones'])===4 && $stale['stale']===true, 'provider failure retains stale locations rather than replacing them with an empty map');
    $statusBefore=file_get_contents(APP_VAR.'/payphones-status.php');
    Payphones::inBounds(Payphones::COVERAGE);
    pp_check(file_get_contents(APP_VAR.'/payphones-status.php')===$statusBefore, 'failure backoff prevents repeated provider requests while the user pans');
    foreach ([['unexpected'=>'data'],['phones'=>[]]] as $failedPayload) {
        $rejected=false;
        try{Payphones::refresh(static fn()=>$failedPayload);}catch(RuntimeException){$rejected=true;}
        pp_check($rejected && hash_file('sha256',APP_VAR.'/payphones.php')===$oldHash, 'incomplete or empty metro results cannot overwrite the last useful cache');
    }
    $recovered=Payphones::refresh(static fn()=>$payload);
    pp_check(Payphones::inBounds(Payphones::COVERAGE)['stale']===false && $recovered['generation']!==$old['generation'], 'a successful later refresh clears stale status and publishes a new generation');
    pp_write_private(APP_VAR.'/payphones-status.php',['attemptedAt'=>time(),'sourceUrl'=>'https://overpass-api.de/api/interpreter','ok'=>false,'failedGeneration'=>$recovered['generation']]);
    pp_check(Payphones::inBounds(Payphones::COVERAGE)['stale']===false,'failure status from the retired provider cannot poison fresh official register data');
    $legacy=['format'=>1,'generation'=>'legacy-fixture','updatedAt'=>date(DATE_ATOM,time()-8*86400),'source'=>'OpenStreetMap','phones'=>$normalized];
    pp_write_private(APP_VAR.'/payphones.php',$legacy);pp_write_private(APP_VAR.'/payphones-status.php',['attemptedAt'=>time(),'sourceUrl'=>Payphones::SOURCE_URL,'ok'=>false,'failedGeneration'=>'legacy-fixture']);
    $legacyResponse=Payphones::inBounds(Payphones::COVERAGE);
    pp_check($legacyResponse['source']==='OpenStreetMap'&&str_contains($legacyResponse['attribution'],'ODbL')&&$legacyResponse['stale']===true&&$legacyResponse['totalPhones']===null,'retained legacy OSM data keeps its own attribution and is never relabelled official Telstra');
    Payphones::refresh(static fn()=>$payload);
    pp_check(Payphones::inBounds(Payphones::COVERAGE)['source']==='Telstra public payphone register'&&pp_private(APP_VAR.'/payphones.php')['format']===2,'successful refresh replaces legacy source data with version-two official provenance');
    $lock=fopen(APP_VAR.'/payphones.lock','c');flock($lock,LOCK_EX);
    try {
        $busy=false;
        try{Payphones::refresh(static fn()=>$payload);}catch(RuntimeException $error){$busy=str_contains($error->getMessage(),'already being refreshed');}
        pp_check($busy && count(Payphones::inBounds(Payphones::COVERAGE)['phones'])===4, 'concurrent refresh locking preserves immediate access to saved locations');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    unlink(APP_VAR.'/payphones.php');
    try{Payphones::refresh(static function(){throw new RuntimeException('Provider HTTP 503');});}catch(RuntimeException){}
    $unavailable=Payphones::inBounds(Payphones::COVERAGE);
    pp_check($unavailable['phones']===[] && $unavailable['updatedAt']===null && $unavailable['stale']===true && str_contains($unavailable['message'],'No locations have been confirmed'), 'first-load failure reports unavailable coverage instead of a successful zero-phone dataset');
    pp_check(glob(APP_VAR.'/*.tmp.php')===[], 'successful and failed refreshes leave no incomplete cache files');
    echo PHP_EOL.$checks.' public telephone checks passed. Fixture locations only; no live provider calls.'.PHP_EOL;
}finally{
    pp_remove($temporary);
}
