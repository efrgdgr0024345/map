/** Nearby-stop UI regression: disclosed API/GPS fixtures, actual Leaflet and controls.
 * Start the PHP app, then run node tests/browser-nearby-bus.mjs.
 */
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {existsSync} from 'node:fs';
const require=createRequire(import.meta.url),{chromium}=require('playwright');
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BINARY||(existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined)});
const base=process.env.BASE_URL||'http://127.0.0.1:8080/';
let checks=0;const check=(condition,label)=>{assert.ok(condition,label);checks++;console.log('PASS:',label);};
try {
for(const width of [320,390,1366]) {
 const ctx=await browser.newContext({viewport:{width,height:900},isMobile:width<760,hasTouch:width<760});
 const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(()=>{navigator.geolocation.watchPosition=()=>1;navigator.geolocation.clearWatch=()=>{};});
 let mode='normal',requests=0;
 const a={id:'A',name:'Fixture eastbound stop',lat:-31.95,lon:115.85,distance:0},b={id:'B',name:'Fixture westbound stop',lat:-31.9504,lon:115.85,distance:44};
 const geometry=[[-31.952,115.848],[-31.951,115.848],[-31.95,115.85],[-31.949,115.853]];
 const iso=seconds=>new Date(Date.now()+seconds*1000).toISOString();
 function payload(stop=a, selectedKey='') {
  let bus={tripId:stop.id==='A'?'trip-east':'trip-west',routeId:'R',routeName:'915',headsign:stop.id==='A'?'Fixture east':'Fixture west',mode:'bus',serviceDate:'20261112',departure:iso(300),arrival:iso(280),stopId:stop.id,routeColor:'#009645'};
  const directions=[{...bus,key:'bus',stop},{...bus,key:'train-return',tripId:'train-return',mode:'train',routeName:'Rail',headsign:'Opposite direction',stop:b,stopName:b.name}];
  if(selectedKey==='train-return'){bus=directions[1];stop=b;}
  return {services:mode==='empty'?[]:directions,stop,nearbyStops:[a,b],departure:mode==='empty'?null:bus,geometry:mode==='empty'?[]:geometry,estimated:true,generatedAt:iso(0),message:'No scheduled buses in the next six hours.',vehicle:mode==='empty'?null:{...bus,id:'fixture-'+bus.tripId,position:{lat:-31.951,lon:115.848,bearing:0},phase:'approaching',generatedAt:iso(0),estimated:true}};
 }
 await page.route('**/api.php*',async route=>{
  const url=new URL(route.request().url()),action=url.searchParams.get('action');
  let data={};
  if(action==='nearby-bus') {
   requests++;
   if(mode==='fail'){await route.fulfill({status:503,json:{error:'Fixture failure'}});return;}
   data=payload(url.searchParams.get('stop')==='B'?b:a,url.searchParams.get('service'));
   if(mode==='slow')await new Promise(resolve=>setTimeout(resolve,350));
  } else if(action==='status')data={mode:'timetable',routeRevision:'nearby-fixture'};
  else if(action==='vehicles')data={vehicles:[],stops:[],lines:[],generatedAt:iso(0)};
  else if(action==='routes')data={revision:'nearby-fixture',lines:[]};
  else if(action==='timetable')data={trip:{routeName:'915',headsign:'Fixture destination'},stops:[],generatedAt:iso(0)};
  await route.fulfill({json:data});
 });
 await page.route('**/assets/app.js*',async route=>{
  const response=await route.fetch();const source=await response.text();
  await route.fulfill({response,body:source.replace('  init();','  window.__nearbyTest={state,fetchNearbyBus,updateUserLocation,renderVehicles,clearJourney,updateNearbyBusCountdown};\n  init();')});
 });
 await page.goto(base);await page.waitForFunction(()=>window.__nearbyTest);
 const gps=async(lat=-31.95,lon=115.85,accuracy=5)=>page.evaluate(({lat,lon,accuracy})=>{const t=window.__nearbyTest;t.state.locationConsent=true;t.updateUserLocation({timestamp:Date.now(),coords:{latitude:lat,longitude:lon,accuracy}});},{lat,lon,accuracy});
 check(await page.locator('#nearby-bus').isHidden(),`${width}px preview waits for consented precise GPS`);
 await gps();await page.locator('#nearby-bus').waitFor({state:'visible'});
 check((await page.locator('#nearby-bus').innerText()).includes('Fixture east'),`${width}px GPS automatically shows the nearest stop and next bus destination`);
 check(await page.evaluate(()=>__nearbyTest.state.nearbyBusLayers.getLayers().filter(l=>l.getLatLngs).every(l=>l.getLatLngs().length===4)),`${width}px exact trip shape retains bends and destination`);
 const box=await page.locator('#nearby-bus').boundingBox();check(box.x>=0&&box.x+box.width<=width&&box.y>=0&&box.y+box.height<=900,`${width}px card fits the viewport`);
 await page.getByRole('button',{name:'Show full route',exact:true}).click();
 await page.waitForFunction(()=>Nextstop.getMap().getBounds().contains([-31.949,115.853]));
 check(true,`${width}px full-route control reveals the destination`);
 await page.getByRole('button',{name:'View all stops',exact:true}).click();await page.locator('#timetable-dialog').waitFor({state:'visible'});
 await page.waitForFunction(()=>document.querySelector('#timetable-title').textContent.includes('915'));
 check((await page.locator('#timetable-title').innerText()).includes('915'),`${width}px timetable control opens the incoming service`);
 await page.locator('#timetable-dialog').evaluate(d=>d.close());
 await page.locator('#nearby-bus select').selectOption('B');await page.waitForFunction(()=>window.__nearbyTest.state.nearbyBusData?.stop.id==='B');
 check(await page.evaluate(()=>__nearbyTest.state.nearbyBusData.stop.id==='B'),`${width}px nearby-stop switch selects the other road side`);
 await page.evaluate(()=>{const t=__nearbyTest,d=t.state.nearbyBusData,v=d.vehicle;t.renderVehicles({vehicles:[{...v,...v.position}],stops:[],lines:[]});});
 check(await page.evaluate(()=>__nearbyTest.state.markers.size===0&&Boolean(__nearbyTest.state.nearbyBusMarker)),`${width}px fleet response cannot duplicate the highlighted incoming bus`);
 const from=a,to={lat:-31.949,lon:115.853,name:'Fixture destination'};
 await page.evaluate(({from,to})=>{const now=Date.now(),departure=new Date(now).toISOString(),arrival=new Date(now+600000).toISOString();Nextstop.showJourney({id:'walk-fixture',duration:600,departure,arrival,legs:[{mode:'walk',from,to,departure,arrival,geometry:[[from.lat,from.lon],[to.lat,to.lon]]}]},from,to);},{from,to});
 check(await page.locator('#nearby-bus').isHidden()&&await page.evaluate(()=>!__nearbyTest.state.nearbyBusMarker),`${width}px selected journey removes the automatic preview and marker`);
 await page.evaluate(()=>__nearbyTest.clearJourney(false));await page.locator('#nearby-bus').waitFor({state:'visible'});
 check(true,`${width}px clearing the journey restores nearby bus mode`);
 await gps(-31.95,115.85,500);check(await page.locator('#nearby-bus').isHidden(),`${width}px coarse GPS removes the preview`);
 await gps();await page.evaluate(()=>__nearbyTest.fetchNearbyBus(true));await page.locator('#nearby-bus').waitFor({state:'visible'});
 await gps(-32.1,116.2);check(await page.locator('#nearby-bus').isHidden(),`${width}px walking away clears the route immediately`);
 await gps();await page.evaluate(()=>__nearbyTest.fetchNearbyBus(true));await page.locator('#nearby-bus').waitFor({state:'visible'});
 await page.locator('.nearby-direction').filter({hasText:'train Rail'}).click();
 await page.waitForFunction(()=>__nearbyTest.state.nearbyBusData?.departure.mode==='train');
 check(await page.evaluate(()=>__nearbyTest.state.nearbyBusData.departure.tripId==='train-return'),`${width}px choosing an opposite train direction updates the selected service`);
 mode='empty';await page.evaluate(()=>__nearbyTest.fetchNearbyBus(true));check((await page.locator('#nearby-bus').innerText()).includes('No scheduled buses'),`${width}px no departures gives an honest empty card`);
 mode='fail';await page.evaluate(()=>__nearbyTest.fetchNearbyBus(true));check(await page.locator('#nearby-bus').isHidden(),`${width}px a failed request clears old estimates`);
 mode='slow';await page.evaluate(()=>{__nearbyTest.fetchNearbyBus(true);__nearbyTest.state.selected={legs:[]};});await page.waitForTimeout(450);
 check(await page.locator('#nearby-bus').isHidden(),`${width}px delayed response cannot overwrite a newly selected journey`);
 check(errors.length===0,`${width}px no JavaScript exceptions`);
 await ctx.close();
}
console.log(`\n${checks} nearby-stop browser checks passed. Disclosed API/GPS fixtures.`);
}finally{await browser.close();}
