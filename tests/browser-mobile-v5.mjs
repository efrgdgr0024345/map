/**
 * Mobile v5 regression against the real running Nextstop API.
 * navigator.geolocation is synthetic, to exercise coarse/fine/live fixes.
 * When the selected real transfer is still in the future, Playwright changes
 * browser Date to that real arrival for the automatic-arrival scenario only;
 * it restores wall time afterwards. The timetable/API clock is never changed.
 * Timetables, walking routes, station guides and Telstra phones are never mocked.
 * Run: node tests/browser-mobile-v5.mjs (uses the environment's NODE_PATH).
 * Optional BASE_URL / CHROMIUM_BINARY. CORE_ONLY=1 leaves live-provider checks
 * explicitly UNRUN; it must not be reported as a full integration pass.
 */
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const base = process.env.BASE_URL || 'http://127.0.0.1:8080/';
const executablePath = process.env.CHROMIUM_BINARY || (existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined);
const browser = await chromium.launch({ headless:true, executablePath });
const contexts = [];
const errors = [];
const limitations = [];
const simulations = [];
const telemetry = { plans:[], transfers:[], phones:[], planRequests:[], tileFailures:0 };
let checks = 0;
const check = (condition,label) => { assert.ok(condition,label); checks++; console.log(`PASS: ${label}`); };

async function observe(page) {
  await page.addInitScript(() => {
    window.__v5Animations=[];
    document.addEventListener('animationstart',event=>{
      if(event.target.closest?.('#mascot-peek')) window.__v5Animations.push({classes:String(event.target.className),name:event.animationName,at:performance.now()});
    },true);
    let library;
    Object.defineProperty(window,'L',{
      configurable:true,
      get() { return library; },
      set(value) {
        library=value;
        if (!value || value.__v5Observed) return;
        value.__v5Observed=true;
        const original=value.map;
        value.map=function(...args) {
          const map=original.apply(this,args);
          window.__v5Map=map;
          return map;
        };
      }
    });
    const start=Date.now();
    const coarse={ latitude:-31.9473, longitude:115.8586, accuracy:220 };
    let current=coarse,currentTimestamp=start;
    let nextWatch=1;
    const watchers=new Map();
    window.__v5Gps={
      requests:[], watches:[], clears:[], start,
      emit(coords,timestamp=Date.now()) {
        if(timestamp>=currentTimestamp){current=coords;currentTimestamp=timestamp;}
        for (const watch of watchers.values()) watch.success({coords,timestamp});
      },
      error(code) { for (const watch of watchers.values()) watch.error?.({code,message:'Synthetic browser GPS condition'}); },
      active() { return watchers.size; }
    };
    Object.defineProperty(navigator,'geolocation',{ configurable:true,value:{
      getCurrentPosition(success,error,options) {
        window.__v5Gps.requests.push(options);
        queueMicrotask(()=>success({coords:current,timestamp:Date.now()}));
      },
      watchPosition(success,error,options) {
        const id=nextWatch++;
        watchers.set(id,{success,error});
        window.__v5Gps.watches.push({id,options});
        return id;
      },
      clearWatch(id) { watchers.delete(id); window.__v5Gps.clears.push(id); }
    }});
  });
  page.on('pageerror',error=>errors.push(error.message));
  page.on('requestfailed',request=>{ if (request.url().includes('tile.openstreetmap.org')) telemetry.tileFailures++; });
  page.on('request',request=>{
    const url=new URL(request.url());
    if (url.pathname.endsWith('/api.php') && url.searchParams.get('action')==='plan') telemetry.planRequests.push(url.href);
  });
  page.on('response',async response=>{
    const url=new URL(response.url());
    if (!url.pathname.endsWith('/api.php')) return;
    const action=url.searchParams.get('action');
    if (!['plan','transfer','phones'].includes(action)) return;
    try {
      const data=await response.json();
      telemetry[`${action==='transfer'?'transfer':action==='plan'?'plan':'phone'}s`].push({data,status:response.status(),url:url.href});
    } catch {}
  });
}

