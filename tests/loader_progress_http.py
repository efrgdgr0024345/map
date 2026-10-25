#!/usr/bin/env python3
"""Check authenticated loader progress over two isolated PHP web workers.

Only the importer is a delayed fixture. The actual loader handles sessions,
CSRF, operation locks, status serialization and async POSTs. Both servers use
the same session/storage directory so successful fast polling proves the long
operation released its session lock. No provider request or key is logged.

Usage: PHP_BINARY=/path/to/php python3 tests/loader_progress_http.py
"""
from concurrent.futures import ThreadPoolExecutor
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request


GUARD = '<?php http_response_code(404); exit; ?>\n'


def fixture_database(path):
    with sqlite3.connect(path) as db:
        db.executescript('''
            CREATE TABLE meta(key TEXT PRIMARY KEY,value TEXT);
            CREATE TABLE stops(id TEXT PRIMARY KEY);
            CREATE TABLE routes(id TEXT PRIMARY KEY);
            CREATE TABLE trips(id TEXT PRIMARY KEY,service_id TEXT);
            CREATE TABLE stop_times(id TEXT);
            CREATE TABLE connections(id TEXT);
            CREATE TABLE calendar(service_id TEXT,mon INTEGER,tue INTEGER,
                wed INTEGER,thu INTEGER,fri INTEGER,sat INTEGER,sun INTEGER,
                start TEXT,end TEXT);
            CREATE TABLE exceptions(service_id TEXT,date TEXT,type INTEGER);
            INSERT INTO stops VALUES('fixture-stop');
            INSERT INTO routes VALUES('fixture-route');
            INSERT INTO trips VALUES('fixture-trip','fixture-service');
            INSERT INTO stop_times VALUES('fixture-time');
            INSERT INTO connections VALUES('fixture-connection');
            INSERT INTO calendar VALUES('fixture-service',1,1,1,1,1,1,1,
                '20000101','20991231');
        ''')
        db.executemany('INSERT INTO meta VALUES(?,?)', [
            ('start_date', '20000101'), ('end_date', '20991231'),
            ('imported_at', '2026-10-25T00:00:00+08:00'),
            ('source', 'Synthetic HTTP progress fixture only'),
        ])


def reserve_port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


