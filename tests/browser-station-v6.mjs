/**
 * Station-map and leave-home clock verification against a running local app.
 * The first scenarios expose existing UI functions through a test-only script hook,
 * use disclosed UI geometry/journey fixtures, and control browser Date. They verify
 * rendering and state transitions; they do not prove pedestrian routing.
 * The default final scenario loads real station-map + transfer APIs (no API mocks).
 * SKIP_LIVE=1 leaves that integration explicitly UNRUN.
 * Run: node tests/browser-station-v6.mjs (uses the environment's NODE_PATH).
 */
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { createRequire } from 'node:module';
const require=createRequire(import.meta.url);
const {chromium}=require('playwright');
const base=process.env.BASE_URL||'http://127.0.0.1:8080/';
const executablePath=process.env.CHROMIUM_BINARY||(existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined);
const activeBrowsers=new Set();

async function verifyClock() {
 let checks=0;const errors=[];const ok=(v,label)=>{assert.ok(v,label);checks++;console.log('PASS:',label)};
 const browser=await chromium.launch({headless:true,executablePath});activeBrowsers.add(browser);
 const context=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true,timezoneId:'Australia/Perth'});
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/assets/app.js*',async route=>{
  const response=await route.fetch();let script=await response.text();
  assert.ok(script.includes('  init();'),'test hook insertion point exists');
  script=script.replace('  init();','  window.__leaveTest = { state, updateLeaveWidget, updateBoardingCountdown, setPanel };\n  init();');
  await route.fulfill({response,body:script});
 });
 await page.goto(base,{waitUntil:'domcontentloaded'});await page.waitForFunction(()=>window.__leaveTest);
 const now=Date.now(),board=now+15*60e3,leave=board-10*60e3;
 await page.clock.setFixedTime(now);
 const journey={id:'ui-fixture-only',boardingAt:new Date(board).toISOString(),leaveAt:new Date(leave).toISOString(),walkRouting:'street',walkToBoardSeconds:480,boardingBufferSeconds:120,legs:[{mode:'bus',tripId:'ui-fixture-only',routeName:'999',routeId:'999',departure:new Date(board).toISOString(),arrival:new Date(board+20*60e3).toISOString(),from:{id:'A',lat:-31.95,lon:115.86,name:'Boarding stop'},to:{id:'B',lat:-31.96,lon:115.86,name:'Destination'}}]};
 await page.evaluate(j=>{__leaveTest.state.selected=j;__leaveTest.updateLeaveWidget();__leaveTest.updateBoardingCountdown()},journey);
 const clock=page.locator('#leave-widget');
 ok(await clock.isVisible(),'verified initial boarding deadline displays');
 ok(await page.locator('#leave-label').textContent()==='LEAVE HOME','initial walk is labelled LEAVE HOME');
 ok((await clock.getAttribute('aria-label')).includes('8 minute walk plus 2 minutes'),'accessible clock explains verified walking and boarding allowance');
 for(const [delta,urgent,due]of [[181,false,false],[180,true,false],[1,true,false],[0,false,true],[-1,false,true]]){
  await page.clock.setFixedTime(leave-delta*1000);await page.evaluate(()=>__leaveTest.updateLeaveWidget());
  const c=await clock.getAttribute('class');ok(c.includes('is-urgent')===urgent&&c.includes('is-due')===due,`deadline ${delta}s: urgency=${urgent}, due=${due}`);
 }
 await page.clock.setFixedTime(leave-1000);await page.evaluate(()=>__leaveTest.updateLeaveWidget());
 ok(await clock.evaluate(e=>getComputedStyle(e).animationName)==='leave-clock-pulse','final-three-minute clock has visible CSS pulse');
 await page.emulateMedia({reducedMotion:'reduce'});
 ok(await clock.evaluate(e=>getComputedStyle(e).animationName)==='none'&&(await clock.evaluate(e=>parseFloat(getComputedStyle(e).outlineWidth)))>=4,'reduced motion replaces flash with static emphasis');
 await page.emulateMedia({reducedMotion:'no-preference'});
 await page.clock.setFixedTime(leave);await page.evaluate(()=>__leaveTest.updateLeaveWidget());
 ok(await page.locator('#leave-title').textContent()==='Leave now'&&await clock.evaluate(e=>getComputedStyle(e).animationName)==='none','due deadline clearly says Leave now without continuing flash');
 const overlaps=(a,b)=>a.x<b.x+b.width&&a.x+a.width>b.x&&a.y<b.y+b.height&&a.y+a.height>b.y;
 for(const [width,height]of [[390,844],[320,740],[1366,900]]){
  await page.setViewportSize({width,height});
  await page.clock.setFixedTime(leave-120000);await page.evaluate(()=>{__leaveTest.setPanel(false);__leaveTest.updateLeaveWidget();document.querySelector('#follow-journey').hidden=false});
  await page.waitForTimeout(250);
  const b=await clock.boundingBox(),brand=await page.locator('.brand').boundingBox(),zoom=await page.locator('.map-controls').boundingBox(),follow=await page.locator('#follow-journey').boundingBox();
  ok(b&&Math.abs(b.width-b.height)<1&&b.x>width*.5&&b.y<40,`${width}px circle is round and anchored top right`);
  ok(!overlaps(b,brand)&&!overlaps(b,zoom)&&!overlaps(b,follow),`${width}px clock does not cover brand, zoom or follow controls`);
  ok(await page.locator('.topbar-right').evaluate(e=>getComputedStyle(e).visibility)==='hidden',`${width}px existing city pill does not collide with clock`);
  if(width===390){
   await page.evaluate(()=>{document.body.classList.add('station-guidance-active');document.querySelector('#station-guidance').hidden=false});
   const stationBox=await page.locator('#station-guidance').boundingBox();
   ok(await clock.isVisible()&&stationBox.y>=b.y+b.height,'manual future-transfer preview retains the valid leave clock above the station card');
   ok(await page.locator('.topbar-right').evaluate(element=>getComputedStyle(element).visibility)==='hidden','manual future-transfer preview keeps the city pill clear of the clock');
   await page.evaluate(()=>{document.body.classList.remove('station-guidance-active');document.querySelector('#station-guidance').hidden=true});
  }
  await page.evaluate(()=>__leaveTest.setPanel(true));
  await page.waitForTimeout(100);
  const panelZoom=await page.locator('.map-controls').boundingBox();
  ok(!panelZoom||!overlaps(b,panelZoom),`${width}px open sheet leaves clock clear of zoom controls`);
  await page.evaluate(()=>__leaveTest.setPanel(false));
  if(width===390)await page.screenshot({path:'/tmp/leave-circle-mobile.png',fullPage:true});
  if(width===1366){await page.evaluate(()=>{document.querySelector('#map-warning').hidden=false});const warning=await page.locator('#map-warning').boundingBox();ok(warning&&warning.y>=b.y+b.height,'desktop tile warning stays below the circular deadline');await page.screenshot({path:'/tmp/leave-circle-desktop.png',fullPage:true});}
 }
 await page.clock.setFixedTime(board);await page.evaluate(()=>__leaveTest.updateLeaveWidget());
 ok(!(await clock.isVisible())&&!(await page.locator('body').getAttribute('class')).includes('leave-clock-visible'),'clock hides and frees header once initial boarding time passes');
 await page.clock.setFixedTime(now);
 for(const [kind,modify]of [['walking-only',j=>{j.legs=[{mode:'walk'}]}],['unverified',j=>{j.walkRouting='unavailable'}],['missing-deadline',j=>{j.leaveAt=null}]]){
  const copy=structuredClone(journey);modify(copy);await page.evaluate(j=>{__leaveTest.state.selected=j;__leaveTest.updateLeaveWidget()},copy);
  ok(!(await clock.isVisible()),`${kind} does not show or flash an invented leave deadline`);
 }
 ok(errors.length===0,'no JavaScript exceptions during focused UI verification');
 console.log(`${checks} clock/layout checks passed. UI fixture and controlled browser Date only; route planning was not tested.`);
 await browser.close();
 return checks;
}

