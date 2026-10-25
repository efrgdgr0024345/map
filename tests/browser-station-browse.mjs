/**
 * Actual local station catalog/API checks. Only browser GPS and the invocation
 * of an actual API journey are controlled; station and walking data are not mocked.
 * Basemap imagery is not asserted when the cloud certificate proxy blocks tiles.
 * Run: node tests/browser-station-browse.mjs against the imported app on port8080.
 */
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { createRequire } from 'node:module';
const require=createRequire(import.meta.url);
const {chromium}=require('playwright');
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BINARY||(existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined)});
const base=process.env.BASE_URL||'http://127.0.0.1:8080/';
const errors=[],requests=[];let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;console.log(`PASS: ${label}`)};
const centre=[-31.956287217969,115.853894560569];
const page=await browser.newPage({viewport:{width:1366,height:900},timezoneId:'Australia/Perth'});
page.on('pageerror',error=>errors.push(error.message));
page.on('request',request=>{if(request.url().includes('api.php'))requests.push(new URL(request.url()));});
await page.route('**/assets/app.js*',async route=>{const response=await route.fetch();let body=await response.text();body=body.replace('  init();','  window.__browseTest={state,getMap:()=>map,selectJourney,clearJourney};\n  init();');await route.fulfill({response,body});});
await page.addInitScript(()=>{
 let watcher;
 Object.defineProperty(navigator,'geolocation',{configurable:true,value:{
  getCurrentPosition(success){success({coords:{latitude:-31.9501,longitude:115.8568,accuracy:8},timestamp:Date.now()});},
  watchPosition(success){watcher=success;return 1;},clearWatch(){watcher=null;}
 }});
 window.__browseGps=(lat,lon)=>watcher?.({coords:{latitude:lat,longitude:lon,accuracy:7},timestamp:Date.now()+1});
});
const settle=async()=>{await page.waitForFunction(()=>{const m=__browseTest.getMap();return!m._animatingZoom&&!m._panAnim?._inProgress});await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))))};
const setView=async(point,zoom)=>{await page.evaluate(({point,zoom})=>{__browseTest.getMap().setView(point,zoom,{animate:false});},{point,zoom});await settle();};
try{
 await page.goto(base,{waitUntil:'domcontentloaded'});await page.waitForFunction(()=>window.__browseTest&&__browseTest.state.routeCatalog?.length&&__browseTest.state.stationBrowseCatalog?.length);
 check(await page.locator('#station-browse').isHidden(),'network overview starts without a station card');
 await page.locator('#map-locate').click();await page.waitForFunction(()=>__browseTest.state.locationWatch!==null);await settle();
 const values=await page.locator('#origin-input,#destination-input').evaluateAll(inputs=>inputs.map(input=>input.value));
 await setView(centre,18);
 await page.waitForFunction(()=>__browseTest.state.stationBrowseReady&&document.querySelector('#map').dataset.stationBrowse==='136');
 check((await page.locator('#station-browse-name').textContent()).includes('Elizabeth Quay Bus'),'close zoom automatically opens the correct Elizabeth Quay bus-station map');
 check(await page.locator('#map').getAttribute('data-network-lines')==='0','overlapping network route variants are suppressed in station close-up');
 check(await page.locator('.station-feature-platform').count()>0&&await page.locator('.station-feature-footway').count()>0,'actual locally stored platform and pedestrian geometry renders without a journey');
 check(await page.locator('.station-stand-label').count()>10,'known station stands have readable local labels');
 check(await page.locator('.guidance-stop-origin,.guidance-stop-target').count()===0,'station exploration invents no alighting or boarding target');
 check(JSON.stringify(await page.locator('#origin-input,#destination-input').evaluateAll(inputs=>inputs.map(input=>input.value)))===JSON.stringify(values),'station exploration leaves both journey endpoints unchanged');
 const platformIndex=await page.locator('.station-feature-platform').evaluateAll(elements=>elements.findIndex(element=>{const b=element.getBoundingClientRect(),x=b.x+b.width/2,y=b.y+b.height/2;return x>430&&x<innerWidth-70&&y>180&&y<innerHeight-120&&document.elementFromPoint(x,y)===element;}));
 assert.ok(platformIndex>=0,'a real platform is available outside the sidebar');
 await page.locator('.station-feature-platform').nth(platformIndex).click();
 check(JSON.stringify(await page.locator('#origin-input,#destination-input').evaluateAll(inputs=>inputs.map(input=>input.value)))===JSON.stringify(values),'opening a mapped platform popup does not bubble into an accidental destination choice');
 await page.evaluate(()=>__browseTest.getMap().closePopup());

 const visible=requests.filter(url=>url.searchParams.get('action')==='station-map');
 check(visible.length===1&&visible[0].searchParams.get('from')==='136'&&visible[0].searchParams.get('to')==='136','station exploration uses the actual public parent-stop lookup');
 const oldCentre=await page.evaluate(()=>{const c=__browseTest.getMap().getCenter();return[c.lat,c.lng]});
 await page.evaluate(()=>__browseGps(-31.95012,115.85682));
 await page.waitForFunction(()=>Math.abs(__browseTest.state.userLocation.lat+31.95012)<1e-9);
 check(await page.evaluate(old=>__browseTest.getMap().distance(old,__browseTest.getMap().getCenter())<.1,oldCentre),'continuous GPS updates do not recenter a station being explored');
 await page.locator('#station-browse-toggle').click();
 await page.waitForFunction(()=>!__browseTest.state.stationBrowse&&Number(document.querySelector('#map').dataset.networkLines)>0);
 check(await page.locator('#station-browse-toggle').textContent()==='Station map','Show routes restores the overview and offers an explicit return to station detail');
 await setView([centre[0]+.00005,centre[1]],18);await page.waitForTimeout(260);
 check(await page.evaluate(()=>!__browseTest.state.stationBrowse),'manual route-view choice remains respected while panning inside the same station');
 await page.locator('#station-browse-toggle').click();await page.waitForFunction(()=>__browseTest.state.stationBrowseReady);
 check(requests.filter(url=>url.searchParams.get('action')==='station-map').length===1,'returning to the same station uses the in-memory detail cache');
 await page.locator('#origin-input').click();
 await page.waitForFunction(()=>__browseTest.state.pickField==='origin');
 check(await page.locator('#station-browse').isHidden(),'map picking hides the station card instead of obstructing point selection');
 check(await page.locator('.station-feature-platform').first().evaluate(element=>getComputedStyle(element).pointerEvents)==='none','station polygons cannot intercept endpoint-picker taps');
 await page.locator('#cancel-map-pick').click();await page.waitForFunction(()=>!__browseTest.state.pickField&&__browseTest.state.stationBrowseReady&&!document.querySelector('#station-browse').hidden);
 check(await page.locator('#station-browse').isVisible(),'cancelling map picking restores the station close-up without reopening the picker');
 await setView(centre,15);await page.waitForFunction(()=>!__browseTest.state.stationBrowse&&Number(document.querySelector('#map').dataset.networkLines)>0);
 check(await page.locator('#station-browse').isHidden(),'zooming out restores network lines and dismisses the station card');
 await setView(centre,18);await page.waitForFunction(()=>__browseTest.state.stationBrowseReady);
 check(await page.locator('#station-browse-toggle').textContent()==='Show routes','returning to close zoom reopens the station map after leaving the station view');
 await setView([-31.9478,115.8675],18);await page.waitForFunction(()=>!__browseTest.state.stationBrowse);
 check(await page.locator('#station-browse').isHidden(),'panning outside station areas restores ordinary browsing');
 const apiUrl=new URL('api.php',base);apiUrl.search=new URLSearchParams({action:'plan',from:'-32.009714444444,115.85615277778',to:'-32.047008888889,115.85414722222'}).toString();
 const result=await page.request.get(apiUrl.href,{timeout:90000});const plan=await result.json();
 assert.ok(result.ok()&&plan.journeys?.length,'actual direct train journey is available for preservation checks');
 await page.evaluate(journey=>__browseTest.selectJourney(journey),plan.journeys[0]);await settle();
 const selected=await page.evaluate(()=>__browseTest.state.selected.id);
 await page.locator('#map-zoom-out').click();await settle();
 await setView(centre,18);await page.waitForTimeout(260);
 check(await page.locator('#station-browse').isHidden()&&await page.evaluate(id=>__browseTest.state.selected.id===id,selected),'a selected actual journey takes priority and is never reset by station browsing');
 await page.evaluate(()=>__browseTest.clearJourney(false));await page.waitForFunction(()=>__browseTest.state.stationBrowseReady);
 await page.locator('#station-browse-details').click();
 check((await page.locator('#station-browse-source').textContent()).includes('OpenStreetMap'),'station context keeps its real source and date available in collapsed details');
 await page.locator('#station-browse-details').click();
 await page.screenshot({path:'/tmp/nextstop-station-browse.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});await page.waitForTimeout(250);
 check(await page.locator('#station-browse').isVisible()&&await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'station detail and controls remain usable on a narrow phone without horizontal overflow');
 const card=await page.locator('#station-browse').boundingBox(),controls=await page.locator('.map-controls').boundingBox();
 check(card.x+card.width<=controls.x,'open mobile sheet keeps station controls beside the zoom buttons without overlap');
 await page.screenshot({path:'/tmp/nextstop-station-browse-mobile.png',fullPage:true});
 check(errors.length===0,`station browsing produces no JavaScript exceptions (${errors.join('; ')})`);
 console.log(`\n${checks} station browsing checks passed. Station and timetable APIs were actual; GPS and selected-journey invocation were controlled. No basemap TLS bypass.`);
}finally{await browser.close();}
