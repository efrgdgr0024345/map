/**
 * Real loader UI verification in an isolated PHP fixture installation.
 * Only timetable importing and telephone data are local delayed fixtures.
 * Sessions, login, CSRF, operation locks, status polling and the progress UI
 * are the actual loader. curl_init is disabled: no provider is contacted.
 * Run: node tests/browser-loader-progress.mjs (uses existing NODE_PATH).
 */
import assert from 'node:assert/strict';
import {mkdtempSync,mkdirSync,copyFileSync,writeFileSync,openSync,closeSync,rmSync,existsSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,dirname,resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawn,execFileSync} from 'node:child_process';
import {createServer} from 'node:net';
import {createRequire} from 'node:module';

const require=createRequire(import.meta.url);
const {chromium}=require('playwright');
const project=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const php=process.env.PHP_BINARY||(existsSync('/workspace/.tools/php/run')?'/workspace/.tools/php/run':'php');
const root=mkdtempSync(join(tmpdir(),'loader-ui-fixture-'));
const key='fixture-only-loader-maintenance-key-not-for-production';
const guard='<?php http_response_code(404); exit; ?>\n';
let server,browser,holder,checks=0;
const errors=[];
const check=(condition,label)=>{assert.ok(condition,label);checks++;console.log('PASS:',label)};

