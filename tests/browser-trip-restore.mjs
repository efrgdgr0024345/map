/** Saved trip lifecycle and all nearby map routes; disclosed API/GPS/tile fixtures. */
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {existsSync} from 'node:fs';
const {chromium}=createRequire(import.meta.url)('playwright');
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BINARY||(existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined)});
let checks=0;const check=(v,s)=>{assert.ok(v,s);checks++;console.log('PASS:',s)};
try{for(const width of [320,390,1366]){
 const context=await browser.newContext({viewport:{width,height:900},reducedMotion:'reduce'}),page=await context.newPage(),errors=[];let plans=0;
 page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(()=>{navigator.geolocation.watchPosition=()=>1;navigator.geolocation.clearWatch=()=>{}});
 const from={lat:-31.95,lon:115.86,name:'Your location'},to={lat:-31.96,lon:115.87,name:'Saved destination'},stop={...from,id:'A',name:'Boarding stand',distance:20};
 const iso=n=>new Date(Date.now()+n*1000).toISOString();
 const services=()=>Array.from({length:8},(_,i)=>({key:'k'+i,tripId:'T'+i,routeId:'R'+i,shapeId:'S'+i,routeName:String(900+i),mode:'bus',routeColor:'#009645',headsign:'Direction '+i,stop,arrival:iso(300+i*60),departure:iso(320+i*60)}));
 const lines=()=>services().map(s=>({...s,geometry:[[from.lat,from.lon],[to.lat+.0001*Number(s.key.slice(1)),to.lon]]}));
 await page.route('https://tile.openstreetmap.org/**',r=>r.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256"/>'}));
 await page.route('**/api.php*',async r=>{
  const action=new URL(r.request().url()).searchParams.get('action');let data={};
  if(action==='status')data={mode:'timetable',expired:false,routeRevision:'restore-fixture'};
  if(action==='routes')data={revision:'restore-fixture',lines:lines()};
  if(action==='vehicles')data={vehicles:[],stops:[],generatedAt:iso(0)};
  if(action==='nearby-bus')data={centre:from,radius:500,services:services(),nearbyStops:[stop],stop,departure:services()[0],geometry:lines()[0].geometry,generatedAt:iso(0)};
  if(action==='plan'){
   plans++;const walk={mode:'walk',routing:'street',from,to:stop,departure:iso(60),arrival:iso(120),duration:60,geometry:[[from.lat,from.lon],[stop.lat,stop.lon]]};
   const ride={...services()[0],from:stop,to,departure:iso(240),arrival:iso(1200),geometry:lines()[0].geometry};
   data={mode:'timetable',journeys:[{id:'saved-choice',walkRouting:'street',leaveAt:iso(60),departure:iso(60),arrival:ride.arrival,duration:1140,legs:[walk,ride]}]};
  }
  await r.fulfill({json:data});
 });
 await page.route('**/assets/app.js*',async r=>{const response=await r.fetch(),s=await response.text();await r.fulfill({response,body:s.replace('  init();','  window.__restoreTest={state,setPlace,setMapOnly,updateUserLocation,clearJourney};\n  init();')});});
 await page.goto('http://127.0.0.1:8080/');await page.waitForFunction(()=>window.__restoreTest&&Nextstop.getMap());
 await page.evaluate(from=>{__restoreTest.state.locationConsent=true;__restoreTest.updateUserLocation({coords:{latitude:from.lat,longitude:from.lon,accuracy:5},timestamp:Date.now()});__restoreTest.setMapOnly(true)},from);
 await page.waitForFunction(()=>document.querySelectorAll('.map-only-service').length===8);
 check(await page.locator('.map-only-service').count()===8,`${width}px every found direction is available, beyond four cards`);
 await page.waitForFunction(()=>__restoreTest.state.nearbyOverviewLayers?.getLayers().length===8);
 check(await page.evaluate(()=>__restoreTest.state.nearbyOverviewLayers.getLayers().every(l=>Nextstop.getMap().hasLayer(l)&&l.getLatLngs().length>=2)),`${width}px all eight exact route shapes are drawn`);
 const box=await page.locator('#map-only-services').boundingBox();check(box.height<=180&&box.x>=0&&box.x+box.width<=width,`${width}px route list leaves space for the map`);
 await page.evaluate(()=>__restoreTest.setMapOnly(false));
 check(await page.evaluate(()=>__restoreTest.state.nearbyOverviewLayers.getLayers().length===0),`${width}px leaving map-only removes the overview layer`);
 await page.evaluate(({from,to})=>{__restoreTest.setPlace('origin',from);__restoreTest.setPlace('destination',to)},{from,to});
 await page.waitForFunction(()=>__restoreTest.state.selected);
 check(await page.evaluate(()=>JSON.parse(localStorage.getItem('nextstop-active-trip-v1')).destination.name)==='Saved destination',`${width}px searched destination is saved on this device`);
 await page.evaluate(()=>{Nextstop.getMap().fire('dragstart');Nextstop.getMap().setView([-31.955,115.865],15,{animate:false});__restoreTest.setMapOnly(true)});
 const previousPlans=plans;await page.reload();await page.waitForFunction(()=>__restoreTest.state.selected);
 check(plans>previousPlans,`${width}px reopening checks fresh departures instead of displaying old times`);
 check(await page.evaluate(()=>__restoreTest.state.destination.name)==='Saved destination',`${width}px reopening restores the destination`);
 await page.waitForFunction(()=>!__restoreTest.state.restoredTripView);
 check(await page.evaluate(()=>Math.abs(Nextstop.getMapCenter().lat+31.955)<.0001&&Nextstop.getMap().getZoom()===15&&__restoreTest.state.mapOnly),`${width}px reopening restores the same map position and hidden-controls mode`);
 await page.evaluate(()=>{__restoreTest.setMapOnly(false);document.getElementById('forget-saved-trip').click()});await page.reload();await page.waitForFunction(()=>window.__restoreTest);
 check(await page.evaluate(()=>!__restoreTest.state.destination&&!localStorage.getItem('nextstop-active-trip-v1')),`${width}px cancelling prevents restoration on the next visit`);
 await page.evaluate(()=>localStorage.setItem('nextstop-active-trip-v1','{"version":1,"origin":{"lat":999},"destination":{}}'));await page.reload();await page.waitForFunction(()=>window.__restoreTest);
 check(await page.evaluate(()=>!localStorage.getItem('nextstop-active-trip-v1')),`${width}px malformed saved coordinates are discarded safely`);
 check(errors.length===0,`${width}px no JavaScript errors`);await context.close();
}console.log(`${checks} saved-trip and all-route checks passed. Disclosed fixtures only.`)}finally{await browser.close()}
