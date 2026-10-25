<?php
declare(strict_types=1);

// Run the downloaders without the cURL extension, providing only legacy options.
// This reproduces the customer's missing constant instead of merely checking text.
if (($argv[1] ?? '')==='--fixture') {
    if (extension_loaded('curl') || defined('CURLOPT_XFERINFOFUNCTION')) { throw new RuntimeException('Fixture must have no cURL constants.'); }
    $keys=['CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_FOLLOWLOCATION','CURLOPT_PROTOCOLS','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_USERAGENT','CURLOPT_HEADERFUNCTION','CURLOPT_WRITEFUNCTION','CURLINFO_RESPONSE_CODE','CURLINFO_HTTP_CODE','CURLPROTO_HTTPS'];
    foreach ($keys as $i=>$key) { define($key,$i+1); }
    $GLOBALS['fixtureBody']=str_repeat('download fixture ',800);
    $GLOBALS['fixtureKnown']=true;
    function curl_init(string $url): object { return (object)['url'=>$url]; }
    function curl_setopt_array(object $handle,array $options): bool { $handle->options=$options; $GLOBALS['fixtureOptions']=$options; return true; }
    function curl_exec(object $handle): bool {
        $o=$handle->options; $body=$GLOBALS['fixtureBody'];
        $o[CURLOPT_HEADERFUNCTION]($handle,"HTTP/1.1 200 OK\r\n");
        if ($GLOBALS['fixtureKnown']) { $o[CURLOPT_HEADERFUNCTION]($handle,'Content-Length: '.strlen($body)."\r\n"); }
        usleep(850000);
        foreach (str_split($body,4096) as $chunk) { if ($o[CURLOPT_WRITEFUNCTION]($handle,$chunk)!==strlen($chunk)) { return false; } }
        return true;
    }
    function curl_getinfo(object $handle,int $option): int { return 200; }
    function curl_error(object $handle): string { return ''; }
    $root=$argv[2];
    define('PM_LOADER_LIBRARY_ONLY',true); require $root.'/loader.php';
    require dirname(__DIR__).'/src/GtfsImporter.php';
    pm_prepare_private(); $lock=pm_lock(); $checks=0;
    $check=static function(bool $ok,string $message) use (&$checks): void {
        if (!$ok) { throw new RuntimeException($message); } $checks++; echo 'PASS: '.$message.PHP_EOL;
    };
    $check(!defined('CURLOPT_XFERINFOFUNCTION'),'host fixture genuinely lacks the newer cURL progress constant');
    foreach ([true,false] as $known) {
        $GLOBALS['fixtureKnown']=$known;
        pm_progress_begin('update'); $file=$root.'/package-'.(int)$known.'.zip';
        pm_download_archive($file);
        $check(file_get_contents($file)===$GLOBALS['fixtureBody'],'standalone package download succeeds with '.($known?'known':'unknown').' length');
        $state=pm_status()['progress'];
        $check($state['completed']>0 && $state['unit']==='bytes','standalone package still reports actual received bytes');
        $o=$GLOBALS['fixtureOptions'];
        $check($o[CURLOPT_SSL_VERIFYPEER]===true && $o[CURLOPT_SSL_VERIFYHOST]===2 && $o[CURLOPT_FOLLOWLOCATION]===false,'standalone compatibility keeps verified HTTPS and fixed-source redirects disabled');
        $events=[]; $importer=new App\GtfsImporter();
        (new ReflectionProperty($importer,'progress'))->setValue($importer,static function(array $e) use (&$events): void { $events[]=$e; });
        $target=$root.'/timetable-'.(int)$known.'.zip';
        (new ReflectionMethod($importer,'download'))->invoke($importer,$target);
        $check(file_get_contents($target)===$GLOBALS['fixtureBody'],'official timetable download also succeeds without the newer constant');
        $last=end($events);
        $check($last['completed']===strlen($GLOBALS['fixtureBody']) && $last['percent']===100,'official timetable completion retains exact byte counts');
    }
    flock($lock,LOCK_UN); fclose($lock);
    echo $checks.' missing-cURL-option compatibility checks passed. Simulated transfers only.'.PHP_EOL;
    exit;
}
$root=sys_get_temp_dir().'/perth-curl-compat-'.bin2hex(random_bytes(6)); mkdir($root,0700);
copy(dirname(__DIR__).'/loader.php',$root.'/loader.php');
try {
    $process=proc_open([PHP_BINARY,'-n',__FILE__,'--fixture',$root],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process);
    echo $output;
    if ($code!==0) { throw new RuntimeException($errors); }
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
