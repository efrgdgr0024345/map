/** Selected-journey last-mile handoff. Disclosed timetable/GPS/map and Uber landing-page fixtures. */
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {existsSync} from 'node:fs';
const require=createRequire(import.meta.url),{chromium}=require('playwright');
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BINARY||(existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined)});
const base=process.env.BASE_URL||'http://127.0.0.1:8080/';let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS:',label)};
try{for(const width of [320,390,430,1366]){
 const context=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce',hasTouch:width<760}),page=await context.newPage(),errors=[],uberRequests=[];
 page.on('pageerror',e=>errors.push(e.message));await page.clock.install();
 await page.addInitScript(()=>{navigator.geolocation.watchPosition=()=>1;navigator.geolocation.clearWatch=()=>{};});
 await context.route('https://m.uber.com/**',async r=>{uberRequests.push(r.request().url());await r.fulfill({contentType:'text/html',body:'<title>Disclosed Uber handoff fixture</title><p>No booking was made.</p>'});});
 await page.route('https://tile.openstreetmap.org/**',r=>r.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256"><rect width="256" height="256" fill="#e7eddf"/></svg>'}));
 const from={lat:-31.9505,lon:115.8586,name:'Starting point'},board={id:'A',lat:-31.9501,lon:115.8586,name:'Boarding stand'},alight={id:'B',lat:-31.955,lon:115.865,name:'Perth alighting platform'},to={lat:-31.9595,lon:115.868,name:'Museum & café / entrance'};
 const iso=s=>new Date(Date.now()+s*1000).toISOString();
 function journey(id,seconds,mode='bus',pickup=alight){
  pickup={...pickup};
  const walk={mode:'walk',routing:'street',duration:120,distance:150,from,to:board,departure:iso(0),arrival:iso(120),geometry:[[from.lat,from.lon],[board.lat,board.lon]]};
  const ride={mode,tripId:'trip-'+id,routeId:'R',routeName:'960',headsign:'Perth',serviceDate:'20261122',from:board,to:pickup,departure:iso(240),arrival:iso(840),geometry:[[board.lat,board.lon],[pickup.lat,pickup.lon]]};
  const final={mode:'walk',routing:'street',duration:seconds,distance:825,from:pickup,to,departure:iso(840),arrival:iso(840+seconds),geometry:[[pickup.lat,pickup.lon],[to.lat,to.lon]]};
  return{id,walkRouting:'street',departure:walk.departure,arrival:final.arrival,duration:840+seconds,leaveAt:iso(0),boardingAt:ride.departure,walkToBoardSeconds:120,boardingBufferSeconds:120,legs:[walk,ride,final]};
 }
 let payload={mode:'timetable',journeys:[journey('long',660)]},statusMode='timetable',expired=false;
 await page.route('**/api.php*',async r=>{
  const u=new URL(r.request().url()),action=u.searchParams.get('action');let data={};
  if(action==='status')data={mode:statusMode,expired,routeRevision:'uber-fixture'};
  else if(action==='routes')data={revision:'uber-fixture',lines:[]};
  else if(action==='vehicles')data={vehicles:[{id:'live-bus',tripId:'live-trip',mode:'bus',routeName:'960',headsign:'Live preview',lat:from.lat,lon:from.lon}],stops:[board],generatedAt:iso(0)};
  else if(action==='nearby-bus'){const service={key:'nearby',mode:'bus',routeName:'960',headsign:'Nearby preview',tripId:'nearby-trip',stop:board,stopId:board.id,stopName:board.name,arrival:iso(300),departure:iso(300)};data={centre:from,radius:500,stop:board,nearbyStops:[board],services:[service],departure:service,geometry:[[from.lat,from.lon],[board.lat,board.lon]],generatedAt:iso(0)};}
  else if(action==='timetable')data={stop:board,departures:[],generatedAt:iso(0)};
  else if(action==='plan')data=payload;
  else if(action==='approach')data={error:'Disclosed fixture omits independent incoming geometry'};
  await r.fulfill({json:data});
 });
 await page.route('**/assets/app.js*',async r=>{const response=await r.fetch(),s=await response.text();await r.fulfill({response,body:s.replace('  init();','  window.__uberTest={state,setPanel,clearJourney,openTimetable,updateUserLocation,fetchVehicles,loadStatus,renderLastMileUber};\n  init();')});});
 await page.goto(base,{waitUntil:'domcontentloaded'});await page.waitForFunction(()=>window.__uberTest?.state.status?.mode==='timetable');
 const absent=()=>page.locator('#last-mile-uber').evaluate(n=>n.hidden&&n.querySelectorAll('a').length===0);
 check(await absent(),`${width}px live vehicle updates do not create an Uber suggestion`);
 await page.evaluate(()=>{const t=__uberTest;t.state.locationConsent=true;t.updateUserLocation({timestamp:Date.now(),coords:{latitude:-31.9505,longitude:115.8586,accuracy:5}});});await page.waitForFunction(()=>__uberTest.state.nearbyBusData);
 check(await absent(),`${width}px a nearby service preview cannot trigger Uber`);
 await page.evaluate(()=>__uberTest.openTimetable({stop:'A'}));await page.locator('#timetable-dialog').waitFor({state:'visible'});
 check(await absent(),`${width}px a stop board or timetable cannot trigger Uber`);await page.locator('#timetable-close').click();
 await page.locator('#map-view-toggle').click();check(await absent(),`${width}px exploratory map-only viewing does not create an Uber offer`);await page.locator('#map-view-toggle').click();
 async function choose(list,mode='timetable'){
  payload={mode,journeys:list};await page.evaluate(({from,to})=>{const t=__uberTest;t.clearJourney(false);t.state.origin=from;t.state.destination=to;t.state.planSignature='';document.querySelector('#origin-input').value=from.name;document.querySelector('#destination-input').value=to.name;t.setPanel(true);},{from,to});
  await page.locator('#plan-button').click();await page.waitForFunction(id=>__uberTest.state.selected?.id===id,list[0].id);await page.evaluate(()=>__uberTest.setPanel(true));
 }
 await choose([journey('long',660)]);await page.locator('#last-mile-uber').waitFor({state:'visible'});
 const saved=await page.evaluate(()=>JSON.stringify(__uberTest.state.selected));
 check((await page.locator('#last-mile-uber').innerText()).includes('Saves 11 min walking'),`${width}px the selected itinerary shows walking minutes avoided`);
 check((await page.locator('.last-mile-price').innerText())==='Approx. A$8–12',`${width}px a simple Perth estimate is clearly approximate and in AUD`);
 const href=await page.locator('.last-mile-link').getAttribute('href'),url=new URL(href);
 check(url.origin==='https://m.uber.com'&&url.pathname==='/ul/'&&url.searchParams.get('action')==='setPickup',`${width}px handoff uses the official universal/mobile Uber link`);
 check(Number(url.searchParams.get('pickup[latitude]'))===alight.lat&&Number(url.searchParams.get('pickup[longitude]'))===alight.lon,`${width}px pickup is the final walk's alighting stop rather than current phone GPS`);
 check(Number(url.searchParams.get('dropoff[latitude]'))===to.lat&&Number(url.searchParams.get('dropoff[longitude]'))===to.lon&&url.searchParams.get('dropoff[nickname]')===to.name,`${width}px drop-off and names survive URL encoding without changing the destination`);
 check(uberRequests.length===0,`${width}px no Uber request or coordinate handoff occurs before a click`);
 await page.locator('#last-mile-uber').scrollIntoViewIfNeeded();const cta=await page.locator('.last-mile-link').boundingBox();check(cta.height>=44&&cta.x>=0&&cta.x+cta.width<=width,`${width}px the optional card and Uber action fit the screen`);
 await page.screenshot({path:`/tmp/nextstop-last-mile-uber-${width}.png`});
 const [popup]=await Promise.all([page.waitForEvent('popup'),page.locator('.last-mile-link').click()]);await popup.waitForLoadState('domcontentloaded');
 check(new URL(popup.url()).hostname==='m.uber.com'&&uberRequests.length===1,`${width}px clicking opens the official mobile handoff and does not book a ride`);await popup.close();
 check(await page.evaluate(()=>JSON.stringify(__uberTest.state.selected))===saved,`${width}px the original transit and walking plan remains unchanged`);
 await page.evaluate(()=>Nextstop.getMap().fire('dragstart'));check(await absent(),`${width}px pausing following removes the offer during exploration`);
 await page.locator('#follow-journey').click();check(!(await page.locator('#last-mile-uber').evaluate(n=>n.hidden)),`${width}px recentering the actively selected journey restores its offer`);
 await choose([journey('boundary',180)]);check(await absent(),`${width}px exactly three minutes does not trigger the strict greater-than rule`);
 await choose([journey('above-boundary',181)]);check(!(await page.locator('#last-mile-uber').evaluate(n=>n.hidden)),`${width}px a final walk just over 180 seconds triggers the temporary test threshold`);
 const short=journey('short-choice',60),alternative=journey('long-alternative',720,'train',{...alight,id:'C',lat:-31.956,lon:115.866,name:'Alternative rail platform'});
 await choose([short,alternative]);check(await absent(),`${width}px an unselected alternative with a long walk cannot create a suggestion`);
 await page.locator('.journey-option[data-journey="long-alternative"]').click();
 check(Number(new URL(await page.locator('.last-mile-link').getAttribute('href')).searchParams.get('pickup[latitude]'))===alternative.legs.at(-1).from.lat,`${width}px explicitly selecting a train alternative updates the handoff to its own alighting point`);
 await choose([journey('demo-response',660)],'demo');check(await absent(),`${width}px illustrative demo journeys never get an Uber offer even with real-looking legs`);
 await choose([journey('valid-again',660)]);statusMode='demo';await page.evaluate(()=>__uberTest.loadStatus());check(await absent(),`${width}px a demo feed also disables the offer`);statusMode='timetable';await page.evaluate(()=>__uberTest.loadStatus());
 expired=true;await page.evaluate(()=>__uberTest.loadStatus());check(await absent(),`${width}px an expired timetable cannot be presented as a real active journey`);expired=false;await page.evaluate(()=>__uberTest.loadStatus());
 const walking=journey('walk-only',660);walking.legs=[walking.legs.at(-1)];await choose([walking]);check(await absent(),`${width}px walking-only routes have no alighting stop and do not trigger Uber`);
 const middle=journey('long-middle',180);middle.legs[0].duration=900;await choose([middle]);check(await absent(),`${width}px a long initial or transfer walk is not substituted for the final walk rule`);
 const invalid=journey('invalid-coordinates',660);invalid.legs.at(-1).from.lon=null;await choose([invalid]);check(await absent(),`${width}px invalid pickup coordinates never produce a malformed Uber link`);
 const noDuration=journey('time-fallback',660,'ferry');delete noDuration.legs.at(-1).duration;await choose([noDuration]);check(!(await page.locator('#last-mile-uber').evaluate(n=>n.hidden)),`${width}px a ferry journey can use the final leg's timestamps when duration is absent`);
 await page.evaluate(()=>__uberTest.clearJourney(false));check(await absent(),`${width}px clearing the journey also removes the saved Uber URL`);
 payload={mode:'timetable',journeys:[journey('day-draft',660)]};
 await page.evaluate(to=>NextstopPlanner.visit({...to,id:'museum-fixture'}),to);await page.locator('#planner-stay-save').click();await page.locator('#planner-return').uncheck();await page.locator('#planner-build').click();await page.locator('.planner-trip-card').waitFor({state:'visible'});
 check(await absent()&&await page.locator('.planner-trip-card .last-mile-link').count()===0,`${width}px unchosen day-planner drafts remain pure transit plans`);
 await page.getByRole('button',{name:'Show this journey on map',exact:true}).click();await page.evaluate(()=>__uberTest.setPanel(true));
 check(!(await page.locator('#last-mile-uber').evaluate(n=>n.hidden)),`${width}px explicitly following a real day-planner journey enables its final-walk option`);
 check(errors.length===0,`${width}px no JavaScript exceptions`);await context.close();
}console.log(`\n${checks} selected-journey Uber checks passed. Disclosed timetable, GPS, map and Uber landing-page fixtures; no real booking or fare API calls.`)}finally{await browser.close()}