async function verifyRenderer() {
 let count=0;const errors=[];
 const check=(value,label)=>{assert.ok(value,label);count++;console.log('PASS:',label)};
 const browser=await chromium.launch({headless:true,executablePath});activeBrowsers.add(browser);
 const page=await browser.newPage({viewport:{width:390,height:844},isMobile:true,hasTouch:true,timezoneId:'Australia/Perth'});
 page.on('pageerror',error=>errors.push(error.message));
 await page.route('**/assets/app.js*',async route=>{const response=await route.fetch();let body=await response.text();body=body.replace('  init();','  window.__stationTest={state,renderStationGuidance,renderStationMap,stationFeatureLabel,fitStationGuidance,exitStationGuidance,getMap:()=>map};\n  init();');await route.fulfill({response,body});});
 await page.goto(base,{waitUntil:'domcontentloaded'});await page.waitForFunction(()=>window.__stationTest);
 const from={id:'fixture-A',name:'Bus stand A',lat:-32.0118,lon:115.8569};
 const to={id:'fixture-B',name:'Canning Bridge Stn Platform 2',lat:-32.0126,lon:115.8573};
 const guide={from,to,station:{name:'UI fixture station'},stops:[{...from,origin:true},{...to,target:true}],routing:'street',transferMinimumSeconds:300,transferTimeSource:'GTFS transfer rules',geometry:[[from.lat,from.lon],[-32.0121,115.857],[-32.0123,115.8573],[to.lat,to.lon]],distance:110,duration:90};
 const feature=(kind,type,coordinates,extra={})=>({type:'Feature',geometry:{type,coordinates},properties:{kind,...extra}});
 const details={available:true,stale:false,source:'OpenStreetMap',sourceUrl:'https://www.openstreetmap.org/copyright',attribution:'© OpenStreetMap contributors',updatedAt:'2026-10-20T00:00:00Z',stations:[{name:'Canning Bridge',officialPlanUrl:'https://www.transperth.wa.gov.au/timetablePDFs/BusStationMaps/Canning%20Bridge%20Station.pdf'},{name:'Invalid',officialPlanUrl:'https://example.com/not-official.pdf'}],featureCollection:{type:'FeatureCollection',features:[
  feature('building','Polygon',[[[115.8567,-32.0117],[115.8575,-32.0117],[115.8575,-32.0128],[115.8567,-32.0128],[115.8567,-32.0117]]],{name:'Station building'}),
  feature('platform','Polygon',[[[115.85722,-32.0122],[115.85735,-32.0122],[115.85735,-32.01275],[115.85722,-32.01275],[115.85722,-32.0122]]],{ref:'99632',localRef:'2',level:'0',gtfsStopId:'fixture-B'}),
  feature('platform','Polygon',[[[115.8574,-32.0122],[115.8575,-32.0122],[115.8575,-32.01275],[115.8574,-32.01275],[115.8574,-32.0122]]],{ref:'1',level:'0'}),
  feature('footway','LineString',[[115.8569,-32.0118],[115.857,-32.0121],[115.8573,-32.0123],[115.8573,-32.0126]],{name:'Mapped walkway'}),
  feature('rail','LineString',[[115.85737,-32.0121],[115.85737,-32.0128]]),
  feature('steps','LineString',[[115.857,-32.0121],[115.85712,-32.0122]],{level:'0;1'}),
  feature('entrance','Point',[115.8569,-32.01185],{name:'Station entrance',level:'1'}),
  feature('lift','Point',[115.85725,-32.0123],{level:'0;1',wheelchair:'yes',foot:'private'}),
  feature('crossing','Point',[115.85697,-32.012]),
  feature('unknown-untrusted-kind','Point',[0,0])
 ]}};
 await page.evaluate(({guide,details})=>{const t=__stationTest;t.state.guidance={transfer:{from:guide.from,to:guide.to,previous:{arrival:new Date(Date.now()+3600000).toISOString()},next:{tripId:'fixture-trip',departure:new Date(Date.now()+4200000).toISOString()}},following:true,confirmed:false,data:null};document.body.classList.add('station-guidance-active');document.querySelector('#station-guidance').hidden=false;t.renderStationGuidance(guide);t.renderStationMap(details);},{guide,details});
 await page.waitForFunction(()=>document.querySelectorAll('.station-feature-platform').length===2);
 check((await page.locator('#guidance-detail').textContent()).includes('2 min walk')&&(await page.locator('#guidance-detail').textContent()).includes('allow 5 min to change'),'walking time stays distinct from official minimum change allowance');
 check(await page.locator('.station-feature-building').count()===1&&await page.locator('.station-feature-platform').count()===2,'station buildings and both platform polygons render from GeoJSON');
 check(await page.locator('.station-feature-footway').count()===1&&await page.locator('.station-feature-rail').count()===1&&await page.locator('.station-feature-steps').count()===1,'quiet walkway, rail and stair geometry renders independently from the route');
 check(await page.locator('.station-place-marker-entrance,.station-place-marker-lift,.station-place-marker-crossing').count()===3,'entrance, lift and crossing symbols use distinct mapped points');
 check((await page.locator('.station-place-marker-lift').getAttribute('title')).includes('restricted access')&&await page.locator('.station-place-marker-lift').evaluate(element=>Number(getComputedStyle(element).opacity)<.5),'restricted lift remains visibly dimmed and labelled as restricted access');
 check(await page.evaluate(()=>__stationTest.stationFeatureLabel({kind:'building'}))==='Building','unnamed nearby footprints are not mislabelled as station buildings');
 check(await page.locator('.guidance-stop-origin').count()===1&&await page.locator('.guidance-origin-pin').textContent()==='A','alighting point is clearly marked A');
 check(await page.locator('.guidance-stop-target').count()===1&&await page.locator('.guidance-target-ring').textContent()==='B'&&(await page.locator('#guidance-target-name').textContent()).includes('Platform 2'),'boarding point stays exact official B target with platform name');
 check(await page.locator('.station-feature-platform.is-boarding-platform').count()===1,'only an exact official GTFS stop-ID match highlights a platform polygon');
 check((await page.locator('.station-platform-label').allTextContents()).includes('2')&&!(await page.locator('.station-platform-label').allTextContents()).includes('99632'),'platform labels prefer mapped local platform numbers over technical stop IDs');
 const position=await page.evaluate(()=>__stationTest.state.stationMapLayers.getLayers()[0].getBounds().getCenter());
 check(position.lat<0&&position.lng>100,'GeoJSON longitude/latitude projects to the intended Perth area');
 check(await page.evaluate(()=>Number(__stationTest.getMap().getPane('stationDetailPane').style.zIndex)<Number(__stationTest.getMap().getPane('stationLabelPane').style.zIndex)&&Number(__stationTest.getMap().getPane('stationLabelPane').style.zIndex)<Number(__stationTest.getMap().getPane('guidancePane').style.zIndex)),'walking trail remains above quiet station geometry');
 check(await page.locator('.guidance-trail-dot').count()>5,'actual guide geometry keeps its ordered animated walking dots');
 check(!(await page.locator('#station-map-details').getAttribute('open'))&&!(await page.locator('#station-map-source').isVisible()),'source and date stay collapsed away from map space');
 await page.locator('#station-map-details summary').click();
 check((await page.locator('#station-map-source').textContent()).includes('OpenStreetMap')&&(await page.locator('#station-map-source').textContent()).includes('20 Oct 2026'),'expanded map details show attribution and stored update date');
 check(await page.locator('#station-map-source a:not(.station-official-plan)').getAttribute('href')==='https://www.openstreetmap.org/copyright','source attribution links to the verified OSM copyright page');
 check(await page.locator('.station-official-plan').count()===1&&(await page.locator('.station-official-plan').getAttribute('href')).startsWith('https://www.transperth.wa.gov.au/'),'official station PDF remains an optional verified-source link inside expanded details');
 await page.locator('#station-map-details summary').click();
 await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
 const before=await page.evaluate(()=>({center:__stationTest.getMap().getCenter(),zoom:__stationTest.getMap().getZoom()}));
 await page.evaluate(details=>__stationTest.renderStationMap(details),details);
 const after=await page.evaluate(()=>({center:__stationTest.getMap().getCenter(),zoom:__stationTest.getMap().getZoom()}));
 check(JSON.stringify(before)===JSON.stringify(after),'late station context does not move the user camera');
 await page.waitForFunction(()=>Array.from(document.querySelectorAll('.guidance-trail-dot')).every(node=>Number(getComputedStyle(node).opacity)>0.9));
 await page.screenshot({path:'/tmp/station-detail-ui.png',fullPage:true});
 await page.evaluate(()=>__stationTest.renderStationMap({available:false}));
 check(await page.locator('.station-feature').count()===0&&await page.locator('.guidance-trail-dot').count()>5&&await page.locator('.guidance-stop-target').count()===1,'unavailable optional detail clears context while preserving verified path and boarding target');
 check(await page.locator('#station-map-state').textContent()==='Unavailable','optional unavailable state is explained in collapsed map details');
 await page.evaluate(details=>__stationTest.renderStationMap({...details,sourceUrl:'javascript:alert(1)'}),details);
 await page.locator('#station-map-details summary').click();
 check(await page.locator('#station-map-source a:not(.station-official-plan)').count()===0&&(await page.locator('#station-map-source').textContent()).includes('OpenStreetMap'),'unsafe source links remain plain attribution text');
 await page.evaluate(()=>__stationTest.exitStationGuidance(false));
 check(await page.locator('.station-feature,.station-place-marker,.guidance-stop-origin,.guidance-stop-target,.guidance-trail-dot').count()===0,'leaving guidance removes both station context and route markers');
 check(errors.length===0,'focused station UI verification has no JavaScript exceptions');
 console.log(count+' station-renderer checks passed. UI-only geometry fixture; no actual OSM/provider routing claim.');
 await browser.close();
 return count;
}

