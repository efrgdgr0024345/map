/** Mobile arrival deadlines and route colours. Timetable, GPS and tile responses are disclosed fixtures. */
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {existsSync} from 'node:fs';
const require=createRequire(import.meta.url),{chromium}=require('playwright');
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BINARY||(existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined)});
const base=process.env.BASE_URL||'http://127.0.0.1:8080/';
let checks=0;const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS:',label)};
try{for(const [width,timezoneId] of [[320,'Australia/Sydney'],[390,'Europe/London'],[430,'Australia/Perth'],[1366,'America/New_York']]){
 const context=await browser.newContext({viewport:{width,height:1000},timezoneId,reducedMotion:'reduce',hasTouch:width<760});
 const page=await context.newPage(),errors=[],requests=[],held=[];let scenario='normal';
 page.on('pageerror',e=>errors.push(e.message));await page.clock.install({time:new Date('2026-11-23T01:00:00Z')});
 await page.addInitScript(()=>{navigator.geolocation.watchPosition=()=>1;navigator.geolocation.clearWatch=()=>{}});
 const from={lat:-31.9505,lon:115.8586,name:'Fixture start'},board={id:'A',lat:-31.95,lon:115.859,name:'Board here'},alight={id:'B',lat:-31.956,lon:115.864,name:'Alight here'},to={lat:-31.959,lon:115.868,name:'Fixture destination'};
 const palette=[['bus','960','#009645','#000000'],['bus','Blue CAT','#002F6C','#FFFFFF'],['ferry','Ferry','#009645','#000000'],['train','Airport Line','#4FC7B5','#000000'],['bus','Red CAT','#9D2235','#FFFFFF']];
 const fleet=()=>palette.map(([mode,routeName,routeColor,routeTextColor],i)=>({id:'vehicle-'+i,tripId:'trip-'+i,routeId:'R'+i,mode,routeName,routeColor,routeTextColor,headsign:'Fixture direction '+i,lat:from.lat+i*.0002,lon:from.lon+i*.0002,bearing:45,serviceDate:'20261123'}));
 const iso=ms=>new Date(ms).toISOString();
 function journey(end,service=fleet()[0]){
  const start=end-1800000;
  const walk={mode:'walk',routing:'street',from,to:board,duration:120,distance:150,departure:iso(start),arrival:iso(start+120000),geometry:[[from.lat,from.lon],[board.lat,board.lon]]};
  const ride={...service,from:board,to:alight,departure:iso(start+240000),arrival:iso(end-600000),geometry:[[board.lat,board.lon],[alight.lat,alight.lon]]};
  const final={mode:'walk',routing:'street',from:alight,to,duration:600,distance:750,departure:ride.arrival,arrival:iso(end),geometry:[[alight.lat,alight.lon],[to.lat,to.lon]]};
  return {id:'journey-'+end,walkRouting:'street',departure:walk.departure,arrival:final.arrival,leaveAt:walk.departure,boardingAt:ride.departure,boardingBufferSeconds:120,walkToBoardSeconds:120,duration:1800,transfers:0,legs:[walk,ride,final]};
 }
 await page.route('https://tile.openstreetmap.org/**',r=>r.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256"><rect width="256" height="256" fill="#e7eddf"/></svg>'}));
 await page.route('**/api.php*',async r=>{
  const u=new URL(r.request().url()),action=u.searchParams.get('action');let data={};
  if(action==='status')data={mode:'timetable',expired:false,routeRevision:'arrival-colours'};
  else if(action==='routes')data={revision:'arrival-colours',lines:fleet().map(v=>({...v,geometry:[[from.lat,from.lon],[alight.lat,alight.lon]]}))};
  else if(action==='vehicles')data={vehicles:fleet().filter(v=>u.searchParams.get('rail')!=='only'||v.mode!=='bus'),stops:[],lines:[],generatedAt:iso(Date.now())};
  else if(action==='plan'){
   const params=Object.fromEntries(u.searchParams),test=scenario;requests.push(params);
   const deadline=params.arriveBy?Date.parse(params.arriveBy):Date.now()+3600000;
   let j=journey(deadline+(test==='late'?1000:0));
   if(test==='invalid')j.arrival='invalid';
   data={mode:test==='demo'?'demo':'timetable',journeys:test==='empty'?[]:[j]};
   if(test==='slow')await new Promise(resolve=>held.push(resolve));
  }else if(action==='timetable'){
   const service=fleet().find(v=>v.tripId===u.searchParams.get('trip'))||fleet()[2];
   data=u.searchParams.has('trip')?{trip:service,stops:[{...board,departure:iso(Date.now()+3600000)},{...alight,arrival:iso(Date.now()+4200000)}]}:{stop:board,departures:fleet().map(v=>({...v,departure:iso(Date.now()+3600000)}))};
  }
  try{await r.fulfill({json:data})}catch(e){if(!r.request().failure())throw e}
 });
 await page.route('**/assets/app.js*',async r=>{const response=await r.fetch(),source=await response.text();await r.fulfill({response,body:source.replace('  init();','  window.__arrivalTest={state,setPlace,setPanel,plan,renderVehicles,drawNetworkLines,openTimetable};\n  init();')});});
 await page.goto(base,{waitUntil:'domcontentloaded'});await page.waitForFunction(()=>window.__arrivalTest&&Nextstop.getMap());
 await page.evaluate(from=>{__arrivalTest.setPlace('origin',from);__arrivalTest.setPanel(true)},from);
 check(await page.locator('#arrival-fields').isHidden()&&await page.locator('#arrival-time').isDisabled(),`${width}px leave-now starts with no required hidden arrival input`);
 await page.locator('[data-journey-time="arrive"]').click();
 check(await page.locator('#arrival-fields').isVisible(),`${width}px arrival is easy to reveal from the main trip form`);
 const defaultMinutes=await page.evaluate(()=>(Date.parse(document.getElementById('arrival-date').value+'T'+document.getElementById('arrival-time').value+':00+08:00')-Date.now())/60000);
 check(await page.locator('#arrival-date').inputValue()==='2026-11-23'&&defaultMinutes>=59&&defaultMinutes<=65,`${width}px arrival defaults use Perth time on a ${timezoneId} device`);
 for(const selector of ['[data-journey-time="arrive"]','#arrival-date','#arrival-time','[data-arrival-minutes="30"]']){
  const box=await page.locator(selector).boundingBox();check(box.height>=44&&box.x>=0&&box.x+box.width<=width,`${width}px ${selector} has a visible phone-sized touch target`);
 }
 check(await page.locator('#arrival-time').evaluate(e=>getComputedStyle(e).fontSize)==='16px',`${width}px native time picker avoids automatic phone text zoom`);
 await page.locator('#arrival-date').fill('2026-11-24');await page.locator('#arrival-time').fill('08:45');await page.locator('#arrival-time').dispatchEvent('change');
 await page.evaluate(to=>__arrivalTest.setPlace('destination',to),to);await page.clock.runFor(500);
 await page.waitForFunction(()=>__arrivalTest.state.selected);
 check(requests.at(-1).arriveBy==='2026-11-24T00:45:00Z'&&!requests.at(-1).at,`${width}px planner receives the requested destination deadline with the correct UTC offset`);
 check(await page.evaluate(()=>Date.parse(__arrivalTest.state.selected.arrival)<=Date.parse(__arrivalTest.state.selected.arriveBy)),`${width}px selected journey reaches the destination including its final walk by the deadline`);
 await page.evaluate(()=>__arrivalTest.setPanel(true));
 check((await page.locator('#journey-arrival-summary').innerText()).includes('Leave by')&&(await page.locator('#journey-arrival-summary').innerText()).includes('8:45'),`${width}px result explains when to leave and when to arrive`);
 check((await page.locator('.option-label').first().innerText())==='Latest departure',`${width}px reverse search is labelled by its latest feasible start`);
 await page.screenshot({path:`/tmp/nextstop-arrive-by-${width}.png`});
 await page.locator('[data-journey-time="now"]').click();await page.clock.runFor(500);await page.waitForFunction(()=>__arrivalTest.state.selected&&!__arrivalTest.state.selected.arriveBy);
 check(!requests.at(-1).arriveBy&&await page.locator('#journey-arrival-summary').isHidden(),`${width}px switching to leave-now removes the deadline and replans`);
 await page.evaluate(()=>__arrivalTest.setPanel(true));await page.locator('[data-journey-time="arrive"]').click();await page.clock.runFor(500);await page.waitForFunction(()=>__arrivalTest.state.selected?.arriveBy);
 await page.evaluate(()=>__arrivalTest.setPanel(true));
 const before=requests.length;await page.locator('#arrival-date').fill('2026-11-22');await page.locator('#arrival-date').dispatchEvent('change');await page.clock.runFor(500);
 check(requests.length===before&&!await page.evaluate(()=>__arrivalTest.state.selected)&&(await page.locator('#message').innerText()).includes('future'),`${width}px past deadlines clear stale results and do not send a plan request`);
 await page.locator('#arrival-date').fill('2026-12-09');await page.locator('#arrival-date').dispatchEvent('change');await page.clock.runFor(500);
 check(requests.length===before&&(await page.locator('#message').innerText()).includes('fourteen'),`${width}px arrivals outside the supported planning window are explained`);
 await page.locator('#arrival-date').fill('2026-11-24');await page.locator('#arrival-time').fill('');await page.locator('#arrival-time').dispatchEvent('change');await page.clock.runFor(500);
 check(requests.length===before&&(await page.locator('#message').innerText()).includes('Choose your arrival'),`${width}px incomplete times cannot accidentally become leave-now plans`);
 for(const test of ['late','empty','demo','invalid']){
  scenario=test;await page.locator('#arrival-time').fill('09:15');await page.locator('#arrival-time').dispatchEvent('change');await page.clock.runFor(500);await page.waitForFunction(()=>!__arrivalTest.state.planController);
  check(!await page.evaluate(()=>__arrivalTest.state.selected)&&(await page.locator('#message').innerText()).includes(test==='demo'?'real timetable':'No journey can reach'),`${width}px ${test} responses cannot claim an arrival guarantee`);
 }
 scenario='normal';await page.locator('[data-arrival-minutes="30"]').click();await page.clock.runFor(500);await page.waitForFunction(()=>__arrivalTest.state.selected);
 check(Date.parse(requests.at(-1).arriveBy)>await page.evaluate(()=>Date.now())&&Date.parse(requests.at(-1).arriveBy)<=await page.evaluate(()=>Date.now()+31*60000),`${width}px one tap sets an arrival thirty minutes from now`);
 await page.evaluate(()=>__arrivalTest.setPanel(true));scenario='slow';await page.locator('#arrival-time').fill('11:30');await page.locator('#arrival-time').dispatchEvent('change');await page.clock.runFor(500);
 await page.waitForFunction(()=>Boolean(__arrivalTest.state.planController));scenario='normal';await page.locator('#arrival-time').fill('12:30');await page.locator('#arrival-time').dispatchEvent('change');await page.clock.runFor(500);await page.waitForFunction(()=>__arrivalTest.state.selected?.arriveBy?.includes('04:30:00'));
 held.splice(0).forEach(resolve=>resolve());await page.clock.runFor(100);
 check(await page.evaluate(()=>__arrivalTest.state.selected.arriveBy.includes('04:30:00')),`${width}px a slow older response cannot replace a changed destination deadline`);
 await page.evaluate(vehicles=>__arrivalTest.renderVehicles({vehicles,stops:[],generatedAt:new Date().toISOString()}),fleet());
 for(const [index,[mode,name,color]] of palette.entries()){
  const actual=await page.evaluate(id=>{const body=__arrivalTest.state.markers.get(id)?.getElement()?.querySelector('.vehicle-body');return body?{color:body.style.getPropertyValue('--route-color'),background:getComputedStyle(body).backgroundColor,next:body.classList.contains('is-next')}:null},'vehicle-'+index);
  const rgb='rgb('+[1,3,5].map(i=>parseInt(color.slice(i,i+2),16)).join(', ')+')';
  check(actual?.color===color&&actual?.background===rgb,`${width}px ${name} ${mode} marker uses its published colour`);
  if(index===0)check(actual.next,`${width}px selected bus keeps its official hue while its halo identifies selection`);
 }
 await page.evaluate(()=>__arrivalTest.openTimetable({stop:'A'}));await page.locator('.departure-row').first().waitFor();
 check(await page.locator('.departure-row .route-chip').count()===palette.length,`${width}px the stop board retains route badges for every mode`);
 await page.locator('#timetable-close').click();await page.clock.runFor(100);
 await page.waitForFunction(()=>!document.getElementById('timetable-dialog').open&&__arrivalTest.state.timetableHistory.length===0);
 await page.evaluate(()=>__arrivalTest.openTimetable({trip:'trip-2'}));await page.locator('.timetable-trip-summary').waitFor();
 check(await page.locator('.timetable-trip-summary .route-chip').evaluate(e=>getComputedStyle(e).backgroundColor)==='rgb(0, 150, 69)',`${width}px ferry timetable uses the feed's green rather than a generic blue`);
 await page.locator('#timetable-close').click();await page.clock.runFor(100);
 await page.waitForFunction(()=>!document.getElementById('timetable-dialog').open&&__arrivalTest.state.timetableHistory.length===0);
 await page.evaluate(to=>NextstopPlanner.visit({...to,id:'fixture-destination'}),to);await page.locator('#planner-stay-save').click();await page.locator('#planner-return').uncheck();await page.locator('#planner-build').click();await page.locator('.planner-trip-card .route-chip').waitFor();
 check(await page.locator('.planner-trip-card .route-chip').first().evaluate(e=>getComputedStyle(e).backgroundColor)==='rgb(0, 150, 69)',`${width}px saved day-plan cards share the same official colour renderer`);
 check(errors.length===0,`${width}px no JavaScript errors`);await context.close();
}console.log(`\n${checks} mobile arrival and route-colour checks passed. Disclosed timetable/GPS/tile/clock fixtures.`)}finally{await browser.close()}