async function newPage(options={}) {
  const context=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true,locale:'en-AU',timezoneId:'Australia/Perth',...options});
  contexts.push(context);
  const page=await context.newPage();
  await observe(page);
  await page.goto(base,{waitUntil:'domcontentloaded'});
  await page.waitForFunction(()=>window.__v5Map && document.querySelector('#open-panel'));
  return page;
}

async function verifyDesktopPicker(width) {
  const page=await newPage({viewport:{width,height:900},isMobile:false,hasTouch:false});
  const before=telemetry.planRequests.length;
  await page.locator('#origin-input').focus();
  await page.locator('#origin-input').press('Enter');
  await page.waitForFunction(()=>document.querySelector('#journey-panel').getAttribute('aria-hidden')==='true' && document.querySelector('#map').classList.contains('map-picking'));
  await page.waitForFunction(()=>!document.querySelector('#journey-panel').contains(document.elementFromPoint(100,300)));
  check(await page.locator('#journey-panel').evaluate(panel=>!panel.contains(document.elementFromPoint(100,300))),`${width} px desktop picker exposes the map beneath the former form area`);
  await page.locator('#use-map-center').click();
  await page.waitForFunction(()=>document.querySelector('#journey-panel').getAttribute('aria-hidden')==='false' && !document.querySelector('#map').classList.contains('map-picking'));
  check(telemetry.planRequests.length===before && await page.locator('#origin-input').inputValue()==='Starting point on map',`${width} px keyboard map selection returns to the form without planning an incomplete trip`);
  await page.locator('#destination-input').click();
  await page.waitForFunction(()=>document.querySelector('#map').classList.contains('map-picking'));
  await page.locator('#cancel-map-pick').click();
  await page.evaluate(()=>document.querySelector('#destination-input').focus());
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  check(await page.locator('#journey-panel').getAttribute('aria-hidden')==='false' && !(await page.locator('#map').getAttribute('class')).includes('map-picking') && telemetry.planRequests.length===before,`${width} px picker cancellation and returning focus do not loop or submit`);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${width} px desktop controls have no horizontal overflow`);
  await page.context().close();
}

async function settleMap(page) {
  await page.waitForFunction(()=>window.__v5Map && !window.__v5Map._animatingZoom && !window.__v5Map._panAnim?._inProgress);
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
}

async function panel(page,open) {
  if (((await page.locator('#journey-panel').getAttribute('aria-hidden'))!=='true')===open) return;
  await page.locator(open?'#open-panel':'#close-panel').click();
  await page.waitForFunction(expected=>(document.querySelector('#journey-panel').getAttribute('aria-hidden')!=='true')===expected,open);
}

async function touchDrag(page,selector,deltaY,startPoint=null) {
  const box=await page.locator(selector).boundingBox();
  assert.ok(box,`${selector} available for actual touch drag`);
  const x=startPoint?.x??box.x+box.width/2,y=startPoint?.y??box.y+box.height/2,endY=Math.max(12,Math.min(830,y+deltaY));
  const client=await page.context().newCDPSession(page);
  try {
    await client.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x,y,id:1}]});
    for(let i=1;i<=6;i++) {
      await client.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x,y:y+(endY-y)*i/6,id:1}]});
      await page.evaluate(()=>new Promise(requestAnimationFrame));
    }
    await client.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
  } finally { await client.detach(); }
}

async function waitGps(page,lat,lon) {
  await page.waitForFunction(([lat,lon])=>{
    let position;
    window.__v5Map.eachLayer(layer=>{if(layer.options?.title==='Your phone location') position=layer.getLatLng();});
    return position && Math.abs(position.lat-lat)<1e-7 && Math.abs(position.lng-lon)<1e-7;
  },[lat,lon]);
}

async function realJson(page,params) {
  const url=new URL('api.php',base);
  for(const [key,value]of Object.entries(params)) url.searchParams.set(key,value);
  const response=await page.request.get(url.href,{timeout:120000});
  const data=await response.json();
  assert.ok(response.ok()&&!data.error,`${params.action} real API: ${data.error||response.status()}`);
  return data;
}

// Place a real coordinate in the clear map area, then send an actual touch tap.
// This changes the map view, never the data returned by an API.
async function chooseMapPoint(page,field,lat,lon) {
  await panel(page,true);
  await page.locator(`#${field}-input`).tap();
  await page.waitForFunction(()=>document.querySelector('#journey-panel').getAttribute('aria-hidden')==='true' && document.querySelector('#map').classList.contains('map-picking'));
  await page.waitForFunction(()=>!document.querySelector('#journey-panel').contains(document.elementFromPoint(innerWidth/2,innerHeight/2)));
  check(await page.locator('#map-hint').isVisible(),`${field} field enters a clear map picker with a visible cancel control`);
  await page.evaluate(([lat,lon])=>{__v5Map.setView([lat,lon],19,{animate:false});},[lat,lon]);
  await settleMap(page);
  const point=await page.evaluate(([lat,lon])=>{
    const p=__v5Map.latLngToContainerPoint([lat,lon]);
    const box=document.querySelector('#map').getBoundingClientRect();
    return{x:box.left+p.x,y:box.top+p.y};
  },[lat,lon]);
  await page.touchscreen.tap(point.x,point.y);
}

