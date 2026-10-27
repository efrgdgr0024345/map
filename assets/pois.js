/* One local catalogue shared by map popups and server-side autocomplete. */
(() => {
 'use strict';
 const map=window.Nextstop?.getMap(),toggle=document.getElementById('map-pois');if(!map||!toggle)return;
 const layer=L.layerGroup().addTo(map),make=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n};
 const colours={attraction:'#9c6538',museum:'#7551a7',park:'#508450',beach:'#187e9a',shopping:'#a2547d',venue:'#6d619b',library:'#627394',university:'#536c8e',transport:'#477e8a',landmark:'#8c723c'};
 let catalog=null,etag='',enabled=true,loadedAt=0,loading=null,popupOpen=false;
 function text(value){return typeof value==='string'?value:''}
 function url(value){try{const u=new URL(value);return ['https:','http:'].includes(u.protocol)&&!u.username&&!u.password?u.href:null}catch{return null}}
 function link(label,target){const a=make('a','poi-link',label);a.href=target;a.target='_blank';a.rel='noopener noreferrer';return a}
 function action(label,run){const b=make('button','poi-action',label);b.type='button';b.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();map.closePopup();run()});return b}
 function point(p){return {id:`poi-${p.id}`,poiId:p.id,name:p.name,lat:Number(p.navigation?.lat??p.lat),lon:Number(p.navigation?.lon??p.lon),stayMinutes:p.defaultStayMinutes||60,address:p.address?.display||p.name,requiresEntrance:!p.navigation&&p.location?.kind==='area-centre'}}
 function popup(p){
  const box=make('div','poi-popup');box.append(make('span','poi-category',p.category),make('h3','',p.name));
  if(p.editorial?.summary)box.append(make('p','',text(p.editorial.summary)));
  if(p.address?.display)box.append(make('p','',text(p.address.display)));
  if(p.openingHours?.text){box.append(make('p','poi-hours',`Hours recorded: ${text(p.openingHours.text)}`));const stamp=p.openingHours.verifiedAt||p.openingHours.asOf;box.append(make('small','poi-source-date',stamp?`Hours source: ${String(stamp).slice(0,10)} · check with venue`:'Check hours with the venue'));}
  const contacts=make('div','poi-contacts');const website=url(p.contact?.website);if(website)contacts.append(link('Website',website));
  const phone=text(p.contact?.phone);phone.split(';').map(p=>p.trim()).filter(Boolean).slice(0,3).forEach(number=>{if(/^[+0-9 ()\-]+$/.test(number)){const a=make('a','poi-link',number);a.href=`tel:${number.replace(/[^+0-9]/g,'')}`;contacts.append(a)}else contacts.append(make('span','',number))});
  const email=text(p.contact?.email);if(email&&/^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/.test(email)){const a=make('a','poi-link','Email');a.href=`mailto:${encodeURIComponent(email)}`;contacts.append(a)}
  if(contacts.childNodes.length)box.append(contacts);
  const events=(p.whatsOn?.events||[]).filter(e=>Number.isFinite(Date.parse(e.start))&&Date.parse(e.end)>Date.now()).sort((a,b)=>Date.parse(a.start)-Date.parse(b.start));
  if(events.length){box.append(make('strong','poi-events-heading',"What's on"));events.slice(0,3).forEach(e=>{const item=make('p','poi-event');const when=Date.parse(e.start)<=Date.now()?'On now':new Intl.DateTimeFormat('en-AU',{timeZone:'Australia/Perth',day:'numeric',month:'short',hour:'numeric',minute:'2-digit'}).format(new Date(e.start));item.append(make('strong','',text(e.title)),make('span','',when));const target=url(e.url);if(target)item.append(link('Event details',target));box.append(item)})}
  const place=point(p),actions=make('div','poi-actions');
  actions.append(action(place.requiresEntrance?'Choose entrance':'Go here',()=>window.Nextstop.choosePlace(place)),action('Plan a visit',()=>window.NextstopPlanner?.visit(place)),action('Save place',()=>window.NextstopPlanner?.save(place)));box.append(actions);
  const details=make('details','poi-details'),summary=make('summary','','Place details & sources');details.append(summary);
  if(p.navigation?.label)details.append(make('p','',text(p.navigation.label)));
  else if(place.requiresEntrance)details.append(make('p','','Approximate area location. Choose the entrance you need on the map.'));
  if(p.accessibility?.wheelchair)details.append(make('p','',`Mapped wheelchair access: ${text(p.accessibility.wheelchair)}. Check suitability with the venue.`));
  (p.sources||[]).forEach(source=>{const target=url(source.url);if(target){const line=make('p');line.append(link(text(source.name)||'Source',target));const stamp=source.asOf||source.retrievedAt;if(stamp)line.append(document.createTextNode(` · ${String(stamp).slice(0,10)}`));details.append(line)}});
  details.append(make('p','','Map information can change. Check the venue for current access and opening hours.'));box.append(details);return box;
 }
 function icon(p){const body=make('div','poi-pin');body.style.setProperty('--poi-colour',colours[p.category]||colours.landmark);const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('aria-hidden','true');const path=document.createElementNS('http://www.w3.org/2000/svg','path');
  const paths={park:'M12 3 5 12h4l-3 5h5v4m2-4h5l-3-5h4L12 3',shopping:'M5 8h14l1 13H4L5 8Zm3 0V6a4 4 0 0 1 8 0v2',museum:'M3 8 12 3l9 5H3Zm2 3v7m5-7v7m4-7v7m5-7v7M3 21h18',beach:'M3 11a9 9 0 0 1 18 0H3Zm9 0v9m-4 0h8M12 3V1',venue:'M5 5h14v14H5Zm4 0v14m6-14v14M5 10h14M5 14h14'};
  path.setAttribute('d',paths[p.category]||'M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0ZM12 7v6m-3-3h6');svg.append(path);body.append(svg);return L.divIcon({html:body,className:'poi-marker',iconSize:[26,30],iconAnchor:[13,28],popupAnchor:[0,-25]});
 }
 function render(){
  if(popupOpen&&enabled)return;layer.clearLayers();if(!enabled||!catalog)return;
  const bounds=map.getBounds().pad(.1),groups=new Map(),zoom=map.getZoom();
  catalog.places.filter(p=>p.display?.enabled!==false&&bounds.contains([p.lat,p.lon])).sort((a,b)=>(a.display?.priority||100)-(b.display?.priority||100)).forEach(p=>{const pixel=map.project([p.lat,p.lon],zoom);const key=zoom<15?`${Math.floor(pixel.x/65)}:${Math.floor(pixel.y/65)}`:p.id;if(!groups.has(key))groups.set(key,[]);groups.get(key).push(p)});
  groups.forEach(items=>{
   if(items.length===1){const p=items[0],marker=L.marker([p.lat,p.lon],{icon:icon(p),title:`${p.name} · Perth place`,zIndexOffset:180,bubblingMouseEvents:false});marker.bindTooltip(p.name,{direction:'top',offset:[0,-25]});marker.bindPopup(()=>popup(p),{maxWidth:310,minWidth:230,className:'poi-popup-container'});marker.addTo(layer);return}
   const lat=items.reduce((n,p)=>n+p.lat,0)/items.length,lon=items.reduce((n,p)=>n+p.lon,0)/items.length;
   const body=make('span','poi-cluster-count',String(items.length)),marker=L.marker([lat,lon],{icon:L.divIcon({html:body,className:'poi-cluster',iconSize:[34,34],iconAnchor:[17,17]}),title:`${items.length} Perth places`,bubblingMouseEvents:false,zIndexOffset:180});
   marker.on('click',()=>map.fitBounds(L.latLngBounds(items.map(p=>[p.lat,p.lon])),{padding:[70,120],maxZoom:16}));marker.addTo(layer);
  });
 }
 async function load(){if(loading)return loading;loading=(async()=>{const response=await fetch('api.php?action=pois',{cache:'no-store',headers:etag?{'If-None-Match':etag}:{}});if(response.status===304){loadedAt=Date.now();return}if(!response.ok)throw Error('Local places unavailable');const value=await response.json();if(value.schemaVersion!==1||!Array.isArray(value.places))throw Error('Invalid place catalogue');catalog=value;etag=response.headers.get('ETag')||'';loadedAt=Date.now();render()})().catch(()=>{toggle.title='Perth places are unavailable; click to retry'}).finally(()=>loading=null);return loading}
 toggle.addEventListener('click',()=>{map.closePopup();if(!catalog){load();return}enabled=!enabled;toggle.setAttribute('aria-pressed',String(enabled));toggle.setAttribute('aria-label',`${enabled?'Hide':'Show'} Perth places`);render();if(enabled&&Date.now()-loadedAt>300000)load()});
 map.on('popupopen',()=>popupOpen=true);map.on('popupclose',()=>{popupOpen=false;render()});map.on('moveend',render);document.addEventListener('visibilitychange',()=>{if(!document.hidden&&Date.now()-loadedAt>300000)load()});
 window.NextstopPois={reload:load};load();
})();
