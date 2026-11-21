/** Map-first and confirmed onboard UX; disclosed GPS/API/footpath fixtures, real Leaflet. */
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {existsSync} from 'node:fs';
const require=createRequire(import.meta.url),{chromium}=require('playwright');
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BINARY||(existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined)});
const base=process.env.BASE_URL||'http://127.0.0.1:8080/';
let checks=0;const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS:',label)};
try{for(const width of [320,390,430,1366]){
 const ctx=await browser.newContext({viewport:{width,height:900},reducedMotion:'reduce'}),page=await ctx.newPage(),errors=[],queries=[];
 page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(()=>{navigator.geolocation.watchPosition=()=>1;navigator.geolocation.clearWatch=()=>{};});
 await page.route('https://tile.openstreetmap.org/**',r=>r.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256"><rect width="256" height="256" fill="#e7eddf"/></svg>'}));
 const iso=s=>new Date(Date.now()+s*1000).toISOString();
 const gps={lat:-31.95,lon:115.85},a={id:'A',name:'Eastbound stand',code:'10001',...gps,distance:0},b={id:'B',name:'Westbound stand',code:'10002',lat:-31.9504,lon:115.85,distance:44},alight={id:'AL',name:'Next alighting stand',lat:-31.9496,lon:115.85,sequence:20,dropoffType:0};
 const ride={key:'east',tripId:'ride',serviceDate:'20261121',routeId:'R',routeName:'960',headsign:'East destination',mode:'bus',stop:a,stopId:'A',stopName:a.name,sequence:10,arrival:iso(300),departure:iso(330),routeColor:'#009645'};
 const west={...ride,key:'west',tripId:'west',headsign:'West destination',stop:b,stopId:'B',stopName:b.name};
 await page.route('**/api.php*',async r=>{
  const u=new URL(r.request().url()),action=u.searchParams.get('action');let data={};
  if(action==='status')data={mode:'timetable',routeRevision:'map-first-fixture'};
  else if(action==='routes')data={revision:'map-first-fixture',lines:[]};
  else if(action==='vehicles')data={vehicles:[],stops:[],lines:[],generatedAt:iso(0)};
  else if(action==='nearby-bus'){
   queries.push(Object.fromEntries(u.searchParams));const onboard=u.searchParams.has('trip'),verified=u.searchParams.get('walk')==='1',place=u.searchParams.has('to');
   const selected=u.searchParams.get('service')==='west'?west:ride;
   const stop=onboard?b:selected.stop;
   const departure=onboard?{...west,tripId:verified?'later-west':'west',accessVerified:verified,arrival:iso(verified?720:300),departure:iso(verified?750:330)}:selected;
   data={stop,nearbyStops:[a,b],services:onboard?[departure]:[ride,west],departure,geometry:[[gps.lat-.003,gps.lon],[stop.lat,stop.lon],[-31.947,115.854]],
    centre:onboard?alight:gps,radius:onboard?300:500,generatedAt:iso(0),estimated:true,
    onboard:onboard?{trip:{...ride,id:'ride'},nextStop:alight,choices:[alight,{...alight,id:'FINAL',name:'Final stand',sequence:30}],passedSequence:10,alightAt:iso(90),geometry:[[gps.lat,gps.lon],[alight.lat,alight.lon]]}:null};
   if(onboard&&verified||place)data.walk={available:true,from:alight,to:stop,geometry:[[alight.lat,alight.lon],[alight.lat,115.8495],[stop.lat,stop.lon]],duration:240,distance:300,accessConnectors:[]};
   if(place){const [lat,lon]=u.searchParams.get('to').split(',').map(Number);data.place={lat,lon,name:u.searchParams.get('name')};data.departure=null;data.geometry=[];}
  }
  await r.fulfill({json:data});
 });
 await page.route('**/assets/app.js*',async r=>{const response=await r.fetch(),s=await response.text();await r.fulfill({response,body:s.replace('  init();','  window.__mapTest={state,updateUserLocation,fetchNearbyBus};\n  init();')});});
 await page.goto(base,{waitUntil:'domcontentloaded'});await page.waitForFunction(()=>window.__mapTest);
 const fix=async(accuracy=5)=>page.evaluate(({gps,accuracy})=>{__mapTest.state.locationConsent=true;__mapTest.updateUserLocation({timestamp:Date.now(),coords:{latitude:gps.lat,longitude:gps.lon,accuracy}});},{gps,accuracy});
 await fix();await page.waitForFunction(()=>__mapTest.state.nearbyBusData&&document.querySelectorAll('.nearby-map-option').length>0);
 check(await page.locator('.nearby-map-option').count()>=1,`${width}px destinations and countdowns are rendered at real map stops`);
 check(await page.locator('.nearby-radius-label').innerText()==='500 m search',`${width}px radius is labelled separately from GPS accuracy`);
 const box=await page.locator('#nearby-bus').boundingBox();check(box.height<900*.32&&box.x>=0&&box.x+box.width<=width,`${width}px collapsed controls leave the map visible`);
 await page.getByRole('button',{name:"I'm on this service",exact:true}).click();await page.waitForFunction(()=>__mapTest.state.nearbyBusData?.onboard);
 check(await page.locator('.nearby-radius-label').innerText()==='300 m from alighting stop',`${width}px onboard radius moves to the next alighting stop`);
 check((await page.locator('.nearby-onboard-title').innerText()).includes('960'),`${width}px a confirmed current service stays explicit`);
 check(await page.evaluate(()=>__mapTest.state.nearbyBusData.centre.id==='AL'),`${width}px onward search never starts at mid-road GPS`);
 await page.waitForFunction(()=>document.querySelector('.nearby-map-option'));
 await page.locator('.nearby-map-option').first().click();await page.waitForFunction(()=>__mapTest.state.nearbyBusData?.walk?.available);
 check(queries.at(-1).walk==='1'&&queries.at(-1).trip==='ride',`${width}px tapping an onward option requests walking verification with the confirmed trip`);
 check(await page.evaluate(()=>__mapTest.state.nearbyBusData.departure.tripId==='later-west'),`${width}px verified access replaces the too-early service`);
 check((await page.locator('.nearby-walk-summary').innerText()).includes('Alight → walk 4 min'),`${width}px the micro-itinerary includes alighting and the actual walk`);
 check(await page.evaluate(()=>__mapTest.state.nearbyBusLayers.getLayers().some(l=>l.options.className==='nearby-walking-path'&&l.getLatLngs().length===3)),`${width}px walking geometry preserves its street bends`);
 await page.evaluate(()=>{Nextstop.getMap().fire('dragstart');});
 check(await page.evaluate(()=>__mapTest.state.nearbyCamera===null),`${width}px exploring pauses automatic camera movement`);
 await page.evaluate(()=>Nextstop.choosePlace({lat:-31.948,lon:115.849,name:'Choose museum entrance',requiresEntrance:true}));
 await page.locator('#cancel-map-pick').click();
 check(await page.evaluate(()=>Boolean(__mapTest.state.onboard)&&!__mapTest.state.onboard.place),`${width}px cancelling an entrance choice preserves onboard exploration`);
 await page.evaluate(()=>Nextstop.choosePlace({lat:-31.948,lon:115.849,name:'Fixture museum'}));await page.waitForFunction(()=>__mapTest.state.nearbyBusData?.place);
 check(queries.at(-1).to==='-31.948,115.849'&&await page.evaluate(()=>__mapTest.state.nearbyBusData.departure===null),`${width}px a place can be reached on foot after alighting without an invented bus leg`);
 await page.screenshot({path:`/tmp/nextstop-onboard-${width}.png`});
 await fix(500);check(await page.evaluate(()=>Boolean(__mapTest.state.onboard)&&!__mapTest.state.nearbyBusData),`${width}px coarse GPS pauses guidance while preserving confirmed boarding`);
 check((await page.locator('#nearby-bus').innerText()).includes('guidance paused'),`${width}px uncertain guidance is clearly identified`);
 await page.getByRole('button',{name:"I'm off",exact:true}).click();check(await page.evaluate(()=>!__mapTest.state.onboard),`${width}px explicit alighting exits onboard mode immediately`);
 await fix();await page.waitForFunction(()=>__mapTest.state.nearbyBusData);
 await page.getByRole('button',{name:"I'm on this service",exact:true}).click();await page.waitForFunction(()=>__mapTest.state.onboard);
 await page.evaluate(()=>{const now=Date.now(),from={lat:-31.95,lon:115.85,name:'Start'},to={lat:-31.949,lon:115.85,name:'Finish'},departure=new Date(now).toISOString(),arrival=new Date(now+600000).toISOString();Nextstop.showJourney({id:'fixture',duration:600,departure,arrival,legs:[{mode:'walk',from,to,departure,arrival,geometry:[[from.lat,from.lon],[to.lat,to.lon]]}]},from,to)});
 check(await page.evaluate(()=>!__mapTest.state.onboard&&!__mapTest.state.nearbyBusData),`${width}px a planned journey takes priority over onboard exploration`);
 check(errors.length===0,`${width}px no JavaScript exceptions`);await ctx.close();
}console.log(`\n${checks} map-first/onboard browser checks passed. API, GPS and map-tile fixtures only.`)}finally{await browser.close()}
