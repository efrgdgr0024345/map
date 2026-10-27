/** Real local POI JSON and autocomplete; transport responses are fixtures. Basemap imagery is not verified. */
import assert from 'node:assert/strict';import {existsSync}from'node:fs';import{createRequire}from'node:module';
const require=createRequire(import.meta.url),{chromium}=require('playwright'),base=process.env.BASE_URL||'http://127.0.0.1:8080/';
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BINARY||(existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined)});
let checks=0;const check=(v,s)=>{assert.ok(v,s);checks++;console.log('PASS:',s)};
try{for(const width of[390,1366]){
 const context=await browser.newContext({viewport:{width,height:900},isMobile:width<760,hasTouch:width<760});const page=await context.newPage(),errors=[];let poiRequests=0;
 page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>{if(new URL(r.url()).searchParams.get('action')==='pois')poiRequests++});
 await page.route('**/api.php*',async route=>{const a=new URL(route.request().url()).searchParams.get('action');if(['pois','geocode'].includes(a)){await route.continue();return}await route.fulfill({json:a==='status'?{mode:'timetable',routeRevision:'fixture'}:a==='plan'?{journeys:[],message:'Fixture plan deliberately unavailable'}:{vehicles:[],lines:[],stops:[]}})});
 await page.goto(base,{waitUntil:'domcontentloaded'});await page.waitForFunction(()=>window.NextstopPois&&document.querySelector('.poi-marker'));
 const response=await page.request.get(new URL('api.php?action=pois',base).href),catalog=await response.json();
 check(catalog.places.length===100,`${width}px actual local catalogue contains all 100 places`);
 check(await page.locator('.poi-marker').count()>0,`${width}px nearby POIs appear on the map`);
 const requests=poiRequests;await page.evaluate(()=>Nextstop.getMap().panBy([80,0],{animate:false}));await page.evaluate(()=>Nextstop.getMap().panBy([-160,0],{animate:false}));await page.waitForTimeout(200);
 check(poiRequests===requests,`${width}px panning redraws local POIs without downloading them again`);
 await page.locator('#map-pois').click();check(await page.locator('.poi-marker').count()===0&&await page.locator('#map-pois').getAttribute('aria-pressed')==='false',`${width}px POI toggle hides the layer`);await page.locator('#map-pois').click();
 await page.evaluate(()=>Nextstop.getMap().setView([-31.9572755,115.8691753],17,{animate:false}));
 const marker=page.locator('.poi-marker[title="The Perth Mint · Perth place"]');await marker.waitFor();await marker.click();
 check((await page.locator('.poi-popup').textContent()).includes('The Perth Mint'),`${width}px POI popup shows the actual catalogue name`);
 check((await page.locator('.poi-popup').textContent()).includes('Choose entrance'),`${width}px an approximate venue point offers entrance selection`);
 await page.locator('.poi-popup').getByRole('button',{name:'Plan a visit'}).click();await page.waitForFunction(()=>document.querySelector('#map').classList.contains('map-picking'));
 await page.locator('#use-map-center').click();await page.locator('#planner-stay').waitFor({state:'visible'});
 check(await page.locator('#planner-stay-minutes').inputValue()==='90',`${width}px POI visit uses the catalogue's suggested stay`);
 await page.locator('#planner-stay-minutes').fill('60');await page.locator('#planner-stay-remember').check();await page.locator('#planner-stay-save').click();
 check(await page.evaluate(()=>JSON.parse(localStorage.getItem('nextstop-places-v1')).some(p=>p.name==='The Perth Mint'&&p.stayMinutes===60)),`${width}px POI can be saved with a one-hour visit`);
 await page.locator('#close-day-planner').click();
 if(await page.locator('#journey-panel').getAttribute('aria-hidden')==='true')await page.locator('#open-panel').click();await page.locator('.location-search[data-search="destination"]').click();await page.locator('#destination-input').fill('Optus Stadium');
 await page.locator('#search-results .search-result').filter({hasText:'Perth Stadium'}).first().waitFor();
 check((await page.locator('#search-results').textContent()).includes('Search other addresses'),`${width}px local autocomplete retains an option to search ordinary addresses`);
 check((await page.locator('#search-results').textContent()).includes('Perth Stadium'),`${width}px actual local alias lookup resolves Optus Stadium`);
 await page.locator('#search-results .search-result').filter({hasText:'Perth Stadium'}).first().click();
 check(await page.locator('#map-hint').textContent().then(t=>t.includes('entrance to Perth Stadium')),`${width}px selecting an area POI requests an entrance on a clear map`);
 await page.locator('#cancel-map-pick').click();
 check(errors.length===0,`${width}px has no JavaScript errors`);
 await page.screenshot({path:`/tmp/nextstop-pois-${width}.png`});await context.close();
}console.log(`${checks} POI UI checks passed. Actual local data; no real walking/venue-opening claim.`)}finally{await browser.close()}