async function clickableIndex(page,selector) {
  return page.locator(selector).evaluateAll(elements=>elements.findIndex(element=>{
    const box=element.getBoundingClientRect(),x=box.x+box.width/2,y=box.y+box.height/2;
    const top=document.elementFromPoint(x,y);
    return x>12&&x<innerWidth-65&&y>210&&y<innerHeight-170&&(top===element||element.contains(top));
  }));
}

async function verifyPhones(page) {
  await panel(page,false);
  // Pause journey following through the same real control a user uses before
  // exploring. Calling Leaflet.setView alone deliberately is not a user gesture.
  await page.locator('#map-zoom-out').tap();
  await settleMap(page);
  await page.evaluate(()=>{__v5Map.setView([-31.9523,115.8613],13,{animate:false});});
  await settleMap(page);
  const responsePromise=page.waitForResponse(response=>new URL(response.url()).searchParams.get('action')==='phones',{timeout:45000});
  await page.locator('#map-phones').tap();
  const response=await responsePromise,data=await response.json();
  if(!response.ok() || !data.phones?.length || data.stale) {
    limitations.push(`Actual Telstra phone locations are UNRUN: ${data.error||data.message||'provider returned no current visible sites'}.`);
    await page.locator('#map-phones').tap();
    return;
  }
  check(/Telstra/i.test(data.source) && /Telstra/i.test(data.attribution) && Number.isFinite(Date.parse(data.sourceUpdatedAt)),'phone overlay uses the actual dated Telstra register with attribution');
  const layers='.phone-marker,.phone-cluster';
  await page.locator(layers).first().waitFor({state:'visible'});
  await page.waitForFunction(([selector,count])=>Array.from(document.querySelectorAll(selector)).reduce((sum,element)=>sum+Number(element.dataset.locationCount||0),0)===count,[layers,data.siteCount]);
  const represented=await page.locator(layers).evaluateAll(elements=>({sites:elements.reduce((sum,element)=>sum+Number(element.dataset.locationCount||0),0),phones:elements.reduce((sum,element)=>sum+Number(element.dataset.phoneCount||0),0),markers:elements.length}));
  check(represented.sites===data.siteCount && represented.phones===data.totalPhones && represented.markers<data.siteCount && await page.locator('.phone-cluster').count()>0,'wide-area clustering retains every registered site and physical phone count');
  const index=await clickableIndex(page,'.phone-cluster');
  assert.ok(index>=0,'a real visible phone cluster is clear of controls');
  const cluster=page.locator('.phone-cluster').nth(index);
  check(await cluster.getAttribute('tabindex')==='0','phone clusters remain keyboard accessible');
  const refresh=page.waitForResponse(response=>new URL(response.url()).searchParams.get('action')==='phones' && response.ok(),{timeout:30000});
  await cluster.tap();
  await refresh;
  await settleMap(page);
  if(await page.evaluate(()=>__v5Map.getZoom())<16) {
    const fineRefresh=page.waitForResponse(response=>new URL(response.url()).searchParams.get('action')==='phones' && response.ok(),{timeout:30000});
    await page.evaluate(()=>{__v5Map.setZoom(16,{animate:false});});
    await fineRefresh;
  }
  await settleMap(page);
  await page.locator('.phone-marker').first().waitFor({state:'visible'});
  check(await page.locator('.phone-cluster').count()===0,'street-level zoom reveals individual phone sites');
  const sizes=await page.locator('.phone-marker').evaluateAll(elements=>elements.map(element=>({
    hit:element.getBoundingClientRect().width,
    visible:element.querySelector('.phone-marker-body').getBoundingClientRect().width,
    count:element.dataset.locationCount,
    keyboard:element.tabIndex
  })));
  check(sizes.every(size=>size.hit>=44 && size.visible>=32 && size.visible<=44 && size.count==='1' && size.keyboard===0),'larger phone artwork has at least a 44 px accessible hit target without merging distinct sites');
  const phoneIndex=await clickableIndex(page,'.phone-marker');
  assert.ok(phoneIndex>=0,'a real individual phone is clear of controls');
  await page.locator('.phone-marker').nth(phoneIndex).tap();
  await page.locator('.phone-popup').waitFor({state:'visible'});
  check(/Telstra/i.test(await page.locator('.phone-popup').textContent()),'larger phone pin opens the actual registered location inside the map');
  await page.evaluate(()=>{__v5Map.closePopup();});
  await page.locator('#map-phones').tap();
  check(await page.locator(layers).count()===0 && await page.locator('#map-phones').getAttribute('aria-pressed')==='false','turning phones off clears both individual and clustered phone overlays');
}