async function verifyRealStation() {
 let count=0;const errors=[];
 const check=(value,label)=>{assert.ok(value,label);count++;console.log('PASS:',label)};
 const query=async(action,parameters={})=>{
  const url=new URL('api.php',base);url.searchParams.set('action',action);
  Object.entries(parameters).forEach(([key,value])=>url.searchParams.set(key,value));
  const response=await fetch(url,{signal:AbortSignal.timeout(20000)});
  const data=await response.json();assert.ok(response.ok&&!data.error,`${action}: ${data.error||response.status}`);return data;
 };
 const details=await query('station-map',{from:'23846',to:'99632'});
 check(details.available&&details.featureCollection?.type==='FeatureCollection'&&details.featureCollection.features.length>10,'real Canning Bridge station catalog supplies detailed geometry');
 check(/OpenStreetMap/i.test(details.attribution)&&Number.isFinite(Date.parse(details.updatedAt)),'real station catalog includes attribution and actual source date');
 check(details.stations.some(station=>station.name.includes('Canning Bridge')&&station.officialPlanUrl?.startsWith('https://www.transperth.wa.gov.au/')),'real station metadata retains the official Canning Bridge PDF reference');
 check(details.featureCollection.features.some(feature=>feature.properties.kind==='platform')&&details.featureCollection.features.some(feature=>feature.properties.kind==='footway'),'real catalog includes platforms and pedestrian ways');
 const guide=await query('transfer',{from:'23846',to:'99632'});
 check(guide.routing==='street'&&guide.pathAvailable&&guide.geometry.length>1,'real public-stop transfer retains verified pedestrian geometry');
 check(guide.from.id==='23846'&&guide.to.id==='99632'&&guide.to.name.includes('Platform 2'),'real walking connection keeps the correct bus sign and next train platform');
 check(Number.isFinite(guide.transferMinimumSeconds)&&guide.transferMinimumSeconds>guide.duration+120,'official minimum connection time remains separate from the shorter mapped walk');
 const departures=await query('timetable',{stop:'99632'});
 const nextDeparture=departures.departures.find(visit=>visit.pickupType!==1);
 assert.ok(nextDeparture,'A real train departure is available for station guidance');
 const trip=await query('timetable',{trip:nextDeparture.tripId,date:nextDeparture.serviceDate});
 const lastStop=trip.stops.at(-1);
 const next={...nextDeparture,from:guide.to,to:lastStop,arrival:lastStop.arrival};
 const browser=await chromium.launch({headless:true,executablePath});activeBrowsers.add(browser);
 const page=await browser.newPage({viewport:{width:390,height:844},isMobile:true,hasTouch:true,timezoneId:'Australia/Perth'});
 page.on('pageerror',error=>errors.push(error.message));
 await page.route('**/assets/app.js*',async route=>{
  const response=await route.fetch();let body=await response.text();
  body=body.replace('  init();','  window.__realStationTest={state,openStationGuidance,getMap:()=>map,getNetwork:()=>networkLayers};\n  init();');
  await route.fulfill({response,body});
 });
 await page.goto(base,{waitUntil:'domcontentloaded'});await page.waitForFunction(()=>window.__realStationTest);
 await page.evaluate(({guide,next})=>{
  const t=__realStationTest;
  // Supply only a display-controller shell from real stop/trip records, then use
  // the actual opener and its live transfer/station-map requests. No home journey
  // or physically completed bus arrival is claimed by this rendering check.
  t.state.selected={id:'station-display-controller',legs:[next],walkRouting:'street'};
  t.openStationGuidance({key:'real-canning-test',from:guide.from,to:guide.to,previous:{mode:'bus',arrival:guide.generatedAt,to:guide.from},next});
 },{guide,next});
 await page.waitForFunction(()=>document.querySelectorAll('.station-feature-platform').length>0&&document.querySelectorAll('.guidance-trail-dot').length>0);
 check(await page.evaluate(()=>!__realStationTest.getMap().hasLayer(__realStationTest.getNetwork())),'real station opener removes unrelated overview routes');
 check((await page.locator('#guidance-detail').textContent()).includes(`${Math.ceil(guide.duration/60)} min walk`)&&(await page.locator('#guidance-detail').textContent()).includes(`allow ${Math.ceil(guide.transferMinimumSeconds/60)} min to change`),'real mini map explains walking minutes and official change allowance distinctly');
 check(await page.locator('.guidance-stop-origin').count()===1&&await page.locator('.guidance-stop-target').count()===1,'real station map shows one alighting A and exact boarding B');
 await page.waitForFunction(()=>Array.from(document.querySelectorAll('.guidance-trail-dot')).every(node=>Number(getComputedStyle(node).opacity)>0.9));
 await page.screenshot({path:'/tmp/nextstop-station-v6-real.png',fullPage:true});
 await page.locator('#station-map-details summary').click();
 check(await page.locator('#station-map-source a.station-official-plan').count()>0&&(await page.locator('#station-map-source').textContent()).includes('OpenStreetMap'),'real map source/date and official diagram are available without covering the map');
 check(errors.length===0,'actual station data renders without JavaScript exceptions');
 await browser.close();
 console.log(`${count} real station API/rendering checks passed. Browser tile imagery is not asserted; no TLS bypass is used.`);
 return count;
}

let total=0;
try {
 total+=await verifyClock();
 total+=await verifyRenderer();
 if(process.env.SKIP_LIVE==='1')console.log('UNRUN: real station-map / pedestrian API integration (SKIP_LIVE=1).');
 else total+=await verifyRealStation();
 console.log(`${total} station/leave-clock checks passed${process.env.SKIP_LIVE==='1'?' (live integration UNRUN)':''}.`);
} catch(error) {
 console.error(error);process.exitCode=1;
} finally {
 await Promise.allSettled([...activeBrowsers].map(browser=>browser.close()));
}
