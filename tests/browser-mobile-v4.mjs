/**
 * Mobile v4 regression against the real running Nextstop API.
 * Only navigator.geolocation is simulated to reproduce a coarse -> fine fix.
 * No transport, walking, approach, or payphone response is mocked.
 * Run: node tests/browser-mobile-v4.mjs (optional BASE_URL / CHROMIUM_BINARY).
 * CORE_ONLY=1 checks only independent mobile UI/GPS controls.
 * SKIP_PHONES=1 permits explicit unavailable-provider error checks; it never
 * treats those checks as verification of real public-phone positions.
 */
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const base = process.env.BASE_URL || 'http://127.0.0.1:8080/';
const executablePath = process.env.CHROMIUM_BINARY || (existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined);
const browser = await chromium.launch({ headless: true, executablePath });
let checks = 0;
const check = (condition, label) => { assert.ok(condition, label); checks++; console.log(`PASS: ${label}`); };
const contexts = [];
const errors = [];
const telemetry = { plans: [], approaches: [], phones: [], tileFailures: 0 };

async function observe(page) {
  await page.addInitScript(() => {
    let library;
    Object.defineProperty(window, 'L', {
      configurable: true,
      get() { return library; },
      set(value) {
        library = value;
        if (!value || value.__v4Observed) return;
        value.__v4Observed = true;
        const original = value.map;
        value.map = function (...args) {
          const map = original.apply(this, args);
          window.__v4Map = map;
          return map;
        };
      }
    });
    const start = Date.now();
    const coarse = { latitude: -31.9473, longitude: 115.8586, accuracy: 220 };
    let nextWatch = 1;
    const watchers = new Map();
    window.__v4Gps = {
      requests: [], watches: [], clears: [], start,
      emit(coords, timestamp = Date.now()) {
        for (const watch of watchers.values()) watch.success({ coords, timestamp });
      },
      error(code) { for (const watch of watchers.values()) watch.error?.({ code, message: 'Synthetic GPS condition' }); },
      active() { return watchers.size; }
    };
    Object.defineProperty(navigator, 'geolocation', { configurable: true, value: {
      getCurrentPosition(success, error, options) {
        window.__v4Gps.requests.push(options);
        queueMicrotask(() => success({ coords: coarse, timestamp: start }));
      },
      watchPosition(success, error, options) {
        const id = nextWatch++;
        watchers.set(id, { success, error });
        window.__v4Gps.watches.push({ id, options });
        return id;
      },
      clearWatch(id) { watchers.delete(id); window.__v4Gps.clears.push(id); }
    } });
  });
  page.on('pageerror', error => errors.push(error.message));
  page.on('requestfailed', request => { if (request.url().includes('tile.openstreetmap.org')) telemetry.tileFailures++; });
  page.on('response', async response => {
    const url = new URL(response.url());
    if (!url.pathname.endsWith('/api.php')) return;
    const action = url.searchParams.get('action');
    if (!['plan', 'approach', 'phones'].includes(action)) return;
    try {
      const data = await response.json();
      if (action === 'plan') telemetry.plans.push(data);
      if (action === 'approach') telemetry.approaches.push(data);
      if (action === 'phones') telemetry.phones.push(data);
    } catch {}
  });
}

async function panel(page, open) {
  const actual = await page.locator('#journey-panel').getAttribute('aria-hidden');
  if ((actual !== 'true') === open) return;
  await page.locator(open ? '#open-panel' : '#close-panel').click();
  await page.waitForFunction(expected => (document.querySelector('#journey-panel').getAttribute('aria-hidden') !== 'true') === expected, open);
}

async function touchDrag(page, selector, deltaY) {
  const box = await page.locator(selector).boundingBox();
  assert.ok(box, `${selector} available for actual touch drag`);
  const x = box.x + box.width / 2;
  const y = box.y + box.height / 2;
  const endY = Math.max(12, Math.min(830, y + deltaY));
  const client = await page.context().newCDPSession(page);
  try {
    await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y, id: 1 }] });
    for (let i = 1; i <= 6; i++) {
      await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y: y + (endY-y)*i/6, id: 1 }] });
      await page.evaluate(() => new Promise(requestAnimationFrame));
    }
    await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  } finally { await client.detach(); }
}

