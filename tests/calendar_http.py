#!/usr/bin/env python3
"""Isolated owner setup, session and CSRF checks; no Google network requests."""
import http.cookiejar,json,os,re,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.parse,urllib.error
from pathlib import Path
project=Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BINARY','php')
with tempfile.TemporaryDirectory(prefix='nextstop-calendar-http-') as tmp:
 root=Path(tmp)
 for name in ['loader.php','bootstrap.php','calendar.php','dev-router.php','src/GoogleCalendar.php']:
  target=root/name;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(project/name,target)
 command='define("PM_LOADER_LIBRARY_ONLY",true);require $argv[1];echo json_encode(pm_application_manifest());'
 manifest=json.loads(subprocess.check_output([php,'-r',command,str(root/'loader.php')]))
 for name in manifest:
  target=root/name
  if not target.exists():target.parent.mkdir(parents=True,exist_ok=True);target.write_text('fixture presence only')
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 env={k:v for k,v in os.environ.items() if k!='PERTH_MAP_SETUP_TOKEN'}
 with (root/'server.log').open('w') as log:
  process=subprocess.Popen([php,'-d',f'session.save_path={root}','-S',f'127.0.0.1:{port}','dev-router.php'],cwd=root,stdout=log,stderr=log,env=env)
  try:
   client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));base=f'http://127.0.0.1:{port}/'
   def request(path,data=None):
    encoded=urllib.parse.urlencode(data).encode() if data is not None else None
    try:
     with client.open(base+path,encoded,timeout=10) as r:return r.status,r.read().decode(),r.headers
    except urllib.error.HTTPError as e:return e.code,e.read().decode(),e.headers
   for _ in range(60):
    try:status,text,_=request('calendar.php?action=status');break
    except urllib.error.URLError:time.sleep(.05)
   assert status==200 and json.loads(text)['configured'] is False and not (root/'var/calendar-sessions').exists()
   _,html,_=request('loader.php');csrf=lambda text:re.search(r'name="csrf" value="([^"]+)"',text)[1]
   key=json.loads((root/'var/setup-token.php').read_text().split('\n',1)[1])['setup_token']
   _,html,_=request('loader.php',{'csrf':csrf(html),'action':'login','key':key})
   config={'action':'google-calendar','google_client':'fixture.apps.googleusercontent.com','google_secret':'fixture-secret-not-real','google_redirect':'https://example.test/calendar.php'}
   _,bad,_=request('loader.php',dict(config,csrf='wrong'))
   assert 'form expired' in bad and not (root/'var/google-calendar.php').exists()
   _,html,_=request('loader.php',dict(config,csrf=csrf(html)))
   assert 'setup saved privately' in html and config['google_secret'] not in html
   saved=json.loads((root/'var/google-calendar.php').read_text().split('\n',1)[1]);assert saved['clientSecret']==config['google_secret']
   assert request('var/google-calendar.php')[0]==404
   _,html,_=request('loader.php',dict(config,csrf=csrf(html),google_secret=''))
   assert json.loads((root/'var/google-calendar.php').read_text().split('\n',1)[1])['clientSecret']==config['google_secret']
   status,text,headers=request('calendar.php?action=status');public=json.loads(text)
   assert status==200 and public['configured'] and not public['connected'] and 'csrf' in public
   assert config['google_secret'] not in text and config['google_client'] not in text
   assert 'no-store' in headers['Cache-Control']
   # Missing method, invalid callback and unconfirmed writes must not contact Google.
   assert request('calendar.php?action=connect')[0]==400
   assert request('calendar.php?code=fixture&state=wrong')[0]==400
   raw=urllib.request.Request(base+'calendar.php?action=add-travel',data=json.dumps({'csrf':'wrong','confirmed':True,'blocks':[]}).encode(),headers={'Content-Type':'application/json'})
   try:client.open(raw,timeout=10);raise AssertionError('CSRF accepted')
   except urllib.error.HTTPError as e:assert e.code==403
   assert request('var/calendar-sessions/')[0]==404
   print('PASS unconfigured state, owner authentication/CSRF, protected credentials, blank-secret preservation, private public-status fields, callback state, mutation CSRF and private sessions. No Google requests.')
  finally:
   process.terminate()
   try:process.wait(timeout=5)
   except subprocess.TimeoutExpired:process.kill();process.wait()