try {
 copyFileSync(join(project,'loader.php'),join(root,'loader.php'));
 mkdirSync(join(root,'var'));mkdirSync(join(root,'sessions'));
 const manifest=JSON.parse(execFileSync(php,['-r',"define('PM_LOADER_LIBRARY_ONLY',true);require $argv[1];echo json_encode(pm_application_manifest());",join(root,'loader.php')],{encoding:'utf8'}));
 for(const name of manifest){
  if(['loader.php','bootstrap.php'].includes(name))continue;
  const path=join(root,name);mkdirSync(dirname(path),{recursive:true});writeFileSync(path,'Isolated application presence fixture only.\n');
 }
 execFileSync(php,['-r',`
  $db=new PDO('sqlite:'.$argv[1]);
  $db->exec("CREATE TABLE meta(key TEXT PRIMARY KEY,value TEXT);
   CREATE TABLE stops(id TEXT PRIMARY KEY);CREATE TABLE routes(id TEXT PRIMARY KEY);
   CREATE TABLE trips(id TEXT PRIMARY KEY,service_id TEXT);CREATE TABLE stop_times(id TEXT);CREATE TABLE connections(id TEXT);
   CREATE TABLE calendar(service_id TEXT,mon INTEGER,tue INTEGER,wed INTEGER,thu INTEGER,fri INTEGER,sat INTEGER,sun INTEGER,start TEXT,end TEXT);
   CREATE TABLE exceptions(service_id TEXT,date TEXT,type INTEGER);
   INSERT INTO stops VALUES('fixture-stop');INSERT INTO routes VALUES('fixture-route');
   INSERT INTO trips VALUES('fixture-trip','daily');INSERT INTO stop_times VALUES('fixture-time');INSERT INTO connections VALUES('fixture-connection');
   INSERT INTO calendar VALUES('daily',1,1,1,1,1,1,1,'20000101','20991231');
   INSERT INTO meta VALUES('start_date','20000101'),('end_date','20991231'),('imported_at','2026-10-25T00:00:00+08:00');");
 `,join(root,'var/transit.sqlite')]);
 writeFileSync(join(root,'bootstrap.php'),`<?php
 namespace { date_default_timezone_set('Australia/Perth');define('APP_VAR',__DIR__.'/var'); }
 namespace App {
  final class GtfsImporter {
   public function import(?string $local=null,?callable $progress=null):array {
    if($progress)$progress(['stage'=>'import','key'=>'stop_times','label'=>'Importing synthetic stop times','message'=>'Fixture rows are being processed; no provider is contacted.','percent'=>50,'completed'=>1250,'total'=>2500,'unit'=>'rows']);
    file_put_contents(APP_VAR.'/fixture-import-started','yes');usleep(2200000);
    if($progress)$progress(['stage'=>'import','key'=>'shapes','label'=>'Importing synthetic route shapes','message'=>'Fixture shapes are being processed.','percent'=>50,'completed'=>5000,'total'=>10000,'unit'=>'rows']);
    usleep(2200000);
    if(is_file(APP_VAR.'/fixture-import-fail'))throw new \\RuntimeException('Synthetic import failed midway');
    if($progress)$progress(['stage'=>'complete','label'=>'Synthetic timetable ready','message'=>'Fixture import complete.','percent'=>100]);
    return ['counts'=>['stops'=>1,'routes'=>1,'trips'=>1,'stop_times'=>1],'validUntil'=>'20991231'];
   }
  }
  final class Payphones {
   public const COVERAGE=[];
   public static function inBounds(array $bounds):array{return ['updatedAt'=>date(DATE_ATOM),'phones'=>[],'source'=>'Synthetic telephone fixture'];}
   public static function refresh():array{return self::inBounds([]);}
  }
 }
 `);
 const port=await new Promise(resolve=>{const s=createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const log=openSync(join(root,'server.log'),'w');
 server=spawn(php,['-d','session.save_path='+join(root,'sessions'),'-d','disable_functions=curl_init','-S',`127.0.0.1:${port}`,'-t',root],{detached:true,stdio:['ignore',log,log],env:{...process.env,PHP_CLI_SERVER_WORKERS:'4',PERTH_MAP_SETUP_TOKEN:key}});
 closeSync(log);
 const base=`http://127.0.0.1:${port}/loader.php`;
 for(let i=0;i<80;i++){
  try {await fetch(base+'?progress=1');break;}catch(error){if(server.exitCode!==null)throw new Error('Fixture PHP server exited');if(i===79)throw error;await new Promise(r=>setTimeout(r,50));}
 }
 browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BINARY||(existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined)});
 const context=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true,timezoneId:'Australia/Perth'});
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 const response=await page.goto(base,{waitUntil:'domcontentloaded'});
 const csp=response.headers()['content-security-policy'];
 check(/script-src[^;]*nonce-/.test(csp)&&csp.includes("connect-src 'self'"),'actual loader uses a nonce script policy and same-origin progress polling');
 await page.locator('#key').fill(key);
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Unlock setup'}).click()]);
 check(await page.getByRole('heading',{name:'Update the complete system'}).isVisible(),'fixture maintenance login reaches the actual installed loader');
 const refresh=page.locator('form:has(input[name="action"][value="import"]) button');
 let posted=false,finished=false,pollsDuringImport=0;
 page.on('request',request=>{if(request.method()==='POST'&&request.postData()?.includes('live'))posted=true;});
 page.on('response',async response=>{
  if(response.request().method()==='POST'&&response.request().postData()?.includes('live'))finished=true;
  if(response.url().includes('?progress=1')&&posted&&!finished&&response.ok())pollsDuringImport++;
 });
 await refresh.click();
 await page.waitForFunction(()=>document.querySelector('#progress-task').textContent.includes('synthetic stop times'));
 check(posted&&!finished,'live importer details appear before the long POST completes');
 check(await page.locator('#maintenance-progress').isVisible(),'progress card appears while maintenance is running');
 check(!(await page.locator('#progress-reload').isVisible()),'the verified-status reload action stays hidden while the operation is running');
 await page.waitForFunction(()=>{const box=document.querySelector('#maintenance-progress').getBoundingClientRect();return box.y>=-2&&box.y<650;},null,{timeout:3000});
 const liveBox=await page.locator('#maintenance-progress').boundingBox();
 check(liveBox&&liveBox.y>=-2&&liveBox.y<650,'starting maintenance brings its progress card into the phone viewport');
 const liveValue=await page.locator('#progress-bar').evaluate(e=>e.value);
 check(liveValue>0&&liveValue<100,'progress remains below 100 until verification finishes');
 check((await page.locator('#progress-count').textContent()).includes('1,250 / 2,500 rows'),'actual callback row counters are visible in the browser');
 check((await page.locator('#progress-elapsed').textContent()).startsWith('Elapsed')&&(await page.locator('#progress-updated').textContent()).startsWith('Last report'),'elapsed time and last report are displayed during the import');
 check(await refresh.isDisabled(),'mutating buttons stay disabled while maintenance is active');
 check(pollsDuringImport>0,'same-session progress polling responds concurrently with the long POST');
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile progress card has no horizontal overflow');
 await page.screenshot({path:'/tmp/loader-progress-mobile.png',fullPage:true});
 await page.waitForFunction(()=>document.querySelector('#progress-task').textContent.includes('synthetic route shapes'));
 check((await page.locator('#progress-count').textContent()).includes('5,000 / 10,000 rows'),'the visible task and counters advance to the next import phase');
 await page.locator('#maintenance-progress.is-complete').waitFor({timeout:12000});
 check(await page.locator('#progress-bar').evaluate(e=>e.value)===100,'100 percent appears only after setup and readiness complete');
 check(await page.locator('#progress-reload').isVisible()&&!await refresh.isDisabled(),'completion enables controls and exposes verified status reload');
 check(await page.locator('#progress-heading').textContent()==='Maintenance complete','successful maintenance has a clear final state');

 writeFileSync(join(root,'var/fixture-import-fail'),'yes');finished=false;
 await refresh.click();
 await page.locator('#maintenance-progress.is-failed').waitFor({timeout:12000});
 check((await page.locator('#progress-message').textContent()).includes('Synthetic import failed midway'),'a failure midway is displayed without hiding the failed task');
 check(await page.locator('#progress-bar').evaluate(e=>e.value)<100,'a failed import never displays complete progress');
 check(!await refresh.isDisabled()&&await page.locator('#progress-reload').isVisible(),'failed setup leaves a clear way to inspect status or retry');

 const progress={runId:'fixture-interrupted-run',action:'update',stage:'index',label:'Fixture competing update',message:'Synthetic database indexing.',percent:88,status:'running',startedAt:new Date().toISOString(),updatedAt:new Date().toISOString()};
 writeFileSync(join(root,'var/maintenance-progress.php'),guard+JSON.stringify(progress));
 holder=spawn(php,['-r',"$h=fopen($argv[1],'c');flock($h,LOCK_EX);echo 'locked';fflush(STDOUT);sleep(15);",join(root,'var/loader.lock')],{stdio:['ignore','pipe','ignore']});
 await new Promise(resolve=>holder.stdout.once('data',resolve));
 await page.reload({waitUntil:'domcontentloaded'});
 await page.waitForFunction(()=>document.querySelector('#progress-task').textContent==='Fixture competing update');
 check(await refresh.isDisabled(),'opening the loader during another update shows its progress and blocks competing actions');
 check((await page.locator('#progress-updated').textContent()).startsWith('Last report'),'an already-running task shows when it last reported');
 holder.kill('SIGTERM');await new Promise(resolve=>holder.once('exit',resolve));holder=null;
 await page.waitForFunction(()=>document.querySelector('#progress-heading').textContent==='Maintenance interrupted',{timeout:6000});
 check((await page.locator('#progress-message').textContent()).includes('stopped before reporting completion'),'a released lock with unfinished status becomes interrupted rather than stuck or successful');
 check(!await refresh.isDisabled(),'interrupted maintenance permits a deliberate retry');
 check(errors.length===0,'actual loader UI has no JavaScript exceptions');
 console.log(`\n${checks} loader browser checks passed. Delayed importer/telephone fixtures only; sessions, locks, polling and UI were actual. No network provider calls.`);
}finally{
 if(holder&&holder.exitCode===null)holder.kill('SIGTERM');
 if(browser)await browser.close();
 if(server&&server.exitCode===null){try{process.kill(-server.pid,'SIGTERM');}catch{}}
 rmSync(root,{recursive:true,force:true});
}
