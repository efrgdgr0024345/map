#!/usr/bin/env python3
"""Release preflight uses committed Git bytes, not mutable working files or an untested main."""
import hashlib
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('release',ROOT/'scripts/release.py');release=importlib.util.module_from_spec(spec);spec.loader.exec_module(release)
n=0

def check(ok,label):
 global n
 if not ok: raise AssertionError(label)
 n+=1;print('PASS:',label)

def fails(fn):
 try:fn();return False
 except RuntimeError:return True

with tempfile.TemporaryDirectory(prefix='nextstop-release-git-') as temp:
 p=Path(temp);release.ROOT=p
 def git(*args):return subprocess.check_output(['git','-C',str(p),*args],stderr=subprocess.PIPE)
 git('init','-q');(p/'assets').mkdir();(p/'var').mkdir();(p/'.github').mkdir()
 files={'index.php':b'<?php // exact committed bytes\n','assets/app.js':b'const route="fixture";\n','.gitignore':b'*.tmp\n'}
 for name,data in files.items():(p/name).write_bytes(data)
 (p/'config.php').write_text('fixture private config');(p/'var/private.php').write_text('fixture private data');(p/'.github/check.yml').write_text('fixture workflow')
 git('add','.');git('-c','user.name=Fixture','-c','user.email=fixture@example.test','commit','-qm','fixture')
 expected=release.local_release()
 check(expected['manifestDigest']==release.digest({f:hashlib.sha256(v).hexdigest() for f,v in files.items()}) and expected['fileCount']==len(files),'independent client digest uses exact Git blobs and the same explicit exclusions as the host')
 (p/'index.php').write_text('uncommitted edit')
 check(fails(release.local_release),'uncommitted changes cannot be mistaken for checked published code');(p/'index.php').write_bytes(files['index.php'])
 (p/'untracked.php').write_text('untracked')
 check(fails(release.local_release),'untracked output cannot silently join a release');(p/'untracked.php').unlink()
 (p/'ignored.tmp').write_text('ignored build file');check(release.local_release()==expected,'ignored local output is not deployed in place of GitHub bytes')
 sha=expected['revision']
 passed={'head_sha':sha,'head_branch':'main','event':'push','conclusion':'success'}
 release.checked_on_github(sha,lambda u:{'sha':sha} if u.endswith('/commits/main') else {'workflow_runs':[passed]})
 check(True,'a successful workflow is bound to the exact main commit')
 check(fails(lambda:release.checked_on_github(sha,lambda u:{'sha':'b'*40})),'a different GitHub main is rejected before sending the maintenance key')
 check(fails(lambda:release.checked_on_github(sha,lambda u:{'sha':sha} if u.endswith('/commits/main') else {'workflow_runs':[{**passed,'conclusion':'failure'}]})),'failed CI does not permit deployment')
 request=urllib.request.Request(release.TARGET,data=b'fixture=private')
 check(fails(lambda:release.NoRedirects().redirect_request(request,None,302,'redirect',{},'https://example.invalid/')),'redirects cannot forward a maintenance request to another host')
 (p/'assets/link.js').symlink_to('app.js');git('add','.');git('-c','user.name=Fixture','-c','user.email=fixture@example.test','commit','-qm','link fixture')
 check(fails(release.local_release),'symlinks are not accepted as application release files')
print(f'\n{n} release-client preflight checks passed. Local Git and HTTP-policy fixtures only.')