async function gpsPoint(page) {
  return page.evaluate(() => {
    let result;
    window.__v4Map.eachLayer(layer => {
      if (layer.options?.title === 'Your phone location') {
        const p = layer.getLatLng(); result = { lat: p.lat, lon: p.lng };
      }
    });
    return result;
  });
}

async function waitGps(page, lat, lon) {
  await page.waitForFunction(([lat,lon]) => {
    let result;
    window.__v4Map.eachLayer(layer => { if (layer.options?.title === 'Your phone location') result = layer.getLatLng(); });
    return result && Math.abs(result.lat-lat)<1e-7 && Math.abs(result.lng-lon)<1e-7;
  }, [lat,lon]);
}

async function getJson(page, params) {
  const url = new URL('api.php', base);
  for (const [key,value] of Object.entries(params)) url.searchParams.set(key,value);
  const response = await page.request.get(url.href);
  const data = await response.json();
  assert.ok(response.ok() && !data.error, `${params.action} real API: ${data.error || response.status()}`);
  return data;
}

async function planTrip(page) {
  await panel(page,true);
  await page.locator('#destination-input').fill('Claremont');
  await page.locator('#destination-input').press('Enter');
  await page.locator('.search-result').first().waitFor({ state:'visible', timeout:30000 });
  await page.locator('.search-result').first().click();
  const response = page.waitForResponse(r => new URL(r.url()).searchParams.get('action')==='plan' && r.ok(),{timeout:60000});
  await page.locator('#plan-button').click();
  const data = await (await response).json();
  await page.locator('.journey-option[aria-pressed="true"]').waitFor({ state:'attached', timeout:60000 });
  await page.waitForFunction(() => document.querySelector('#journey-panel').getAttribute('aria-hidden')==='true');
  await page.locator('.vehicle-body.is-next').waitFor({ state:'visible', timeout:30000 });
  await page.waitForFunction(() => !window.__v4Map._animatingZoom && !window.__v4Map._panAnim?._inProgress);
  return data;
}

