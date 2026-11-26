/** Transport preferences and failed-endpoint assistance. Disclosed API/GPS/tile fixtures. */
import assert from 'node:assert/strict';import {createRequire} from 'node:module';import {existsSync} from 'node:fs';
const {chromium}=createRequire(import.meta.url)('playwright');const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BINARY||(existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined)});
let checks=0;const check=(v,label)=>{assert.ok(v,label);checks++;console.log('PASS:',label)};
try{for(const width of [320,390,430,1366]){
 const context=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce'}),page=await context.newPage(),errors=[],requests=[];let fail=false,failEndpoint='destination',accessRequests=0;
 page.on('pageerror',e=>errors.push(e.message));await page.addInitScript(()=>{navigator.geolocation.watchPosition=()=>1;navigator.geolocation.clearWatch=()=>{}});
 const from={lat:-31.95,lon:115.85,name:'Start'},to={lat:-31.96,lon:115.86,name:'Inside building'},gps={lat:-31.96,lon:115.8604,name:'Public footway',distanceFromSelection:38};
 const polygon={type:'Polygon',coordinates:[[[115.8598,-31.9602],[115.8602,-31.9602],[115.8602,-31.9598],[115.8598,-31.9598],[115.8598,-31.9602]]],name:'Fixture building'};
 const iso=s=>new Date(Date.now()+s*1000).toISOString();
 function journey(params){const [lat,lon]=params.to.split(',').map(Number),end={lat,lon,name:'Arrival'};const mode=params.modes.split(',')[0];const walk={mode:'walk',routing:'street',from,to:from,departure:iso(0),arrival:iso(120),duration:120,distance:150,geometry:[[from.lat,from.lon],[from.lat+.0001,from.lon]]};const final={...walk,from,to:end,departure:iso(800),arrival:iso(1460),duration:660,distance:825,geometry:[[from.lat,from.lon],[lat,lon]]};const ride={mode,tripId:'fixture-trip',routeName:'Fixture '+mode,routeColor:'#009645',from,to:from,departure:iso(240),arrival:iso(800),geometry:[[from.lat,from.lon],[lat,lon]],headsign:'Arrival'};return{id:'mode-trip',walkRouting:'street',departure:walk.departure,arrival:final.arrival,duration:1460,legs:mode?[walk,ride,final]:[{...final,departure:iso(0),arrival:iso(300),duration:300}]}}
 await page.route('https://tile.openstreetmap.org/**',r=>r.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256"/>'}));
 await page.route('**/api.php*',async r=>{const u=new URL(r.request().url()),action=u.searchParams.get('action');let data={};
  if(action==='status')data={mode:'timetable',expired:false,routeRevision:'modes'};
  if(action==='routes')data={revision:'modes',lines:[]};if(action==='vehicles')data={vehicles:[],stops:[],generatedAt:iso(0)};
  if(action==='plan'){const p=Object.fromEntries(u.searchParams);requests.push(p);data=fail?{mode:'timetable',journeys:[],message:'Choose a public entrance.',walkingIssue:{reason:'no_segment',endpoint:failEndpoint}}:{mode:'timetable',journeys:[journey(p)]};}
  if(action==='walking-access'){accessRequests++;data={available:true,polygon,nearestWalkingPoint:gps,message:'Approximate walking access. Not a verified entrance.',attribution:'© OpenStreetMap contributors'};}
  await r.fulfill({json:data});});
 await page.route('**/assets/app.js*',async r=>{const response=await r.fetch(),s=await response.text();await r.fulfill({response,body:s.replace('  init();','  window.__modes={state,setPlace,setPanel};\n  init();')});});
 await page.goto(process.env.BASE_URL||'http://127.0.0.1:8080/');await page.waitForFunction(()=>window.__modes&&Nextstop.getMap());
 const show=()=>page.evaluate(()=>__modes.setPanel(true));await show();
 for(const mode of ['bus','train','ferry','uber','walk']){const el=page.locator(`[data-search-mode="${mode}"]`),b=await el.boundingBox();check(b.height>=44&&b.width>=44&&b.x>=0&&b.x+b.width<=width,`${width}px ${mode} is a phone-sized toggle`);}
 await page.evaluate(({from,to})=>{__modes.setPlace('origin',from);__modes.setPlace('destination',to)},{from,to});await page.waitForFunction(()=>__modes.state.selected);await show();
 check(requests.at(-1).modes==='bus,train,ferry'&&requests.at(-1).longWalks==='1',`${width}px defaults reach the route search`);
 await page.locator('[data-search-mode="bus"]').click();await page.waitForFunction(()=>__modes.state.selected?.legs.some(l=>l.mode==='train'));await show();
 check(requests.at(-1).modes==='train,ferry',`${width}px bus off replans using the remaining transit modes`);
 check(await page.locator('#last-mile-uber').evaluate(e=>!e.hidden),`${width}px optional Uber is initially available on a long final walk`);
 const before=requests.length;await page.locator('[data-search-mode="uber"]').click();check(await page.locator('#last-mile-uber').isHidden()&&requests.length===before,`${width}px Uber off removes the handoff without replacing the selected transit journey`);
 await page.locator('[data-search-mode="train"]').click();await page.waitForFunction(()=>__modes.state.selected?.legs.some(l=>l.mode==='ferry'));await show();
 check(requests.at(-1).modes==='ferry',`${width}px ferry-only search sends exactly that preference`);
 await page.locator('[data-search-mode="ferry"]').click();await page.waitForFunction(()=>__modes.state.selected?.legs.every(l=>l.mode==='walk'));await show();
 check(requests.at(-1).modes==='',`${width}px all transit off permits walking-only search`);
 await page.locator('[data-search-mode="walk"]').click();await page.waitForFunction(()=>__modes.state.selected);await show();
 check(requests.at(-1).longWalks==='0'&&await page.evaluate(()=>__modes.state.selected.legs.every(l=>l.mode==='walk')),`${width}px Walk off requests the long-walk limit while retaining short access walking`);
 await page.reload();await page.waitForFunction(()=>window.__modes&&__modes.state.selected);await show();
 check(await page.locator('[data-search-mode="bus"]').getAttribute('aria-pressed')==='false'&&await page.locator('[data-search-mode="walk"]').getAttribute('aria-pressed')==='false',`${width}px travel choices survive reopening the saved trip`);
 fail=true;await page.locator('[data-search-mode="bus"]').click();await page.locator('.access-suggestion-button').first().waitFor();
 check(accessRequests===1,`${width}px a diagnosed endpoint failure triggers one boundary lookup`);
 check(await page.evaluate(()=>__modes.state.accessSuggestionLayers.getLayers().length===2),`${width}px the returned polygon and suggested walking GPS point appear on the map`);
 check((await page.locator('#message').innerText()).includes('Approximate')&&(await page.locator('#message').innerText()).includes('OpenStreetMap'),`${width}px the suggestion shows its uncertainty and data source`);
 check((await page.locator('#message').innerText()).includes('GPS: -31.960000, 115.860400'),`${width}px the suggested GPS coordinates are readable`);
 fail=false;await page.getByRole('button',{name:'Use this walking point',exact:true}).click();await page.waitForFunction(()=>__modes.state.selected);await show();
 check(requests.at(-1).to===`${gps.lat},${gps.lon}`,`${width}px choosing the walking point performs a fresh route search to that GPS position`);
 fail=true;failEndpoint='connection';await page.locator('[data-search-mode="train"]').click();await page.waitForFunction(()=>!__modes.state.planController&&document.getElementById('message').textContent==='Choose a public entrance.');
 check(accessRequests===1&&await page.locator('.access-suggestion-button').count()===0,`${width}px an inter-stop connection failure never moves the destination or requests a destination polygon`);
 check(errors.length===0,`${width}px no JavaScript errors`);await context.close();
}console.log(`${checks} search-mode and endpoint-assistance browser checks passed. Disclosed fixtures only.`)}finally{await browser.close()}
