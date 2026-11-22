#!/usr/bin/env python3
"""Existing passkey/session/CSRF release client, against an isolated local host."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('release_client', ROOT/'scripts/release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
php = os.environ.get('PHP_BINARY', 'php')
count = 0


def check(ok, label):
    global count
    if not ok:
        raise AssertionError(label)  # No captured response or passkey in output.
    count += 1
    print('PASS:', label)


with tempfile.TemporaryDirectory(prefix='nextstop-release-http-') as folder:
    root = Path(folder)
    (root/'var').mkdir()
    (root/'sessions').mkdir()
    source = (ROOT/'loader.php').read_text()
    a = source.index('function pm_install_archive(')
    b = source.index('/** Lightweight installed-data', a)
    # Explicit local source/package fixture, never an alternative production URL.
    source = source[:a]+'''function pm_install_archive(?string $expected=null): array {
        $release=pm_read_private(PM_LOADER_PRIVATE.'/fixture-release.php');
        if ($expected!==$release['revision']) { throw new RuntimeException('Fixture commit differs'); }
        return pm_apply_archive(PM_LOADER_PRIVATE.'/fixture.zip',$release);
    }
    '''+source[b:]
    a = source.index('function pm_run_setup(')
    b = source.index('function pm_phone_cache(', a)
    source = source[:a]+'''function pm_run_setup(bool $downloadApplication=true,?string $revision=null): array {
        $installed=pm_install_archive($revision);
        return ['complete'=>true,'release'=>$installed['release']];
    }
    '''+source[b:]
    (root/'loader.php').write_text(source)
    manifest = json.loads(subprocess.check_output([php,'-r',
        'define("PM_LOADER_LIBRARY_ONLY",true);require $argv[1];echo json_encode(pm_application_manifest());', str(root/'loader.php')]))
    files = {path: ('fixture '+path).encode() for path in manifest}
    files['loader.php'] = source.encode()
    revision = 'a'*40
    entries = {path: {'path': path, 'type': 'blob', 'mode': '100644', 'size': len(content),
                     'sha': hashlib.sha1(b'blob '+str(len(content)).encode()+b'\0'+content).hexdigest()}
               for path, content in files.items()}
    record = {'revision': revision, 'tree': 'b'*40, 'files': entries,
              'checksUrl': 'https://github.com/efrgdgr0024345/map/actions/runs/123'}
    guard = '<?php http_response_code(404); exit; ?>\n'
    (root/'var/fixture-release.php').write_text(guard+json.dumps(record))
    with zipfile.ZipFile(root/'var/fixture.zip','w') as archive:
        for path, content in files.items():
            archive.writestr('fixture/'+path, content)
    expected = {'revision': revision, 'manifestDigest': release.digest({p: hashlib.sha256(c).hexdigest() for p,c in files.items()}), 'fileCount': len(files)}
    with socket.socket() as listener:
        listener.bind(('127.0.0.1',0)); port = listener.getsockname()[1]
    key = 'fixture-only-release-passkey-not-production'
    env = os.environ.copy(); env['PERTH_MAP_SETUP_TOKEN'] = key
    with open(root/'server.log','w') as log:
        process = subprocess.Popen([php,'-d','session.save_path='+str(root/'sessions'),'-S',f'127.0.0.1:{port}','-t',str(root)],stdout=log,stderr=log,env=env)
        try:
            host = release.Maintenance(f'http://127.0.0.1:{port}/loader.php')
            for _ in range(60):
                try:
                    _, html = host.csrf(); break
                except (OSError, urllib.error.URLError):
                    time.sleep(.05)
            else:
                raise RuntimeError('Local fixture host failed to start')
            check(key not in html,'maintenance does not expose the passkey in HTML')
            status, body = host.request({'action':'verify-release','csrf':host.csrf()[0],'live':'1'})
            check(status==403,'unauthenticated clients cannot inspect release verification')
            try:
                host.login('wrong-fixture-passkey'); check(False,'wrong key refused')
            except RuntimeError:
                check(True,'an incorrect passkey cannot deploy')
            host.login(key)
            status, body = host.request({'action':'verify-release','csrf':'bad','live':'1'})
            check(status==409,'the existing CSRF check also protects release operations')
            try:
                host.action('install',confirm='yes',revision='c'*40); check(False,'wrong commit refused')
            except RuntimeError:
                check(True,'a request cannot silently deploy a different commit')
            result = host.action('install',confirm='yes',revision=revision)
            check(result.get('complete') and release.matching(result['release'],expected),'passkey deployment returns the exact expected commit and file digest')
            verified = host.action('verify-release')
            check(release.matching(verified,expected),'fresh authenticated verification matches the independent client manifest')
            check(not release.matching(verified,{**expected,'manifestDigest':'0'*64}),'the client rejects a server digest different from GitHub source')
            (root/'assets/app.js').write_text('manual production edit')
            try:
                host.action('verify-release'); check(False,'tamper refused')
            except RuntimeError:
                check(True,'a changed application file prevents a verified-deployment claim')
            result = host.action('install',confirm='yes',revision=revision)
            check(release.matching(result['release'],expected),'reinstalling the pinned commit repairs a manually edited managed file')
            try:
                release.Maintenance('https://example.invalid/steal-key'); check(False,'foreign host refused')
            except RuntimeError:
                check(True,'the maintenance credential cannot be sent to an arbitrary host')
            check(not (root/'var/setup-token.php').exists(),'environment-provided authentication does not generate a plaintext bootstrap key')
        finally:
            process.terminate(); process.wait(timeout=5)
print(f'\n{count} passkey release/client HTTP checks passed. Local source/auth/package fixtures only.')
