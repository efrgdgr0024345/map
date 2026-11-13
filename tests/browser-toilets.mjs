/** Actual Leaflet/controls with disclosed toilet API fixtures; no provider requests. */
import assert from 'node:assert/strict';import {createRequire} from 'node:module';import {existsSync} from 'node:fs';
const require=createRequire(import.meta.url),{chromium}=require('playwright');
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BINARY||(existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined)});
const base=process.env.BASE_URL||'http://127.0.0.1:8080/';let checks=0;const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS:',label);};
try{for(const width of [320,390,1366]){
 const context=await browser.newContext({viewport:{width,height:900},isMobile:width<760,hasTouch:width<760});const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 let calls=0,details=0,mode='normal';
 const toilets=[{id:'123',name:'Fixture toilet',lat:-31.9505,lon:115.8586,address:'1 Test St Perth',type:'Park',hours:'Mon-Fri 8am-8pm',listedClosed:false,amenities:['Accessible toilets','Baby change','Key required'],sourceUrl:'https://toiletmap.gov.au/facility/123'},
 {id:'456',name:'Fixture closed toilet',lat:-31.9497,lon:115.8598,address:'2 Test St Perth',hours:'Hours not supplied',listedClosed:true,amenities:['Drinking water'],sourceUrl:'https://toiletmap.gov.au/facility/456'}];
 const meta=()=>({source:'National Public Toilet Map',sourceUrl:'https://toiletmap.gov.au/',updatedAt:new Date().toISOString(),stale:mode==='stale',cacheSeconds:120,limited:true});
 await page.route('**/api.php*',async route=>{const url=new URL(route.request().url()),a=url.searchParams.get('action');let data={};
  if(a==='toilets'){calls++;if(mode==='fail'){await route.fulfill({status:503,json:{error:'Fixture outage'}});return;}if(mode==='slow')await new Promise(r=>setTimeout(r,300));data={...meta(),toilets:mode==='duplicate'?[...toilets,{...toilets[0],id:'789',name:'Fixture upstairs toilet',sourceUrl:'https://toiletmap.gov.au/facility/789'}]:toilets};}
  else if(a==='toilet'){details++;const t=toilets.find(t=>t.id===url.searchParams.get('id'))||{...toilets[0],id:'789',name:'Fixture upstairs toilet'};data={...meta(),toilet:{...t,notes:[{label:'Access',text:'Collect key from reception'},{label:'Hours',text:'Public holidays may differ'}],operator:'Fixture Council',contactPhone:'08 1234 5678'}};}
  else if(a==='status')data={mode:'timetable',routeRevision:'toilet-fixture'};
  else if(a==='vehicles')data={vehicles:[],stops:[],lines:[],generatedAt:new Date().toISOString()};
  else if(a==='routes')data={revision:'toilet-fixture',lines:[]};
  await route.fulfill({json:data});
 });
 await page.route('**/assets/app.js*',async route=>{const r=await route.fetch(),s=await r.text();await route.fulfill({response:r,body:s.replace('  init();','  window.__toiletTest={state,fetchToilets,renderToilets};\n  init();')});});
 await page.goto(base);await page.waitForFunction(()=>window.Nextstop?.getMap());
 check(calls===0,`${width}px toilet API stays idle until the WC toggle is enabled`);
 const button=page.getByRole('button',{name:'Show public toilets',exact:true});const rect=await button.boundingBox();check(rect.x>=0&&rect.y>=0&&rect.y+rect.height<=900,`${width}px WC toggle beside zoom controls is reachable`);
 await button.click();await page.waitForFunction(()=>document.querySelectorAll('.toilet-marker').length===2);
 check(await page.locator('#map-toilets').getAttribute('aria-pressed')==='true',`${width}px WC toggle renders individual facilities`);
 await page.locator('.toilet-marker').first().click();await page.waitForFunction(()=>document.querySelector('.toilet-popup')?.textContent.includes('Collect key from reception'));
 const text=await page.locator('.toilet-popup').innerText();check(text.includes('Mon-Fri 8am-8pm')&&text.includes('Accessible toilets')&&text.includes('Baby change')&&text.includes('Key required'),`${width}px popup includes published hours and useful facilities`);
 check(text.includes('Fixture Council')&&text.includes('Public holidays may differ')&&details>=1,`${width}px opening a pin fetches additional live access and operator notes`);
 check(await page.locator('.toilet-popup a').getAttribute('href')==='https://toiletmap.gov.au/facility/123',`${width}px popup links to the official facility record`);
 check(text.includes('cache up to 2 min')&&!text.includes('Open now'),`${width}px source fetch time is visible without inventing live opening status`);
 await page.locator('.leaflet-popup-close-button').click();await page.locator('.toilet-marker').nth(1).click();await page.waitForFunction(()=>document.querySelector('.toilet-popup')?.textContent.includes('Listed as closed'));
 check((await page.locator('.toilet-popup').innerText()).includes('Hours not supplied'),`${width}px closed status and unknown hours remain explicit`);
 await page.locator('.leaflet-popup-close-button').click();
 await page.evaluate(()=>Nextstop.getMap().setZoom(12,{animate:false}));await page.waitForFunction(()=>document.querySelectorAll('.toilet-cluster').length>0);
 check(true,`${width}px overview clusters toilet locations`);
 await page.locator('.toilet-cluster').first().click();await page.waitForFunction(()=>Nextstop.getMap().getZoom()>=16);
 check(true,`${width}px clicking a cluster opens the detailed area`);
 mode='duplicate';await page.evaluate(()=>__toiletTest.fetchToilets());
 await page.waitForFunction(()=>document.querySelectorAll('.toilet-cluster').length>0);
 await page.locator('.toilet-cluster').first().click();await page.getByRole('button',{name:'Fixture upstairs toilet',exact:true}).click();
 await page.waitForFunction(()=>document.querySelectorAll('.toilet-popup').length===1 && document.querySelector('.toilet-popup')?.textContent.includes('Fixture upstairs toilet') && document.querySelector('.toilet-popup')?.textContent.includes('Collect key from reception'));
 check((await page.locator('.toilet-popup').innerText()).includes('Fixture upstairs toilet'),`${width}px facilities at the same coordinates can be selected individually`);
 await page.locator('.leaflet-popup-close-button').click();
 mode='stale';await page.evaluate(()=>__toiletTest.fetchToilets());check((await page.locator('#map-toilets').getAttribute('title')).includes('Saved data'),`${width}px source outage fallback is clearly labelled as saved data`);
 await page.locator('#map-toilets').click();check(await page.locator('.toilet-marker,.toilet-cluster').count()===0,`${width}px toggle removes toilet pins without changing journey state`);
 mode='slow';await page.locator('#map-toilets').click();await page.locator('#map-toilets').click();await page.waitForTimeout(400);
 check(await page.locator('.toilet-marker,.toilet-cluster').count()===0,`${width}px a delayed result cannot restore a disabled layer`);
 check(errors.length===0,`${width}px no JavaScript exceptions`);await context.close();
}console.log(`\n${checks} toilet overlay browser checks passed. Disclosed API fixtures.`);}finally{await browser.close();}