try {
  const context = await browser.newContext({ viewport:{ width:390,height:844 }, isMobile:true, hasTouch:true, locale:'en-AU', timezoneId:'Australia/Perth' });
  contexts.push(context);
  const page = await context.newPage();
  await observe(page);
  await page.goto(base,{ waitUntil:'domcontentloaded' });
  await page.waitForFunction(() => window.__v4Map && document.querySelectorAll('.vehicle-marker').length>0);
  check(await page.locator('#journey-panel').getAttribute('aria-hidden')==='true', 'phone starts with a compact collapsed search panel');
  await touchDrag(page,'#open-panel',-180);
  await page.waitForFunction(() => document.querySelector('#journey-panel').getAttribute('aria-hidden')==='false');
  check(true,'an upward touch drag opens the mobile sheet');
  check(!(await page.locator('.network-card').isVisible()),'mobile activity summary hides while the search sheet is open');
  check(!(await page.locator('.panel-intro').isVisible()),'mobile sheet hides the large slogan and introductory notes');
  await touchDrag(page,'#sheet-handle',180);
  await page.waitForFunction(() => document.querySelector('#journey-panel').getAttribute('aria-hidden')==='true');
  check(true,'pulling the sheet handle down dismisses it without a map tap');
  await panel(page,true);
  await page.locator('#use-location').click();
  await waitGps(page,-31.9473,115.8586);
  await page.waitForFunction(() => window.__v4Gps.active()>0);
  const gpsOptions = await page.evaluate(() => ({ requests:__v4Gps.requests,watches:__v4Gps.watches }));
  check(gpsOptions.requests[0]?.enableHighAccuracy===true && gpsOptions.requests[0]?.maximumAge===0,'initial GPS requests a fresh high-accuracy fix');
  check(gpsOptions.watches[0]?.options.enableHighAccuracy===true && gpsOptions.watches[0]?.options.maximumAge===0,'GPS continues refining before any journey exists');
  await page.evaluate(() => __v4Gps.emit({ latitude:-31.9455,longitude:115.8586,accuracy:8 },Date.now()+1000));
  await waitGps(page,-31.9455,115.8586);
  check(true,'a later fine GPS fix replaces the initial approximately200metre fix');
  const origin = await page.locator('#origin-input').inputValue();
  check(origin==='Your location','refined GPS retains the chosen location-based origin');
  await page.evaluate(() => __v4Gps.error(2));
  check(await page.evaluate(() => __v4Gps.active()>0),'temporary position-unavailable errors do not stop refinement');
  await page.evaluate(() => __v4Gps.emit({ latitude:-31.94549,longitude:115.85861,accuracy:7 },Date.now()+2000));
  await waitGps(page,-31.94549,115.85861);
  const fine = await gpsPoint(page);
  await page.evaluate(() => __v4Gps.emit({ latitude:-31.9473,longitude:115.8586,accuracy:220 },__v4Gps.start-1000));
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  const afterStale = await gpsPoint(page);
  check(Math.abs(afterStale.lat-fine.lat)<1e-7 && Math.abs(afterStale.lon-fine.lon)<1e-7,'an older cached coarse fix cannot overwrite the newer accurate position');
  check((await page.locator('#gps-status').textContent()).includes('7'),'compact GPS status reflects the refined accuracy rather than the coarse fix');

  if (process.env.CORE_ONLY==='1') {
    const controls=await page.locator('#map-phones').boundingBox();
    const plus=await page.locator('#map-zoom-in').boundingBox();
    check(controls.y<plus.y,'public-phone control is above the zoom buttons');
    check(errors.length===0,'independent mobile controls and GPS flows have no JavaScript exceptions');
    await panel(page,false);
    const card=await page.locator('.network-card').boundingBox();
    const launcher=await page.locator('#open-panel').boundingBox();
    check(card && card.y+card.height<launcher.y && launcher.y-(card.y+card.height)<35,'mobile activity summary sits immediately above the collapsed search launcher');
    await page.waitForFunction(()=>!window.__v4Map._animatingZoom&&!window.__v4Map._panAnim?._inProgress);
    await page.screenshot({path:'/tmp/nextstop-v4-mobile-core.png',fullPage:true});
    console.log(`\n${checks} independent mobile UI/GPS checks passed. Street walking, departure timing, approach animation, and real phone data remain UNRUN pending their network prerequisites.`);
  } else {
  const plan = await planTrip(page);
  const planned = plan.journeys?.[0];
  assert.ok(planned,'real plan response observed');
  const ride = planned.legs.find(leg=>leg.mode!=='walk');
  check(Boolean(ride),'the street-walking test journey contains a real bus or train');
  check(await page.evaluate(()=>__v4Gps.active()>0),'location refinement continues after the journey is selected');
  const walks = planned.legs.filter(leg=>leg.mode==='walk');
  check(walks.length>0 && walks.every(leg=>leg.routing==='street' && typeof leg.routingSource==='string' && leg.routingSource.length>0),'walking legs carry an explicit street-routing source');
  check(walks.some(leg=>leg.geometry?.length>2),'at least one walking section follows mapped street/path vertices rather than an endpoint chord');
  check(walks.every(leg=>Number(leg.distance)>0 && Number(leg.duration)>0),'street walking includes real network distance and duration');
  check(Number.isFinite(Date.parse(planned.leaveAt)) && planned.walkToBoardSeconds>0,'journey exposes a leave-home time based on walking to the boarding stop');
  const board = Date.parse(planned.boardingAt || ride.departure);
  const margin = Number(planned.boardingBufferSeconds ?? 120);
  check(Math.abs(Date.parse(planned.leaveAt)-(board-(planned.walkToBoardSeconds+margin)*1000))<1500,'leave-home time subtracts street-walking time and boarding buffer');
  const activity=await page.locator('.network-card').boundingBox();
  const launcher=await page.locator('#open-panel').boundingBox();
  check(activity && activity.y+activity.height<launcher.y && launcher.y-(activity.y+activity.height)<35,'journey activity summary stays above the collapsed search launcher');
  await page.locator('#leave-widget').waitFor({ state:'visible' });
  const leaveBox=await page.locator('#leave-widget').boundingBox();
  check(leaveBox.x<180 && leaveBox.y<260,'leave-home timing is visible near the top-left while the phone menu is collapsed');
  await panel(page,true);
  check(!(await page.locator('#message').isVisible()),'successful planning does not add a verbose instruction block beneath the route button');
  check(!(await page.locator('.next-footnote').isVisible()),'mobile next-service card omits the long explanatory footnote');
  await panel(page,false);

  const approach=await getJson(page,{ action:'approach',trip:ride.tripId,stop:ride.from.id,date:ride.serviceDate,boarding:ride.departure });
  check(Number.isFinite(approach.secondsToStop) && approach.secondsToStop>=0 && Number.isFinite(Date.parse(approach.arrival)),'selected service exposes its scheduled arrival and time to the boarding stop');
  if (approach.phase==='approaching') {
  check(approach.geometry?.length>=2,'an actual approaching service supplies its current-to-boarding path');
  await page.waitForFunction(()=>document.querySelector('.incoming-route-pulse'));
  const incoming=await page.locator('.incoming-route-pulse').first().evaluate(line=>({ stroke:line.getAttribute('stroke'),animation:getComputedStyle(line).animationName }));
  check(incoming.stroke?.toUpperCase()===ride.routeColor.toUpperCase() && incoming.animation!=='none','incoming route pulses in the selected network line colour');
  await page.emulateMedia({ reducedMotion:'reduce' });
  check(await page.locator('.incoming-route-pulse').first().evaluate(line=>getComputedStyle(line).animationName)==='none','reduced-motion preference leaves a static approach line');
  await page.emulateMedia({ reducedMotion:'no-preference' });
  } else {
    check(await page.locator('.incoming-route-pulse').count()===0,'a service that is not approaching does not show a misleading pulsing path');
    console.log('LIMITATION: selected actual service phase is '+approach.phase+'; a live approaching-line pulse was not exercised.');
  }

  const controls=await page.locator('#map-phones').boundingBox();
  const plus=await page.locator('#map-zoom-in').boundingBox();
  check(controls.y<plus.y,'public-phone control is above the zoom buttons');
  const phoneJourney=await page.locator('.journey-option[aria-pressed="true"]').getAttribute('data-journey');
  const phoneResponse=page.waitForResponse(response=>new URL(response.url()).searchParams.get('action')==='phones',{ timeout:45000 });
  await page.locator('#map-phones').click();
  const phoneHttp=await phoneResponse;
  const phones=await phoneHttp.json();
  const unavailablePhones=!phoneHttp.ok() || (phones.updatedAt===null && phones.stale===true);
  if (process.env.SKIP_PHONES==='1' && unavailablePhones) {
    await page.locator('#map-toast').waitFor({state:'visible'});
    check((await page.locator('#map-toast').textContent()).includes('Retry'),'unavailable phone provider shows an actionable retry');
    check(await page.locator('.phone-marker').count()===0,'failed phone download does not invent locations');
    await page.locator('#map-phones').click();
    console.log('LIMITATION: real phone positions remain UNRUN because no verified provider dataset is available.');
  } else {
  check(phoneHttp.ok(),'actual phone API is reachable');
  check(await page.locator('#map-phones').getAttribute('aria-pressed')==='true','public-phone control exposes its enabled state');
  check(Array.isArray(phones.phones) && phones.phones.length>0 && /Telstra/i.test(phones.source) && /Telstra/i.test(phones.attribution) && Number.isFinite(Date.parse(phones.sourceUpdatedAt)) && phones.stale===false,'public phone locations come from the actual current Telstra register with attribution');
  check(phones.siteCount===phones.phones.length && phones.totalPhones===phones.phones.reduce((sum,site)=>sum+Number(site.phoneCount||1),0),'returned register sites and individual phone counts remain distinct and consistent');
  let displayedPhones=phones;
  const layerSelector='.phone-marker, .phone-cluster';
  await page.locator(layerSelector).first().waitFor({state:'visible',timeout:30000});
  const represented=await page.locator(layerSelector).evaluateAll(elements=>({
    locations:elements.reduce((sum,element)=>sum+Number(element.dataset.locationCount||0),0),
    phones:elements.reduce((sum,element)=>sum+Number(element.dataset.phoneCount||0),0),
    markers:elements.length
  }));
  check(represented.locations===phones.siteCount && represented.phones===phones.totalPhones,'cluster counts preserve every registered location and individual phone');
  check(await page.locator('.phone-cluster').count()>0 && represented.markers<phones.siteCount,'wide journey view groups nearby phones instead of covering the route with every pin');
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  await page.screenshot({path:'/tmp/nextstop-v4-phone-clusters.png',fullPage:true});
  const clickableIndex=async(selector)=>page.locator(selector).evaluateAll(elements=>elements.findIndex(element=>{
    const box=element.getBoundingClientRect(),x=box.x+box.width/2,y=box.y+box.height/2;
    const top=document.elementFromPoint(x,y);
    return x>12&&x<innerWidth-65&&y>220&&y<innerHeight-160&&(top===element||element.contains(top));
  }));
  const clusterIndex=await clickableIndex('.phone-cluster');
  assert.ok(clusterIndex>=0,'a visible cluster is clear of the mobile controls');
  check(await page.locator('.phone-cluster').nth(clusterIndex).getAttribute('tabindex')==='0','phone groups retain keyboard focus as well as touch activation');
  const previousZoom=await page.evaluate(()=>__v4Map.getZoom());
  let refresh=page.waitForResponse(response=>new URL(response.url()).searchParams.get('action')==='phones'&&response.ok(),{timeout:15000});
  await page.locator('.phone-cluster').nth(clusterIndex).tap();
  displayedPhones=await(await refresh).json();
  await page.waitForFunction(zoom=>__v4Map.getZoom()>zoom&&!__v4Map._animatingZoom&&!__v4Map._panAnim?._inProgress,previousZoom);
  check(await page.locator('#follow-journey').getAttribute('aria-pressed')==='false','opening a phone cluster pauses journey following so the view can be explored');
  check(await page.locator('.journey-option[aria-pressed="true"]').getAttribute('data-journey')===phoneJourney,'cluster zoom preserves the selected journey');
  if (await page.evaluate(()=>__v4Map.getZoom())<16) {
    refresh=page.waitForResponse(response=>new URL(response.url()).searchParams.get('action')==='phones'&&response.ok(),{timeout:15000});
    await page.evaluate(()=>__v4Map.setZoom(16,{animate:false}));
    displayedPhones=await(await refresh).json();
  }
  await page.waitForFunction(()=>__v4Map.getZoom()>=16&&!__v4Map._animatingZoom&&!__v4Map._panAnim?._inProgress);
  await page.waitForFunction(count=>document.querySelectorAll('.phone-marker').length===count,displayedPhones.siteCount);
  check(displayedPhones.siteCount>0&&await page.locator('.phone-cluster').count()===0,'street-level zoom exposes individual registered locations');
  const sizes=await page.locator('.phone-marker').evaluateAll(elements=>elements.map(element=>({width:element.getBoundingClientRect().width,count:element.dataset.locationCount})));
  check(sizes.every(size=>size.width>=20&&size.width<=26&&size.count==='1'),'individual phone pins stay small and represent exactly one location');
  const clickablePhone=await clickableIndex('.phone-marker');
  assert.ok(clickablePhone>=0,'a visible public phone is clear of mobile controls');
  await page.locator('.phone-marker').nth(clickablePhone).tap();
  check(await page.locator('.leaflet-popup').isVisible(),'individual phone markers open an in-app location popup');
  check(/Telstra/i.test(await page.locator('.phone-popup').textContent())&&!/OpenStreetMap/i.test(await page.locator('.phone-popup').textContent()),'phone popup identifies the actual Telstra source rather than the old OSM fallback');
  check((await page.locator('#map-phones').getAttribute('title')).includes(String(displayedPhones.siteCount))&&/Telstra/i.test(await page.locator('#map-phones').getAttribute('title')),'phone control reports the visible registered locations and correct source');
  check((await page.locator('.phone-quantity').textContent()).includes(String(displayedPhones.phones[clickablePhone].phoneCount)),'location popup reports the number of actual payphones at that site');
  await page.evaluate(()=>window.__v4Map.closePopup());
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  await page.screenshot({path:'/tmp/nextstop-v4-phones.png',fullPage:true});
  await page.locator('#map-phones').click();
  check(await page.locator('.phone-marker, .phone-cluster').count()===0 && await page.locator('#map-phones').getAttribute('aria-pressed')==='false','turning phones off removes only the phone overlay');
  }
  check(await page.locator('.journey-option[aria-pressed="true"]').getAttribute('data-journey')===phoneJourney,'phone toggling preserves the selected transport journey');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'compact phone layout has no horizontal overflow');
  check(errors.length===0,'mobile flows complete without JavaScript exceptions');
  await page.screenshot({ path:'/tmp/nextstop-v4-mobile.png',fullPage:true });
  console.log(`\n${checks} real-API mobile v4 checks passed.`);
  console.log(`GPS was synthetic to reproduce coarse-to-fine updates; transport and foot routes used real APIs. Phone data/error outcomes are reported above. Street-map TLS failures: ${telemetry.tileFailures}; no basemap verification or TLS bypass is claimed.`);
  }
} finally {
  for (const context of contexts) await context.close();
  await browser.close();
}
