#!/usr/bin/env python3
"""Owner-controlled POI upload and public caching; isolated PHP fixture, no providers."""
import http.cookiejar,json,os,re,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.parse,urllib.error
from pathlib import Path
project=Path(__file__).resolve().parents[1];php=os.environ.get('PHP_BINARY','php')
with tempfile.TemporaryDirectory(prefix='nextstop-poi-http-') as tmp:
 root=Path(tmp)
 for name in ['loader.php','bootstrap.php','api.php','dev-router.php','src/PoiCatalog.php','src/GoogleCalendar.php','assets/perth-pois.json']:
  target=root/name;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(project/name,target)
 manifest=json.loads(subprocess.check_output([php,'-r','define("PM_LOADER_LIBRARY_ONLY",true);require $argv[1];echo json_encode(pm_application_manifest());',str(root/'loader.php')]))
 for name in manifest:
  target=root/name
  if not target.exists():target.parent.mkdir(parents=True,exist_ok=True);target.write_text('fixture presence only')
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 env={k:v for k,v in os.environ.items() if k!='PERTH_MAP_SETUP_TOKEN'}
 with (root/'server.log').open('w') as log:
  process=subprocess.Popen([php,'-d',f'session.save_path={root}','-S',f'127.0.0.1:{port}','dev-router.php'],cwd=root,stdout=log,stderr=log,env=env)
  try:
   client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));base=f'http://127.0.0.1:{port}/'
   def request(path,data=None,headers={}):
    if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
    try:
     with client.open(urllib.request.Request(base+path,data=data,headers=headers),timeout=10) as r:return r.status,r.read().decode(),r.headers
    except urllib.error.HTTPError as e:return e.code,e.read().decode(),e.headers
   for _ in range(60):
    try:status,text,headers=request('api.php?action=pois');break
    except urllib.error.URLError:time.sleep(.05)
   bundled=json.loads(text);assert status==200 and len(bundled['places'])==100 and bundled['catalogOrigin']=='bundled'
   etag=headers['ETag'];assert request('api.php?action=pois',headers={'If-None-Match':etag})[0]==304
   assert 'public' in headers['Cache-Control'] and request('api.php?action=pois&download=1')[2]['Content-Disposition'].endswith('"perth-pois.json"')
   _,html,_=request('loader.php');csrf=lambda text:re.search(r'name="csrf" value="([^"]+)"',text)[1]
   key=json.loads((root/'var/setup-token.php').read_text().split('\n',1)[1])['setup_token']
   _,html,_=request('loader.php',{'csrf':csrf(html),'action':'login','key':key})
   def upload(content,token):
    boundary='nextstop-poi-upload-fixture';parts=[]
    for name,value in [('csrf',token),('action','poi-upload')]:parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n')
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="poi_json"; filename="places.json"\r\nContent-Type: application/json\r\n\r\n{content}\r\n--{boundary}--\r\n')
    return request('loader.php',''.join(parts).encode(),{'Content-Type':f'multipart/form-data; boundary={boundary}'})
   _,bad,_=upload(json.dumps(bundled),'wrong');assert 'form expired' in bad and not(root/'var/perth-pois.json').exists()
   _,html,_=upload('{broken',csrf(html));assert 'Invalid POI catalogue' in html and not(root/'var/perth-pois.json').exists()
   custom=json.loads(json.dumps(bundled));custom['places'][0]['name']='Owner curated park';custom['revision']='owner-fixture'
   _,html,_=upload(json.dumps(custom),csrf(html));assert 'Perth places published: 100 entries' in html
   status,text,headers=request('api.php?action=pois');active=json.loads(text);assert status==200 and active['catalogOrigin']=='custom' and active['places'][0]['name']=='Owner curated park' and headers['ETag']!=etag
   assert request('var/perth-pois.json')[0]==404
   before=(root/'var/perth-pois.json').read_bytes();invalid=json.loads(json.dumps(custom));invalid['places'][0]['contact']['website']='javascript:alert(1)'
   _,html,_=upload(json.dumps(invalid),csrf(html));assert 'Invalid POI catalogue' in html and (root/'var/perth-pois.json').read_bytes()==before
   _,html,_=request('loader.php',{'csrf':csrf(html),'action':'poi-reset'});assert 'Confirm restoring' in html and (root/'var/perth-pois.json').exists()
   _,html,_=request('loader.php',{'csrf':csrf(html),'action':'poi-reset','confirm_poi_reset':'yes'});assert 'bundled Perth places catalogue is active' in html and not(root/'var/perth-pois.json').exists()
   assert json.loads(request('api.php?action=pois')[1])['catalogOrigin']=='bundled'
   print('PASS public catalogue, download, ETag/304, owner auth/CSRF, JSON upload validation, private override, rejected-update preservation and confirmed reset. No provider calls.')
  finally:
   process.terminate()
   try:process.wait(timeout=5)
   except subprocess.TimeoutExpired:process.kill();process.wait()
