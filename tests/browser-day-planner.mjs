/** UI regression with disclosed timetable/geocode/Google fixtures. No live OAuth or phone alarm claim. */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {existsSync} from 'node:fs';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url),{chromium}=require('playwright');
const base=process.env.BASE_URL||'http://127.0.0.1:8080/';
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BINARY||(existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined)});
let checks=0;const ok=(v,label)=>{assert.ok(v,label);checks++;console.log('PASS:',label)};
try{
 for(const width of [390,1366]){
  const context=await browser.newContext({viewport:{width,height:900},isMobile:width<760,hasTouch:width<760,timezoneId:'Australia/Perth',acceptDownloads:true});const page=await context.newPage();const errors=[],requests=[],writes=[];let connected=false;
  page.on('pageerror',e=>errors.push(e.message));
  const now=Date.now(),eventStart=new Date(now+4*3600000).toISOString(),eventEnd=new Date(now+5*3600000).toISOString();
  const home={lat:-31.9505,lon:115.8586,name:'Home'},gym={lat:-31.96,lon:115.86,name:'Gym entrance'};
  await page.addInitScript(()=>{let lib;Object.defineProperty(window,'L',{configurable:true,get(){return lib},set(value){lib=value;const original=value.map;value.map=(...args)=>{const map=original(...args);window.__plannerMap=map;return map}}})});
  await page.route('**/api.php*',async route=>{
   const url=new URL(route.request().url()),action=url.searchParams.get('action');requests.push({action,params:Object.fromEntries(url.searchParams)});let data;
   if(action==='status')data={mode:'timetable',message:'Fixture timetable',routeRevision:'planner-fixture'};
   else if(action==='routes')data={revision:'planner-fixture',lines:[]};
   else if(action==='vehicles')data={vehicles:[{id:'fixture-network',tripId:'fixture-network',mode:'bus',routeName:'915',headsign:'Fixture',lat:home.lat,lon:home.lon,bearing:90}],stops:[],lines:[],generatedAt:new Date().toISOString()};
   else if(action==='geocode')data={results:[{...gym,name:'Gym entrance, Perth'}]};
   else if(action==='plan'){
    const [a,b]=['from','to'].map(k=>{const [lat,lon]=url.searchParams.get(k).split(',').map(Number);return{lat,lon,name:k==='from'?'Start':'Destination'}});
    const start=url.searchParams.has('arriveBy')?Date.parse(url.searchParams.get('arriveBy'))-30*60000:Date.parse(url.searchParams.get('at'));
    const iso=n=>new Date(start+n*60000).toISOString();const walk={mode:'walk',routing:'street',duration:120,distance:150,from:a,to:{...a,id:'A'},departure:iso(0),arrival:iso(2),geometry:[[a.lat,a.lon],[a.lat+.0001,a.lon],[a.lat+.0001,a.lon+.0001]]};
    const ride={mode:'bus',tripId:'trip-'+start,routeId:'R',routeName:'915',serviceDate:'20261027',headsign:'Destination',from:{...a,id:'A'},to:{...b,id:'B'},departure:iso(5),arrival:iso(15),geometry:[[a.lat,a.lon],[b.lat,b.lon]]};
    data={mode:'timetable',journeys:[{id:'journey-'+start,walkRouting:'street',leaveAt:iso(1),boardingAt:iso(5),walkToBoardSeconds:120,boardingBufferSeconds:120,departure:iso(0),arrival:iso(15),duration:900,legs:[walk,ride]}]};
   }else if(action==='approach')data={error:'Fixture approach not required'};else data={};
   await route.fulfill({json:data});
  });
  await page.route('**/calendar.php*',async route=>{
   const url=new URL(route.request().url()),action=url.searchParams.get('action');let data;
   if(action==='status')data={configured:true,connected,csrf:'fixture-csrf'};
   else if(action==='events')data={events:[{id:'event-A',title:'Gym appointment',location:'Gym entrance',start:eventStart,end:eventEnd,allDay:false}],moreAvailable:false};
   else if(action==='add-travel'){const input=JSON.parse(route.request().postData());writes.push(input);data={added:input.blocks.map((b,i)=>({id:String(i),title:b.title}))};}
   else if(action==='disconnect'){connected=false;data={connected:false};}else data={};
   await route.fulfill({json:data});
  });
  await page.goto(base,{waitUntil:'domcontentloaded'});await page.waitForFunction(()=>window.__plannerMap);
  ok(await page.evaluate(()=>__plannerMap.getZoom()===16),`${width}px starts at the requested street zoom`);
  ok(await page.locator('.brand-cat').evaluate(e=>e.getBoundingClientRect().width>=(innerWidth<760?48:56)),`${width}px brand cat is larger`);
  await page.evaluate(()=>__plannerMap.setZoom(15,{animate:false}));await page.waitForTimeout(700);const count=requests.filter(r=>r.action==='vehicles').length;
  await page.waitForTimeout(700);ok(requests.filter(r=>r.action==='vehicles').length===count&&await page.locator('.vehicle-marker').count()===0,`${width}px zooming out hides the fleet and stops fleet requests`);
  await page.evaluate(()=>__plannerMap.setZoom(16,{animate:false}));await page.waitForTimeout(700);ok(requests.filter(r=>r.action==='vehicles').length>count,`${width}px street zoom resumes network requests`);
  await page.locator('#open-day-planner').click();await page.locator('#day-planner').waitFor({state:'visible'});
  ok(await page.locator('#planner-departure-mode').inputValue()==='now'&&await page.locator('#planner-start-field').isHidden(),`${width}px Leave now is explicit and does not require a departure clock`);
  await page.locator('[data-planner-tab="places"]').click();
  await page.locator('#saved-place-name').fill('Home');await page.locator('#saved-place-map').click();await page.locator('#saved-place-save').click();
  ok((await page.locator('#planner-saved-list').textContent()).includes('Home'),`${width}px home can be saved from the map centre`);
  await page.locator('#saved-place-name').fill('Gym');await page.locator('#saved-place-address').fill('Gym entrance');await page.locator('#saved-place-search').click();await page.locator('#saved-place-results button').first().click();await page.locator('#saved-place-stay').fill('45');await page.locator('#saved-place-save').click();
  ok(await page.evaluate(()=>JSON.parse(localStorage.getItem('nextstop-places-v1')).length===2),`${width}px named places persist on this device`);
  await page.locator('[data-planner-tab="day"]').click();await page.locator('#planner-place-chips button').filter({hasText:/^Gym$/}).click();
  ok(await page.locator('#planner-stay').isVisible(),`${width}px selecting Gym asks how long to stay`);
  ok(await page.locator('#planner-stay-minutes').inputValue()==='45',`${width}px the usual stay saved with Gym pre-fills its visit`);
  await page.locator('#planner-stay-minutes').fill('60');await page.locator('#planner-stay-remember').check();await page.locator('#planner-stay-form button[type="submit"]').click();
  ok(await page.evaluate(()=>JSON.parse(localStorage.getItem('nextstop-places-v1')).find(p=>p.name==='Gym').stayMinutes===60),`${width}px Remember stay updates the saved place`);
  await page.locator('#planner-stops').getByRole('button',{name:'Edit stay'}).click();await page.locator('#planner-stay-minutes').fill('90');await page.locator('#planner-stay-save').click();
  ok((await page.locator('#planner-stops').textContent()).includes('90 min'),`${width}px an existing visit duration can be edited`);
  await page.locator('#planner-stops').getByRole('button',{name:'Edit stay'}).click();await page.locator('#planner-stay-minutes').fill('60');await page.locator('#planner-stay-save').click();
  await page.locator('#planner-departure-mode').selectOption('at');await page.locator('#planner-start').fill('2020-01-01T12:00');await page.locator('#planner-departure-mode').selectOption('now');
  await page.locator('#planner-build').click();await page.locator('.planner-trip-card').nth(1).waitFor();
  ok(await page.locator('.planner-trip-card').count()===2,`${width}px plans an outbound journey and a return home`);
  const planned=requests.filter(r=>r.action==='plan').slice(-2);ok(Math.abs(Date.parse(planned[0].params.at)-Date.now())<60000,`${width}px Leave now ignores an old custom departure time`);const outEnd=Date.parse(planned[0].params.at)+15*60000;
  ok(Date.parse(planned[1].params.at)===outEnd+60*60000,`${width}px return search starts after arrival plus the one-hour stay`);
  const downloadPromise=page.waitForEvent('download');await page.locator('#planner-download').click();const download=await downloadPromise,ics=await readFile(await download.path(),'utf8');
  ok((ics.match(/BEGIN:VEVENT/g)||[]).length===2&&(ics.match(/BEGIN:VALARM/g)||[]).length===4&&ics.includes('TRIGGER:-PT10M'),`${width}px calendar download contains both travel blocks and phone-calendar alarms`);
  ok(writes.length===0,`${width}px planning and downloading do not write to Google Calendar`);
  await page.locator('.planner-trip-card button').first().click();await page.waitForFunction(()=>!document.querySelector('#day-planner').open);
  ok(await page.evaluate(()=>__plannerMap.getZoom()>=16),`${width}px showing a journey uses a local following view`);
  await page.locator('#open-day-planner').click();connected=true;await page.locator('[data-planner-tab="calendar"]').click();await page.locator('#calendar-load').waitFor({state:'visible'});
  const before=requests.filter(r=>r.action==='plan').length;await page.locator('#calendar-load').click();await page.locator('.calendar-event-card').click();
  ok(requests.filter(r=>r.action==='plan').length===before&&writes.length===0,`${width}px loading or selecting an event never automatically plans or writes`);
  await page.locator('#calendar-event-results button').filter({hasText:/^Gym$/}).click();
  ok(await page.locator('#calendar-event-add').isDisabled(),`${width}px event travel requires the user's explicit selection consent`);
  await page.locator('#calendar-event-consent').check();await page.locator('#calendar-event-add').click();
  // Remove the earlier standalone gym visit; retain only the appointment.
  await page.locator('#planner-stops .planner-stop-row').first().getByRole('button',{name:'Remove'}).click();
  await page.locator('#planner-build').click();await page.locator('.planner-trip-card').nth(1).waitFor();
  const eventPlans=requests.filter(r=>r.action==='plan').slice(-2);
  ok(Date.parse(eventPlans[0].params.arriveBy)===Math.floor(Date.parse(eventStart)/1000)*1000,`${width}px selected appointment uses arrival-by planning`);
  ok(Date.parse(eventPlans[1].params.at)===Math.floor(Date.parse(eventEnd)/1000)*1000,`${width}px appointment return starts at the scheduled end`);
  await page.locator('#planner-google-preview').click();await page.locator('#planner-reminder-preview').waitFor({state:'visible'});
  ok(await page.locator('#planner-reminder-save').isDisabled()&&writes.length===0,`${width}px travel reminders have a separate review and agreement step`);
  await page.locator('#planner-reminder-consent').check();await page.locator('#planner-reminder-save').click();await page.waitForFunction(()=>document.querySelector('#planner-message').textContent.includes('travel reminders added'));
  ok(writes.length===1&&writes[0].confirmed===true&&writes[0].blocks.length===2,`${width}px only confirmed travel blocks are sent to Google`);
  ok(await page.locator('#day-planner').evaluate(el=>el.scrollWidth<=el.clientWidth+1),`${width}px planner has no horizontal overflow`);
  await page.evaluate(()=>{const places=JSON.parse(localStorage.getItem('nextstop-places-v1'));sessionStorage.setItem('nextstop-pending-day',JSON.stringify({stops:[{place:places.find(p=>p.name==='Gym'),minutes:60}],start:document.querySelector('#planner-start').value,origin:places.find(p=>p.name==='Home').id,returnHome:true}))});
  await page.goto(new URL('?planner=calendar',base).href,{waitUntil:'domcontentloaded'});
  await page.locator('#day-planner').waitFor({state:'visible'});await page.locator('[data-planner-tab="day"]').click();
  ok((await page.locator('#planner-stops').textContent()).includes('Gym')&&await page.evaluate(()=>sessionStorage.getItem('nextstop-pending-day')===null),`${width}px OAuth return restores the pending visits and removes temporary redirect storage`);
  ok(errors.length===0,`${width}px has no JavaScript exceptions`);
  await page.screenshot({path:`/tmp/nextstop-day-planner-${width}.png`});await context.close();
 }
 console.log(`\n${checks} day-planner/map checks passed. Google/geocode/timetable fixtures; no live OAuth or locked-phone alarm claim.`);
}finally{await browser.close()}