async function verifyTransfer(page,planned) {
  const rides=planned.journeys[0].legs.filter(leg=>leg.mode!=='walk');
  if(rides.length<2) {
    limitations.push('The actual selected journey had no transfer. Station transfer UI and automatic arrival remain UNRUN.');
    return;
  }
  const previous=rides[0],next=rides[1],from=previous.to,to=next.from;
  const selected=await page.locator('.journey-option[aria-pressed="true"]').getAttribute('data-journey');
  const eligible=Date.now()>=Date.parse(previous.arrival)-180000 && Date.now()<=Date.parse(next.departure)+90000;
  let controlledClock=false;
  try {
  await page.evaluate(point=>__v5Gps.emit({latitude:point.lat,longitude:point.lon,accuracy:220},Date.now()),from);
  await waitGps(page,from.lat,from.lon);
  await page.waitForTimeout(150);
  check(!(await page.locator('#station-guidance').isVisible()),'a coarse GPS fix at a transfer does not claim arrival at the correct stop');
  if(!eligible) {
    await page.evaluate(point=>__v5Gps.emit({latitude:point.lat,longitude:point.lon,accuracy:8},Date.now()),from);
    await waitGps(page,from.lat,from.lon);
    await page.waitForTimeout(200);
    check(!(await page.locator('#station-guidance').isVisible()),'GPS proximity outside the real scheduled transfer window does not open a premature station view');
    await page.clock.setSystemTime(new Date(previous.arrival));
    controlledClock=true;
    simulations.push(`Browser Date was set to actual selected transfer arrival ${previous.arrival} for arrival activation only, then restored. Timetable and transfer APIs stayed live; no physical journey is claimed.`);
  }
  const guideResponse=page.waitForResponse(response=>new URL(response.url()).searchParams.get('action')==='transfer',{timeout:45000});
  await page.evaluate(point=>__v5Gps.emit({latitude:point.lat,longitude:point.lon,accuracy:8},Date.now()),from);
  await waitGps(page,from.lat,from.lon);
  await page.locator('#station-guidance').waitFor({state:'visible'});
  check(true,`accurate GPS near the real scheduled transfer opens station guidance automatically${controlledClock?' with explicitly simulated browser arrival time':''}`);
  const response=await guideResponse,data=await response.json();
  check(response.ok() && data.from?.id===from.id && data.to?.id===to.id,'station guidance loads the actual alighting and next boarding stop IDs');
  if(data.routing!=='street' || !data.geometry?.length) {
    limitations.push(`Actual transfer footpath animation is UNRUN: ${data.message||data.routing||data.error||'provider unavailable'}.`);
  } else {
    await page.locator('.guidance-trail-dot').first().waitFor({state:'attached'});
    check(data.geometry.length>2 && data.distance>0 && data.duration>0 && /OpenStreetMap/i.test(data.routingSource),'transfer uses a real mapped pedestrian path, distance and duration');
    check(await page.locator('.guidance-stop-target').count()===1 && (await page.locator('#guidance-target-name').textContent()).includes(to.name),'only the exact next boarding platform or sign receives the target marker');
    check(await page.locator('.guidance-stop-context').count()>0 && await page.locator('.guidance-stop-context').evaluateAll(elements=>elements.every(element=>getComputedStyle(element).animationName==='none')),'nearby stop markers remain quiet around the boarding target');
    const trail=await page.locator('.guidance-trail-dot').evaluateAll(elements=>elements.map(element=>({animation:getComputedStyle(element).animationName,delay:parseFloat(element.style.animationDelay)||0})));
    check(trail.length>2 && trail.every((dot,index)=>dot.animation!=='none' && (!index || dot.delay>trail[index-1].delay)),'path dots reveal in order from alighting point toward the next boarding point');
    const actualDots=await page.evaluate(()=>{
      const points=[];
      __v5Map.eachLayer(layer=>{if(layer.options?.className==='guidance-trail-dot'){const p=layer.getLatLng();points.push([p.lat,p.lng]);}});
      return points;
    });
    check(actualDots.length===trail.length && actualDots.every(point=>data.geometry.some((b,index)=>{
      if(!index)return false;
      const a=data.geometry[index-1],scale=Math.cos(point[0]*Math.PI/180),dx=(b[1]-a[1])*scale,dy=b[0]-a[0];
      const ratio=Math.max(0,Math.min(1,((point[1]-a[1])*scale*dx+(point[0]-a[0])*dy)/(dx*dx+dy*dy||1)));
      return Math.hypot((point[1]-a[1]-(b[1]-a[1])*ratio)*scale,point[0]-a[0]-(b[0]-a[0])*ratio)*111320<.5;
    })),'progressive dots stay on the actual provider footpath rather than an endpoint chord');
    check(await page.locator('.guidance-target-ring').evaluate(element=>getComputedStyle(element).animationName)!=='none','the required boarding stop flashes clearly while guidance is active');
    await page.emulateMedia({reducedMotion:'reduce'});
    check(await page.locator('.guidance-target-ring,.guidance-trail-dot').evaluateAll(elements=>elements.every(element=>getComputedStyle(element).animationName==='none')),'reduced motion leaves the labelled boarding target and complete path static');
    await page.emulateMedia({reducedMotion:'no-preference'});
  }
  await page.evaluate(point=>__v5Gps.emit({latitude:point.lat+.00001,longitude:point.lon+.00001,accuracy:7},Date.now()),from);
  await waitGps(page,from.lat+.00001,from.lon+.00001);
  check(await page.evaluate(()=>__v5Gps.active()>0),'GPS keeps updating the phone marker while station guidance is open');
  await settleMap(page);
  const panStart=await page.evaluate(()=>{
    for(const y of[340,400,480])for(const x of[65,110,195,260]){
      const element=document.elementFromPoint(x,y);
      if(document.querySelector('#map').contains(element)&&!element.closest('.leaflet-marker-icon'))return{x,y};
    }
    return null;
  });
  assert.ok(panStart,'station map has a clear area for an actual touch pan');
  await touchDrag(page,'#map',150,panStart);
  await settleMap(page);
  const exploredCenter=await page.evaluate(()=>{const p=__v5Map.getCenter();return[p.lat,p.lng];});
  await page.evaluate(point=>__v5Gps.emit({latitude:point.lat+.00002,longitude:point.lon+.00002,accuracy:6},Date.now()),from);
  await waitGps(page,from.lat+.00002,from.lon+.00002);
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  const drift=await page.evaluate(point=>__v5Map.distance(point,__v5Map.getCenter()),exploredCenter);
  check(drift<1,'manual station-map dragging pauses guidance following while GPS still updates');
  await page.locator('#map-locate').tap();
  await settleMap(page);
  const recenterDistance=await page.evaluate(point=>__v5Map.distance(point,__v5Map.getCenter()),exploredCenter);
  check(recenterDistance>20 && await page.locator('#station-guidance').isVisible() && await page.locator('.journey-option[aria-pressed="true"]').getAttribute('data-journey')===selected,'GPS recenter resumes the station view without switching to the full journey');
  await page.waitForTimeout(400);
  await page.screenshot({path:'/tmp/nextstop-v5-transfer.png',fullPage:true});
  await page.locator('#exit-guidance').tap();
  check(!(await page.locator('#station-guidance').isVisible()) && await page.locator('.guidance-trail-dot,.guidance-stop-target').count()===0 && await page.locator('.journey-option[aria-pressed="true"]').getAttribute('data-journey')===selected,'closing guidance restores the original selected journey and clears station overlays');
  await page.evaluate(point=>__v5Gps.emit({latitude:point.lat,longitude:point.lon,accuracy:6},Date.now()),from);
  await waitGps(page,from.lat,from.lon);
  await page.waitForTimeout(150);
  check(!(await page.locator('#station-guidance').isVisible()),'the same arrival fix cannot reopen dismissed guidance');
  await panel(page,true);
  const manualResponse=page.waitForResponse(response=>new URL(response.url()).searchParams.get('action')==='transfer',{timeout:45000});
  await page.locator('.transfer-guidance-button,.transfer-station-button').first().tap();
  await manualResponse;
  check(await page.locator('#station-guidance').isVisible(),'the itinerary transfer can still be reopened manually after dismissal');
  await page.locator('#exit-guidance').tap();
  } finally {
    if(controlledClock) await page.clock.setSystemTime(new Date());
  }
}

