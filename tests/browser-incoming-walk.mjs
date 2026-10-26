/**
 * Deterministic browser regression for independent incoming markers and the
 * leave-clock walking view. Journey/approach/fleet/GPS responses are disclosed
 * fixtures; the actual Leaflet app and UI interactions run in Chromium.
 * Run against the local PHP app: node tests/browser-incoming-walk.mjs
 */
import assert from 'node:assert/strict';
import {existsSync} from 'node:fs';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url),{chromium}=require('playwright');
const base=process.env.BASE_URL||'http://127.0.0.1:8080/';
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BINARY||(existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined)});
let checks=0;const check=(v,label)=>{assert.ok(v,label);checks++;console.log('PASS:',label)};
try {
 for(const width of [320,390,1366]) {
  const context=await browser.newContext({viewport:{width,height:900},isMobile:width<760,hasTouch:width<760,timezoneId:'Australia/Perth'});
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  let now=Date.now(),fleetMode='empty',approachMode='ok',approachData;
  await page.clock.setFixedTime(now);
  const iso=offset=>new Date(now+offset*1000).toISOString();
  const origin={lat:-31.953,lon:115.853,name:'Fixture home'},stop={id:'A',lat:-31.9505,lon:115.8586,name:'Fixture stand 12'},destination={lat:-32.0563,lon:115.7499,name:'Fixture destination'};
  const ride={mode:'bus',tripId:'incoming-fixture',serviceDate:'20261026',routeId:'R',routeName:'915',headsign:'Fixture destination',routeColor:'#009645',from:stop,to:{id:'Z',...destination},departure:iso(1200),arrival:iso(3600),geometry:[[stop.lat,stop.lon],[destination.lat,destination.lon]]};
  const walk={mode:'walk',routing:'street',from:origin,to:stop,departure:iso(0),arrival:iso(420),geometry:[[origin.lat,origin.lon],[-31.952,115.853],[-31.952,115.8586],[stop.lat,stop.lon]]};
  const journey={id:'fixture-journey',walkRouting:'street',leaveAt:iso(660),boardingAt:ride.departure,walkToBoardSeconds:420,boardingBufferSeconds:120,departure:iso(0),arrival:ride.arrival,duration:3600,legs:[walk,ride]};
  const payload=(mode='approaching')=>({...ride,id:`${ride.serviceDate}-${ride.tripId}`,tripId:ride.tripId,stop,phase:mode,upcoming:mode==='not-started',estimated:true,generatedAt:new Date(now).toISOString(),arrival:iso(1150),departure:ride.departure,secondsToStop:1150,position:{lat:-31.948,lon:115.84,bearing:90},geometry:mode==='not-started'?[]:[[-31.948,115.84],[-31.948,115.8586],[stop.lat,stop.lon]],motion:{duration:15,frames:[{offset:0,lat:-31.948,lon:115.84,bearing:90},{offset:15,lat:-31.948,lon:115.841,bearing:90}]}});
  approachData=payload();
  await page.route('**/api.php*',async route=>{
   const url=new URL(route.request().url()),action=url.searchParams.get('action');let data;
   if(action==='status')data={mode:'timetable',routeRevision:'incoming-fixture',message:'Fixture timetable'};
   else if(action==='routes')data={revision:'incoming-fixture',lines:[]};
   else if(action==='vehicles') {
    if(fleetMode==='fail'){await route.fulfill({status:503,json:{error:'Fixture network unavailable'}});return;}
    data={vehicles:fleetMode==='selected'?[{...ride,...approachData.position,id:approachData.id,generatedAt:iso(0)}]:[],stops:[],lines:[],generatedAt:iso(0)};
   } else if(action==='approach'){
    if(approachMode==='fail'){await route.fulfill({status:503,json:{error:'Fixture approach unavailable'}});return;}
    data=approachData;
   } else data={};
   await route.fulfill({json:data});
  });
  await page.route('**/assets/app.js*',async route=>{
   const response=await route.fetch();let script=await response.text();
   assert.ok(script.includes('  init();'));
   script=script.replace('  init();','  window.__incomingTest={state,selectJourney,fetchApproach,fetchVehicles,renderVehicles,updateUserLocation,updateLeaveWidget,setPanel,setFollow,clearJourney,getMap:()=>map};\n  init();');
   await route.fulfill({response,body:script});
  });
  await page.goto(base,{waitUntil:'domcontentloaded'});await page.waitForFunction(()=>window.__incomingTest?.getMap());
  await page.evaluate(({origin,destination,journey})=>{const t=__incomingTest;t.state.origin=origin;t.state.destination=destination;t.selectJourney(journey)}, {origin,destination,journey});
  await page.locator('.vehicle-body.is-next').waitFor();
  check(await page.locator('.vehicle-body.is-next').count()===1,`${width}px selected incoming bus appears when the fleet is empty`);
  check(await page.locator('.incoming-route-pulse').count()===1,`${width}px approaching route remains visible in the line colour`);
  check(await page.locator('.vehicle-body.is-next').evaluate(el=>el.closest('.vehicle-marker').getAttribute('aria-label').includes('estimated position')),`${width}px selected marker remains labelled as an estimate`);
  fleetMode='selected';await page.evaluate(()=>__incomingTest.fetchVehicles());
  check(await page.locator('.vehicle-body.is-next').count()===1,`${width}px a later fleet response cannot duplicate the selected marker`);
  fleetMode='fail';await page.evaluate(()=>__incomingTest.fetchVehicles());
  check(await page.locator('.vehicle-body.is-next').count()===1,`${width}px selected service survives a failed network-fleet request`);
  await page.evaluate(()=>__incomingTest.setPanel(true));
  const clock=page.locator('#leave-widget');
  check(await clock.evaluate(el=>el.tagName==='BUTTON'&&getComputedStyle(el).pointerEvents==='auto'),`${width}px leave circle is an interactive native button`);
  await clock.click();
  await page.waitForFunction(()=>__incomingTest.state.firstWalkFocus&&!__incomingTest.state.zooming);
  check(await page.locator('#journey-panel').evaluate(el=>getComputedStyle(el).display==='none'),`${width}px circle hides the journey panel to expose the map`);
  const view=await page.evaluate(({origin,stop,destination})=>{const m=__incomingTest.getMap(),b=m.getBounds();return{zoom:m.getZoom(),origin:b.contains([origin.lat,origin.lon]),stop:b.contains([stop.lat,stop.lon]),destination:b.contains([destination.lat,destination.lon])}},{origin,stop,destination});
  check(view.zoom>=15&&view.origin&&view.stop&&!view.destination,`${width}px walking view fits home and first stop without the distant destination`);
  check(await page.locator('.first-walk-stop-label').textContent()==='Board here · Fixture stand 12',`${width}px first boarding stand is clearly labelled`);
  check(await page.evaluate(()=>__incomingTest.state.firstWalkLayers.getLayers().filter(l=>l.getLatLngs).some(l=>l.getLatLngs().length===4)),`${width}px walking view retains the mapped bends rather than drawing a shortcut`);
  fleetMode='empty';await page.evaluate(()=>__incomingTest.fetchVehicles());
  check(await page.evaluate(()=>__incomingTest.state.firstWalkFocus&&__incomingTest.getMap().getZoom()>=15),`${width}px network updates cannot zoom back out to the full journey`);
  await page.evaluate(()=>__incomingTest.updateUserLocation({timestamp:Date.now(),coords:{latitude:-31.9525,longitude:115.853,accuracy:5}}));
  check(await page.evaluate(()=>__incomingTest.state.gpsMarker.getLatLng().lat===-31.9525&&__incomingTest.state.firstWalkFocus),`${width}px phone location continues updating in the walking view`);
  await page.screenshot({path:`/tmp/nextstop-walk-to-stop-${width}.png`});
  await page.locator('#map-zoom-in').click();
  check(await page.evaluate(()=>__incomingTest.state.firstWalkFocus),`${width}px manually zooming preserves the walking path`);
  await page.locator('#follow-journey').click();
  await page.waitForFunction(()=>!__incomingTest.state.firstWalkFocus&&!__incomingTest.state.zooming&&__incomingTest.getMap().getBounds().contains([-32.0563,115.7499]));
  check(await page.evaluate(()=>__incomingTest.getMap().getBounds().contains([-32.0563,115.7499])),`${width}px Show full journey restores the destination view`);
  // A train beginning at the boarding station has no incoming line yet, but
  // must still have its own selected marker even with no fleet response.
  const trainRide={...ride,mode:'train',tripId:'train-fixture',routeName:'Fremantle Line',routeColor:'#002F6C'};
  approachData={...payload('not-started'),...trainRide,id:'20261026-train-fixture',position:{lat:stop.lat,lon:stop.lon,bearing:0},motion:{duration:15,frames:[{offset:0,lat:stop.lat,lon:stop.lon,bearing:0},{offset:15,lat:stop.lat,lon:stop.lon,bearing:0}]}};
  await page.evaluate(j=>__incomingTest.selectJourney(j),{...journey,id:'train-journey',legs:[walk,trainRide]});
  await page.waitForFunction(()=>document.querySelector('.vehicle-body.train.is-next'));
  check(await page.locator('.vehicle-body.is-next').count()===1&&await page.locator('.incoming-route-pulse').count()===0,`${width}px upcoming train at its first station shows one marker without an invented incoming line`);
  await clock.click();
  await page.waitForFunction(()=>__incomingTest.state.firstWalkFocus&&!__incomingTest.state.zooming&&__incomingTest.getMap().getZoom()>=15);
  await page.locator('.vehicle-body.train.is-next').click();
  check((await page.locator('.leaflet-popup-content').textContent()).includes('Scheduled trip not started'),`${width}px first-stop popup explains the train has not started`);
  await page.evaluate(()=>__incomingTest.getMap().closePopup());
  // Browser Date is advanced without changing timer rates: stale estimates
  // must disappear when both data sources fail.
  approachMode='fail';fleetMode='fail';now+=46000;await page.clock.setFixedTime(now);
  await page.waitForFunction(()=>!document.querySelector('.vehicle-body.is-next'),null,{timeout:5000});
  check(await page.locator('.vehicle-body.is-next').count()===0,`${width}px stale incoming estimates are removed after provider failures`);
  check(errors.length===0,`${width}px has no JavaScript exceptions`);
  await page.screenshot({path:`/tmp/nextstop-incoming-${width}.png`});
  await context.close();
 }
 console.log(`\n${checks} incoming-marker and leave-circle checks passed. Disclosed API/GPS/clock fixtures; basemap imagery not verified.`);
} finally {await browser.close();}
