#!/usr/bin/env python3
"""Exercise the standalone cPanel loader without touching the checkout or network.

Usage: PHP_BINARY=/path/to/php python3 tests/loader_http.py
Only Python's standard library is required. The PHP binary must include sessions.
"""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request


def main():
    php = os.environ.get('PHP_BINARY', 'php')
    source = Path(__file__).resolve().parents[1] / 'loader.php'
    with tempfile.TemporaryDirectory(prefix='perth-loader-test-') as folder:
        root = Path(folder)
        shutil.copyfile(source, root / 'loader.php')
        (root / 'sessions').mkdir()
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        # A dedicated session directory avoids relying on a host's PHP config.
        with open(root / 'server.log', 'w') as log:
            process = subprocess.Popen(
                [php, '-d', 'session.save_path=' + str(root / 'sessions'),
                 '-S', f'127.0.0.1:{port}', '-t', str(root)],
                stdout=log, stderr=log,
                env={key: value for key, value in os.environ.items()
                     if key != 'PERTH_MAP_SETUP_TOKEN'},
            )
            try:
                cookies = http.cookiejar.CookieJar()
                client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
                base = f'http://127.0.0.1:{port}/'

                def get(path='loader.php'):
                    try:
                        with client.open(base + path, timeout=10) as response:
                            return response.status, response.read().decode()
                    except urllib.error.HTTPError as error:
                        return error.code, error.read().decode()

                def post(data):
                    with client.open(base + 'loader.php',
                                     urllib.parse.urlencode(data).encode(), timeout=10) as response:
                        return response.read().decode()

                def csrf(html):
                    return re.search(r'name="csrf" value="([^"]+)"', html)[1]

                for _ in range(60):
                    try:
                        status, html = get()
                        break
                    except urllib.error.URLError:
                        if process.poll() is not None:
                            raise RuntimeError('PHP development server failed to start')
                        time.sleep(0.05)
                else:
                    raise RuntimeError('PHP development server did not become ready')

                assert status == 200 and 'Unlock maintenance' in html
                token_file = root / 'var/setup-token.php'
                token = json.loads(token_file.read_text().split('\n', 1)[1])['setup_token']
                assert token not in html, 'The setup token leaked into the page'
                assert get('var/setup-token.php')[0] == 404
                original_csrf = csrf(html)
                assert 'form expired' in post({'csrf': 'bad', 'action': 'prepare'})
                assert 'Unlock maintenance before making changes' in post({
                    'csrf': original_csrf, 'action': 'prepare'})
                assert 'key is incorrect' in post({
                    'csrf': original_csrf, 'action': 'login', 'key': 'wrong'})
                html = post({'csrf': original_csrf, 'action': 'login', 'key': token})
                assert 'Install & set up your map' in html
                assert 'name="action" value="install"' in html
                assert 'name="action" value="import"' not in html
                assert not re.search(r'name="(?:url|source_url|archive|package_url)"', html), \
                    'Maintenance must not accept an arbitrary download source'
                assert 'Open map</a>' not in html, \
                    'An empty standalone deployment must not be presented as ready'
                assert not token_file.exists(), 'Bootstrap token was not removed'
                assert get('var/maintenance.php')[0] == 404
                html = post({'csrf': csrf(html), 'action': 'install',
                             'source_url': 'https://invalid.example/arbitrary.zip'})
                assert 'Confirm replacement' in html
                assert not (root / 'index.php').exists(), \
                    'An unconfirmed update changed application files'
                html = post({'csrf': csrf(html), 'action': 'import'})
                assert 'Latest setup attempt' in html
                assert 'Setup incomplete.' in html
                assert 'Application files are incomplete.' in html
                assert 'Open map</a>' not in html
                html = post({'csrf': csrf(html), 'action': 'rotate',
                             'new_key': 'test-only-maintenance-key-with-enough-entropy'})
                assert 'Maintenance key changed' in html
                html = post({'csrf': csrf(html), 'action': 'logout'})
                assert 'Unlock maintenance' in html
                html = post({'csrf': csrf(html), 'action': 'login', 'key': token})
                assert 'key is incorrect' in html, 'The old key was still accepted'
                html = post({'csrf': csrf(html), 'action': 'login',
                             'key': 'test-only-maintenance-key-with-enough-entropy'})
                assert 'Install & set up your map' in html
                print('PASS loader-only startup, token privacy, guarded files, CSRF, '
                      'authentication, fixed download source, update confirmation, '
                      'incomplete setup reporting, key rotation, logout and relogin')
            finally:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()


if __name__ == '__main__':
    main()