try {
  const page=await newPage();
  await page.locator('#mascot-peek').waitFor({state:'visible'});
  check(await page.locator('#journey-panel').getAttribute('aria-hidden')==='true','mobile loads with the search sheet collapsed');
  const catBox=await page.locator('#mascot-peek').boundingBox();
  const launcherBox=await page.locator('#open-panel').boundingBox();
  check(catBox && catBox.width<=120 && catBox.height<=110 && catBox.y<launcherBox.y,'peeking cat stays compact above the bottom search bar');
  const mascotAssets=await page.locator('#mascot-peek').evaluate(async cat=>{
    const head=cat.querySelector('img'),background=getComputedStyle(cat.querySelector('.mascot-paw-left')).backgroundImage;
    const url=background.match(/url\(["']?(.*?)["']?\)/)?.[1];
    if(!url)return{head:head.complete&&head.naturalWidth>0,paws:false};
    const image=new Image();image.src=url;
    try{await Promise.all([head.decode(),image.decode()]);return{head:head.complete&&head.naturalWidth>0,paws:image.naturalWidth>0};}catch{return{head:false,paws:false};}
  });
  check(mascotAssets.head&&mascotAssets.paws,'cat head and matching paw artwork both load successfully');
  await page.waitForFunction(()=>['mascot-peek-head','mascot-paw-left','mascot-paw-right'].every(name=>__v5Animations.some(event=>event.classes.includes(name))));
  const mascotMotion=await page.evaluate(()=>Object.fromEntries(['mascot-peek-head','mascot-paw-left','mascot-paw-right'].map(name=>[name,__v5Animations.find(event=>event.classes.includes(name)).at])));
  check(mascotMotion['mascot-peek-head']<mascotMotion['mascot-paw-left'] && mascotMotion['mascot-paw-left']<=mascotMotion['mascot-paw-right'],'cat head enters before the paws land');
  await page.emulateMedia({reducedMotion:'reduce'});
  check(await page.locator('.mascot-peek-head,.mascot-paw-left,.mascot-paw-right').evaluateAll(elements=>elements.every(element=>getComputedStyle(element).animationName==='none')),'reduced motion keeps the cat static');
  await page.emulateMedia({reducedMotion:'no-preference'});
  await page.waitForTimeout(900);
  await page.screenshot({path:'/tmp/nextstop-v5-mobile.png',fullPage:true});
  const beforeCatPlans=telemetry.planRequests.length;
  await page.locator('#mascot-peek').tap();
  await page.waitForFunction(()=>{
    const cat=document.querySelector('#mascot-peek'),s=getComputedStyle(cat);
    return cat.hidden || s.display==='none' || s.visibility==='hidden' || Number(s.opacity)===0;
  });
  check(await page.locator('#journey-panel').getAttribute('aria-hidden')==='true' && !(await page.locator('#map').getAttribute('class')).includes('map-picking') && telemetry.planRequests.length===beforeCatPlans,'tapping the cat hides it without opening the sheet, choosing a map point or planning');
  await touchDrag(page,'#open-panel',-180);
  await page.waitForFunction(()=>document.querySelector('#journey-panel').getAttribute('aria-hidden')==='false');
  check(true,'upward touch drag still opens the mobile sheet');
  await touchDrag(page,'#sheet-handle',180);
  await page.waitForFunction(()=>document.querySelector('#journey-panel').getAttribute('aria-hidden')==='true');
  check(true,'downward handle drag still closes the sheet');

  await panel(page,true);
  await page.locator('#origin-input').tap();
  await page.waitForFunction(()=>document.querySelector('#journey-panel').getAttribute('aria-hidden')==='true' && document.querySelector('#map').classList.contains('map-picking'));
  await page.locator('#cancel-map-pick').tap();
  await page.waitForFunction(()=>document.querySelector('#journey-panel').getAttribute('aria-hidden')==='false' && !document.querySelector('#map').classList.contains('map-picking'));
  await page.evaluate(()=>document.querySelector('#origin-input').focus());
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  check(await page.locator('#journey-panel').getAttribute('aria-hidden')==='false' && !(await page.locator('#map').getAttribute('class')).includes('map-picking'),'returning keyboard focus to a field does not reopen the map picker');
  await page.locator('.location-search[data-search="origin"]').tap();
  check(await page.locator('#origin-input').evaluate(input=>!input.readOnly && document.activeElement===input),'explicit address-search control enables keyboard address entry');
  check(await page.locator('#journey-panel').getAttribute('aria-hidden')==='false','address entry keeps the form visible');

  await page.locator('#use-location').tap();
  await waitGps(page,-31.9473,115.8586);
  await page.waitForFunction(()=>__v5Gps.active()>0);
  const gpsOptions=await page.evaluate(()=>({requests:__v5Gps.requests,watches:__v5Gps.watches}));
  check(gpsOptions.requests[0]?.enableHighAccuracy && gpsOptions.requests[0]?.maximumAge===0 && gpsOptions.watches[0]?.options.enableHighAccuracy,'fresh high-accuracy GPS continues watching before any journey is planned');
  await page.evaluate(()=>__v5Gps.emit({latitude:-31.9455,longitude:115.8586,accuracy:8},Date.now()));
  await waitGps(page,-31.9455,115.8586);
  check(true,'later accurate browser GPS replaces the coarse initial fix');
  await page.evaluate(()=>__v5Gps.error(2));
  check(await page.evaluate(()=>__v5Gps.active()>0),'a temporary GPS unavailable condition retains the active watch');

  const beforeOriginPlans=telemetry.planRequests.length;
  await chooseMapPoint(page,'origin',-32.0126030198,115.848987625);
  await page.waitForFunction(()=>document.querySelector('#journey-panel').getAttribute('aria-hidden')==='false' && !document.querySelector('#map').classList.contains('map-picking'));
  await page.waitForTimeout(500);
  check(telemetry.planRequests.length===beforeOriginPlans && (await page.locator('#destination-input').inputValue())==='','selecting a start point returns to the form without submitting an empty destination');
  await page.evaluate(()=>document.querySelector('#origin-input').focus());
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  check(await page.locator('#journey-panel').getAttribute('aria-hidden')==='false','programmatic focus after a map choice keeps the form open');
  await page.evaluate(()=>__v5Gps.emit({latitude:-32.0126030198,longitude:115.848987625,accuracy:8},Date.now()));
  await waitGps(page,-32.0126030198,115.848987625);

  if(process.env.CORE_ONLY==='1') {
    limitations.push('Real planning, autocomplete result selection, transfer routes, arrival guidance and Telstra data are UNRUN in CORE_ONLY mode.');
  } else {
    const planResponse=page.waitForResponse(response=>new URL(response.url()).searchParams.get('action')==='plan',{timeout:120000});
    const beforeDestinationPlans=telemetry.planRequests.length;
    await chooseMapPoint(page,'destination',-32.527317777778,115.74678888889);
    const planned=await(await planResponse).json();
    await page.waitForTimeout(800);
    check(telemetry.planRequests.length===beforeDestinationPlans+1,'two valid map endpoints start exactly one real route request without pressing Find ride');
    if(!planned.journeys?.length) {
      limitations.push(`Real walking provider did not yield a safe journey: ${planned.message||planned.error||'no route'}. Transfer UI/GPS-arrival checks remain UNRUN.`);
      check(await page.locator('#journey-panel').getAttribute('aria-hidden')==='false','a failed real route leaves the form available to retry');
    } else {
      await page.locator('.journey-option[aria-pressed="true"]').waitFor({state:'attached',timeout:30000});
      await page.waitForFunction(()=>document.querySelector('#journey-panel').getAttribute('aria-hidden')==='true');
      check(planned.mode==='timetable' && planned.journeys.some(journey=>journey.legs.some(leg=>leg.mode!=='walk')),'automatic planning returns an actual Perth timetable journey');
      const walks=planned.journeys[0].legs.filter(leg=>leg.mode==='walk');
      check(walks.length>0 && walks.every(leg=>leg.routing==='street' && leg.geometry?.length>=2 && leg.routingSource),'offered walking connections carry verified street-route geometry and attribution');
      const selectedJourney=await page.locator('.journey-option[aria-pressed="true"]').getAttribute('data-journey');
      await page.evaluate(()=>__v5Gps.error(2));
      await page.evaluate(()=>__v5Gps.emit({latitude:-32.0125930198,longitude:115.848997625,accuracy:7},Date.now()));
      await waitGps(page,-32.0125930198,115.848997625);
      check(await page.evaluate(()=>__v5Gps.active()>0) && await page.locator('.journey-option[aria-pressed="true"]').getAttribute('data-journey')===selectedJourney,'transient GPS errors and subsequent fixes update the marker while retaining the journey');
      await verifyTransfer(page,planned);
    }
    await verifyPhones(page);
  }
  await verifyDesktopPicker(1024);
  await verifyDesktopPicker(1366);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile layout has no horizontal overflow');
  check(errors.length===0,`mobile flows have no JavaScript exceptions (${errors.join('; ')})`);
  console.log(`\n${checks} mobile v5 checks passed.`);
  for(const limitation of limitations) console.log(`LIMITATION: ${limitation}`);
  for(const simulation of simulations) console.log(`SIMULATION: ${simulation}`);
  console.log(`Browser GPS was synthetic; any browser Date simulation is identified above. Real transport/walking/provider checks are identified above. Street-map TLS failures: ${telemetry.tileFailures}; basemap verification and TLS bypass are not claimed.`);
} finally {
  for(const context of contexts) await context.close();
  await browser.close();
}
