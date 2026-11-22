<?php
declare(strict_types=1);
/** Offline release identity, pinned-source and rollback fixtures; no production downloads. */
$project=dirname(__DIR__);$tmp=sys_get_temp_dir().'/nextstop-release-'.bin2hex(random_bytes(6));mkdir($tmp.'/site',0700,true);
$site=$tmp.'/site';$original=file_get_contents($project.'/loader.php');
$instrumented=str_replace("function pm_progress_report(array \$event): void\n{", "function pm_progress_report(array \$event): void\n{\n    if (!empty(\$GLOBALS['release_test_fail']) && (\$event['stage'] ?? '')==='app-publish' && (\$event['completed'] ?? 0)>0) { throw new RuntimeException('Fixture interrupted publish'); }",$original);
$instrumented=str_replace("function pm_write_private(string \$path, array \$data): void\n{", "function pm_write_private(string \$path, array \$data): void\n{\n    if (!empty(\$GLOBALS['release_test_metadata_fail']) && str_ends_with(\$path,'/last-update.php')) { throw new RuntimeException('Fixture metadata failure'); }",$instrumented);
file_put_contents($site.'/loader.php',$instrumented);define('PM_LOADER_LIBRARY_ONLY',true);require $site.'/loader.php';
$n=0;function check(bool $ok,string $message):void{global $n;if(!$ok)throw new RuntimeException('FAIL: '.$message);$n++;echo 'PASS: '.$message.PHP_EOL;}
function package(string $path,array $files):void{$z=new ZipArchive();$z->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE);foreach($files as $p=>$v)$z->addFromString('release/'.$p,$v);$z->close();}
function source(array $files,string $revision):array{$tree=['sha'=>str_repeat('b',40),'tree'=>[]];foreach($files as $p=>$v)$tree['tree'][]=['path'=>$p,'type'=>'blob','mode'=>'100644','sha'=>sha1('blob '.strlen($v)."\0".$v),'size'=>strlen($v)];return ['revision'=>$revision,'tree'=>$tree['sha'],'files'=>pm_release_files($tree),'checksUrl'=>'https://github.com/efrgdgr0024345/map/actions/runs/123'];}
function rejected(callable $action):bool{try{$action();return false;}catch(RuntimeException){return true;}}
try{
 pm_prepare_private();$rev=str_repeat('a',40);$files=[];foreach(pm_application_manifest() as $path)$files[$path]='fixture contents '.$path;
 $files['loader.php']=$instrumented;$files['assets/retired.js']='old managed fixture';
 $release=source($files,$rev);$tree=['sha'=>$release['tree'],'tree'=>array_values($release['files'])];
 $read=static function($url)use($rev,$tree){if(str_contains($url,'/commits/main'))return json_encode(['sha'=>$rev,'commit'=>['tree'=>['sha'=>$tree['sha']]]]);if(str_contains($url,'/actions/'))return json_encode(['workflow_runs'=>[['head_sha'=>$rev,'head_branch'=>'main','event'=>'push','conclusion'=>'success','id'=>123]]]);return json_encode($tree);};
 check(pm_source_release($rev,$read)['revision']===$rev,'release resolves the exact successful main commit');
 check(rejected(static fn()=>pm_source_release(str_repeat('c',40),$read)),'a moved main branch cannot replace the requested tested commit');
 $pending=static fn($u)=>str_contains($u,'/actions/')?json_encode(['workflow_runs'=>[['head_sha'=>$rev,'head_branch'=>'main','event'=>'push','conclusion'=>'failure','id'=>123]]]):$read($u);
 check(rejected(static fn()=>pm_source_release($rev,$pending)),'failed or pending checks cannot publish code');
 $pr=static fn($u)=>str_contains($u,'/actions/')?json_encode(['workflow_runs'=>[['head_sha'=>$rev,'head_branch'=>'main','event'=>'pull_request','conclusion'=>'success','id'=>123]]]):$read($u);
 check(rejected(static fn()=>pm_source_release($rev,$pr)),'a pull-request check cannot stand in for the main push workflow');
 $badTree=static fn($u)=>str_contains($u,'/git/trees/')?json_encode(['sha'=>str_repeat('c',40),'tree'=>$tree['tree']]):$read($u);
 check(rejected(static fn()=>pm_source_release($rev,$badTree)),'the source tree must belong to the pinned commit');
 package($tmp.'/one.zip',$files);$first=pm_apply_archive($tmp.'/one.zip',$release);$record=pm_read_private($site.'/var/release.php');
 check($first['release']['verified']&&$record['revision']===$rev,'a complete package records its installed commit only after verification');
 check(pm_verify_release()['verified']&&pm_verify_release()['fileCount']===count($files),'every managed file including the loader is rehashed');
 $expected=[];foreach($files as $p=>$v)$expected[$p]=hash('sha256',$v);
 check(pm_verify_release()['manifestDigest']===pm_manifest_digest($expected),'the installed manifest is a deterministic SHA-256 digest of exact source bytes');
 file_put_contents($site.'/var/private-config.php','private data');file_put_contents($site.'/config.php','local hosting config');
 check(pm_verify_release()['verified'],'private runtime data and explicitly preserved host config are excluded from application identity');
 file_put_contents($site.'/assets/app.js','manual edit');$changed=pm_verify_release();
 check(!$changed['verified']&&in_array('assets/app.js',$changed['changed'],true),'manual production edits are detected');
 pm_apply_archive($tmp.'/one.zip',$release);unlink($site.'/assets/style.css');
 check(!pm_verify_release()['verified'],'a missing application file invalidates verification');pm_apply_archive($tmp.'/one.zip',$release);
 file_put_contents($site.'/unexpected.php','unrecorded code');
 check(!pm_verify_release()['verified']&&rejected(static fn()=>pm_apply_archive($tmp.'/one.zip',$release)),'unexpected code is reported and blocks publishing instead of being silently retained');
 unlink($site.'/unexpected.php');$tampered=$files;$tampered['index.php']='wrong package';package($tmp.'/bad.zip',$tampered);
 check(rejected(static fn()=>pm_apply_archive($tmp.'/bad.zip',$release))&&pm_verify_release()['verified'],'a package with changed bytes is rejected before replacing application files');
 $missing=$files;unset($missing['assets/retired.js']);package($tmp.'/missing.zip',$missing);
 check(rejected(static fn()=>pm_apply_archive($tmp.'/missing.zip',$release)),'the package must include every file in the pinned commit');
 $next=$files;unset($next['assets/retired.js']);$next['index.php']='next source';$next['assets/new.js']='new source';$nextRelease=source($next,str_repeat('d',40));package($tmp.'/two.zip',$next);
 $GLOBALS['release_test_fail']=true;
 check(rejected(static fn()=>pm_apply_archive($tmp.'/two.zip',$nextRelease)),'an interrupted publish reports failure');$GLOBALS['release_test_fail']=false;
 check(pm_verify_release()['verified']&&pm_verify_release()['revision']===$rev&&file_get_contents($site.'/assets/retired.js')===$files['assets/retired.js'],'rollback restores overwritten and retired files and keeps the previous release identity');
 $GLOBALS['release_test_metadata_fail']=true;
 check(rejected(static fn()=>pm_apply_archive($tmp.'/two.zip',$nextRelease)),'failure after writing release metadata cannot claim success');$GLOBALS['release_test_metadata_fail']=false;
 check(pm_verify_release()['verified']&&pm_verify_release()['revision']===$rev,'metadata failure restores the old manifest and application bytes');
 $second=pm_apply_archive($tmp.'/two.zip',$nextRelease);
 check($second['removed']===1&&!file_exists($site.'/assets/retired.js')&&is_file($site.'/'.$second['backup'].'/assets/retired.js'),'only previously managed retired files are removed, with a backup');
 check(pm_verify_release()['verified']&&pm_verify_release()['revision']===$nextRelease['revision'],'the final installation matches the new commit exactly');
 $saved=pm_read_private($site.'/var/release.php');$saved['files']['../escape.php']=str_repeat('a',64);$saved['manifestDigest']=pm_manifest_digest($saved['files']);pm_write_private($site.'/var/release.php',$saved);
 check(rejected(static fn()=>pm_verify_release()),'a corrupt protected manifest cannot read or delete paths outside the application');
 echo "\n$n verified-release checks passed. Isolated source/package/fault fixtures only.\n";
}finally{pm_remove_tree($tmp);}