def main():
    php = os.environ.get('PHP_BINARY', 'php')
    source = Path(__file__).resolve().parents[1] / 'loader.php'
    checks = 0

    def check(condition, message):
        nonlocal checks
        if not condition:
            # Never include captured HTML/JSON: a failing security test may have
            # captured the very credential it is intended to protect.
            raise AssertionError(message)
        checks += 1
        print('PASS: ' + message)

    with tempfile.TemporaryDirectory(prefix='perth-loader-progress-http-') as temp:
        root = Path(temp)
        shutil.copyfile(source, root / 'loader.php')
        (root / 'sessions').mkdir()
        manifest = json.loads(subprocess.check_output([
            php, '-r', "define('PM_LOADER_LIBRARY_ONLY',true); "
            "require $argv[1]; echo json_encode(pm_application_manifest());",
            str(root / 'loader.php'),
        ], text=True))
        environment = {key: value for key, value in os.environ.items()
                       if key != 'PERTH_MAP_SETUP_TOKEN'}
        ports = [reserve_port(), reserve_port()]
        while ports[1] == ports[0]:
            ports[1] = reserve_port()
        processes, logs = [], []
        try:
            for index, port in enumerate(ports):
                log = open(root / f'worker-{index}.log', 'w')
                logs.append(log)
                processes.append(subprocess.Popen([
                    php, '-d', 'session.save_path=' + str(root / 'sessions'),
                    '-d', 'disable_functions=curl_init',
                    '-S', f'127.0.0.1:{port}', '-t', str(root),
                ], stdout=log, stderr=log, env=environment))
            bases = [f'http://127.0.0.1:{port}/loader.php' for port in ports]

            def request(url, data=None, opener=None, cookie=None, timeout=10):
                headers = {}
                if cookie:
                    headers['Cookie'] = cookie
                payload = None if data is None else urllib.parse.urlencode(data).encode()
                req = urllib.request.Request(url, payload, headers)
                try:
                    response = (opener.open(req, timeout=timeout) if opener
                                else urllib.request.urlopen(req, timeout=timeout))
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    return response.status, response.read().decode(), response.headers

            for base, process in zip(bases, processes):
                for _ in range(80):
                    try:
                        status, body, _ = request(base + '?progress=1')
                        break
                    except urllib.error.URLError:
                        if process.poll() is not None:
                            raise RuntimeError('Fixture PHP worker did not start')
                        time.sleep(.05)
                else:
                    raise RuntimeError('Fixture PHP worker did not become ready')
                check(status == 403 and 'error' in json.loads(body),
                      'progress status requires authentication before setup')
            check(not (root / 'var').exists(),
                  'unauthenticated status polling does not create a setup token or storage')

            clients = []
            for _ in range(2):
                jar = http.cookiejar.CookieJar()
                clients.append((jar, urllib.request.build_opener(
                    urllib.request.HTTPCookieProcessor(jar))))
            _, html, _ = request(bases[0], opener=clients[0][1])
            token = json.loads((root / 'var/setup-token.php').read_text().split('\n', 1)[1])['setup_token']
            check(token not in html, 'normal loader bootstrap does not reveal its key')

            def csrf(html):
                match = re.search(r'name="csrf" value="([^"]+)"', html)
                if not match:
                    raise AssertionError('Expected maintenance CSRF form')
                return match.group(1)

            forms = []
            for index, (_, client) in enumerate(clients):
                if index:
                    _, html, _ = request(bases[1], opener=client)
                _, html, headers = request(bases[index], {
                    'csrf': csrf(html), 'action': 'login', 'key': token,
                }, opener=client)
                check('Maintenance unlocked' in html and token not in html,
                      'independent maintenance session authenticates without key output')
                forms.append(csrf(html))
            check(not (root / 'var/setup-token.php').exists(),
                  'login removes the one-time bootstrap token')
            csp = headers.get('Content-Security-Policy', '')
            check("connect-src 'self'" in csp and re.search(r"script-src[^;]*'nonce-[^']+'", csp)
                  and not re.search(r"script-src[^;]*'unsafe-inline'", csp),
                  'progress UI permits same-origin polling with a nonce-based script policy')

            # Build only the isolated application presence/readiness fixture. No
            # real importer is loaded and curl_init is disabled in both workers.
            for name in manifest:
                if name in ('loader.php', 'bootstrap.php'):
                    continue
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('Fixture application presence only.\n')
            fixture_database(root / 'var/transit.sqlite')
            (root / 'bootstrap.php').write_text('''<?php
declare(strict_types=1);
namespace { date_default_timezone_set('Australia/Perth'); define('APP_VAR',__DIR__.'/var'); }
namespace App {
    final class GtfsImporter {
        public function import(?string $local=null,?callable $progress=null): array {
            if (is_file(APP_VAR.'/fixture-import-fail')) {
                throw new \\RuntimeException('Synthetic timetable import failure');
            }
            if ($progress) { $progress([
                'stage'=>'import','key'=>'stop_times','label'=>'Importing synthetic stop times',
                'message'=>'Synthetic fixture is waiting; no provider is contacted.',
                'percent'=>50,'completed'=>1250,'total'=>2500,'unit'=>'rows',
                'setup_token'=>'PRIVATE-FIXTURE-MATERIAL','password_hash'=>'PRIVATE-FIXTURE-MATERIAL',
            ]); }
            file_put_contents(APP_VAR.'/fixture-import-started','yes');
            usleep(4000000);
            if ($progress) { $progress(['stage'=>'complete','label'=>'Synthetic rows imported',
                'message'=>'Synthetic import complete.','percent'=>100]); }
            return ['counts'=>['stops'=>1,'routes'=>1,'trips'=>1,'stop_times'=>1],
                'validUntil'=>'20991231'];
        }
    }
}
''')
            cookies = ['; '.join(f'{item.name}={item.value}' for item in jar)
                       for jar, _ in clients]
            for private in ['setup-token.php', 'maintenance.php', 'maintenance-progress.php']:
                # This first call also checks nonexistent guarded paths never
                # disclose auth state; the progress-file test repeats below.
                status, body, _ = request(f'http://127.0.0.1:{ports[1]}/var/{private}')
                check(status == 404 and token not in body,
                      private + ' is not publicly readable')

            with ThreadPoolExecutor(max_workers=1) as pool:
                started = time.monotonic()
                operation = pool.submit(request, bases[0], {
                    'csrf': forms[0], 'action': 'import', 'live': '1',
                }, None, cookies[0], 15)
                marker = root / 'var/fixture-import-started'
                while not marker.exists() and time.monotonic() - started < 3:
                    if operation.done():
                        break
                    time.sleep(.03)
                check(marker.is_file() and not operation.done(),
                      'async import enters the actual loader operation while the fixture waits')
                before_poll = time.monotonic()
                status, body, poll_headers = request(bases[1] + '?progress=1', cookie=cookies[0])
                elapsed = time.monotonic() - before_poll
                current = json.loads(body)
                check(status == 200 and elapsed < 1.5 and not operation.done(),
                      'authenticated polling completes while the same session runs a long operation')
                check(current['running'] and not current['interrupted']
                      and current['progress']['status'] == 'running',
                      'status reflects the held OS lock and active job truthfully')
                check(current['progress']['completed'] == 1250
                      and current['progress']['total'] == 2500
                      and current['progress']['unit'] == 'rows'
                      and current['progress'].get('percent', 0) < 100,
                      'live status reports real callback counters without premature completion')
                maintenance_hash = json.loads((root / 'var/maintenance.php').read_text().split('\n', 1)[1])['password_hash']
                check(token not in body and maintenance_hash not in body
                      and 'PRIVATE-FIXTURE-MATERIAL' not in body
                      and 'password_hash' not in body and 'setup_token' not in body,
                      'progress serialization excludes credentials and unknown private event fields')
                check('no-store' in poll_headers.get('Cache-Control', '')
                      and 'application/json' in poll_headers.get('Content-Type', ''),
                      'status responses are private uncached JSON')
                report = root / 'var/maintenance-progress.php'
                before_competing = hashlib.sha256(report.read_bytes()).digest()
                status, body, _ = request(bases[1], {
                    'csrf': forms[1], 'action': 'import', 'live': '1',
                }, cookie=cookies[1])
                competing = json.loads(body)
                check(status in (400, 409) and not competing.get('complete')
                      and 'Another installation' in competing.get('error', ''),
                      'an independently authenticated competing import is rejected as busy')
                check(hashlib.sha256(report.read_bytes()).digest() == before_competing,
                      'a rejected competing job cannot replace the active progress report')
                status, body, _ = operation.result(timeout=15)
                result = json.loads(body)['result']
                check(status == 200 and result['complete']
                      and result['readiness']['ready'] and time.monotonic() - started >= 3.8,
                      'async setup returns final JSON only after real readiness succeeds')

            status, body, _ = request(bases[1] + '?progress=1', cookie=cookies[0])
            finished = json.loads(body)
            check(status == 200 and not finished['running'] and not finished['interrupted']
                  and finished['progress']['status'] == 'complete'
                  and finished['progress']['percent'] == 100,
                  'completed operation releases the lock and reports a terminal 100 percent')
            status, body, _ = request(f'http://127.0.0.1:{ports[1]}/var/maintenance-progress.php')
            check(status == 404 and token not in body,
                  'the saved progress report remains guarded against direct HTTP reads')
            _, html, _ = request(bases[1], cookie=cookies[0])
            before_bad = hashlib.sha256(report.read_bytes()).digest()
            status, body, _ = request(bases[1], {
                'csrf': 'incorrect-fixture-csrf', 'action': 'import', 'live': '1',
            }, cookie=cookies[0])
            rejected = json.loads(body)
            check(status in (400, 403, 409) and not rejected.get('result')
                  and 'expired' in rejected.get('error', ''),
                  'async mutation still requires a valid CSRF token')
            check(hashlib.sha256(report.read_bytes()).digest() == before_bad,
                  'CSRF rejection does not start or rewrite an operation')
            (root / 'var/fixture-import-fail').write_text('yes')
            status, body, _ = request(bases[1], {
                'csrf': csrf(html), 'action': 'import', 'live': '1',
            }, cookie=cookies[0])
            failed = json.loads(body)['result']
            check(status in (200, 400, 500) and not failed['complete'] and failed['readiness']['ready'],
                  'failed refresh returns failure while retaining the previous usable timetable')
            _, body, _ = request(bases[1] + '?progress=1', cookie=cookies[0])
            stopped = json.loads(body)
            check(not stopped['running'] and not stopped['interrupted']
                  and stopped['progress']['status'] == 'failed'
                  and stopped['progress'].get('percent', 0) < 100,
                  'failed work releases the lock without reporting successful completion')
            status, body, _ = request(bases[1] + '?progress=1')
            check(status == 403 and token not in body and 'progress' not in json.loads(body),
                  'another unauthenticated browser cannot inspect saved progress')
            print(f'\n{checks} loader HTTP progress checks passed. '
                  'Delayed importer fixtures only; no provider requests or key output.')
        finally:
            for process in processes:
                process.terminate()
            for process in processes:
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()
            for log in logs:
                log.close()


if __name__ == '__main__':
    main()
