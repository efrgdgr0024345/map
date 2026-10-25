#!/usr/bin/env python3
"""Test the real loader CLI in an isolated folder without network or key output.

Usage: PHP_BINARY=/path/to/php python3 tests/loader_cli.py
Only Python's standard library and the application's PHP extensions are needed.
"""
import fcntl
import datetime
import json
import os
from pathlib import Path
import re
import secrets
import selectors
import shutil
import sqlite3
import subprocess
import tempfile


def main():
    php = os.environ.get('PHP_BINARY', 'php')
    source = Path(__file__).resolve().parents[1] / 'loader.php'
    checks = 0

    def check(passed, message):
        nonlocal checks
        if not passed:
            # Never interpolate captured subprocess output: it may contain a setup key.
            raise AssertionError(message)
        checks += 1
        print('PASS: ' + message)

    with tempfile.TemporaryDirectory(prefix='perth-loader-cli-') as temporary:
        root = Path(temporary)
        shutil.copyfile(source, root / 'loader.php')
        environment = {key: value for key, value in os.environ.items()
                       if key != 'PERTH_MAP_SETUP_TOKEN'}

        def run(*flags, extra_env=None):
            configured = environment.copy()
            if extra_env:
                configured.update(extra_env)
            return subprocess.run([php, str(root / 'loader.php'), *flags], cwd=root,
                                  env=configured, capture_output=True, text=True,
                                  timeout=15, check=False)

        bare = run()
        check(bare.returncode == 0 and 'Usage: php loader.php' in bare.stdout
              and not bare.stderr, 'bare loader invocation explains its commands')
        help_result = run('--help')
        check(help_result.returncode == 0 and '--install' in help_result.stdout
              and '--update' in help_result.stdout and '--refresh' in help_result.stdout
              and '--show-setup-token' in help_result.stdout and '--status' in help_result.stdout,
              'CLI help documents installation, updates, refresh and initial key retrieval')
        check(run('-h').returncode == 0, 'short help flag is supported')
        check(list(root.iterdir()) == [root / 'loader.php'],
              'help commands do not modify a loader-only deployment')
        for flags in [('--unknown',), ('--check', '--refresh')]:
            invalid = run(*flags)
            check(invalid.returncode == 2 and not invalid.stdout
                  and 'Unknown or combined commands' in invalid.stderr,
                  'invalid command exits 2 without setup for ' + ' '.join(flags))
        check(list(root.iterdir()) == [root / 'loader.php'],
              'invalid commands do not create private state or install files')
        empty_status = run('--status')
        empty_report = json.loads(empty_status.stdout)
        check(empty_status.returncode == 0 and not empty_status.stderr
              and empty_report['running'] is False and empty_report['progress'] is None
              and list(root.iterdir()) == [root / 'loader.php'],
              'read-only status works before setup without creating private files')
        requirements = run('--check')
        check(requirements.returncode == 0 and requirements.stdout.count('[OK]') >= 5
              and '[MISSING]' not in requirements.stdout and not requirements.stderr,
              'requirements command validates this PHP runtime and exits successfully')

        first_key = run('--show-setup-token')
        check(first_key.returncode == 0 and not first_key.stderr
              and re.fullmatch(r'[0-9a-f]{64}\n', first_key.stdout) is not None,
              'initial key command returns only a generated key in captured terminal output')
        token = first_key.stdout.strip()
        token_file = root / 'var/setup-token.php'
        private = json.loads(token_file.read_text().split('\n', 1)[1])
        check(private['setup_token'] == token,
              'captured terminal key matches protected bootstrap state without logging it')
        again = run('--show-setup-token')
        check(again.returncode == 0 and again.stdout.strip() == token,
              'retrieving an unused initial key is stable rather than regenerating credentials')

        incomplete = run('--refresh')
        result = json.loads(incomplete.stdout)
        check(incomplete.returncode == 1 and bool(incomplete.stderr.strip())
              and result['complete'] is False and result['readiness']['ready'] is False
              and 'Application files are incomplete' in result['error'],
              'real refresh command exits 1 with structured incomplete-application results')
        check(token not in incomplete.stderr and '100%' not in incomplete.stderr,
              'failed setup emits progress separately from JSON without a key or false completion')
        check(isinstance(result['steps'], list) and len(result['steps']) >= 1
              and any(step['status'] == 'failed' for step in result['steps'])
              and token not in incomplete.stdout,
              'failed refresh reports its failed step without exposing the maintenance key')
        status_file = root / 'var/setup-status.php'
        saved = json.loads(status_file.read_text().split('\n', 1)[1])
        check(saved['complete'] is False and saved['error'] == result['error']
              and not (root / 'bootstrap.php').exists(),
              'CLI refresh saves retryable status and never downloads application files')

        phones = run('--refresh-phones')
        phone_result = json.loads(phones.stdout)
        check(phones.returncode == 1 and phone_result['complete'] is False
              and 'Install or update' in phone_result['error'] and token not in phones.stdout,
              'phone refresh requires installed application files without exposing the setup key')

        # PHP flock and Python fcntl share the same local advisory lock mechanism.
        # These commands must fail before downloading or importing anything.
        with open(root / 'var/loader.lock', 'a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            progress_file = root / 'var/maintenance-progress.php'
            stamp = datetime.datetime.now(datetime.timezone.utc).isoformat()
            fixture_progress = {'runId': secrets.token_hex(8), 'action': 'update', 'pid': os.getpid(),
                                'startedAt': stamp, 'updatedAt': stamp, 'stage': 'import:shapes',
                                'label': 'Importing route shapes', 'message': 'Fixture row progress.',
                                'percent': 72, 'completed': 1000000, 'unit': 'rows', 'status': 'running',
                                'setup_token': token, 'password_hash': 'fixture-private-hash'}
            progress_file.write_text('<?php http_response_code(404); exit; ?>\n'
                                     + json.dumps(fixture_progress))
            progress_before = progress_file.read_bytes()
            active_status = run('--status')
            report = json.loads(active_status.stdout)
            check(active_status.returncode == 0 and not active_status.stderr
                  and report['running'] is True and report['interrupted'] is False
                  and report['progress']['completed'] == 1000000
                  and token not in active_status.stdout and 'password_hash' not in active_status.stdout,
                  'status remains available during an active job and exposes only safe progress')
            for flag in ['--refresh', '--install', '--update', '--refresh-phones']:
                locked = run(flag)
                failure = json.loads(locked.stdout)
                check(locked.returncode == 1 and failure['complete'] is False
                      and failure['steps'] == [] and 'Another installation' in failure['error'],
                      flag + ' respects the shared maintenance lock before any work')
            check(progress_file.read_bytes() == progress_before,
                  'competing maintenance commands leave the active progress snapshot unchanged')
            locked_key = run('--show-setup-token')
            check(locked_key.returncode == 1 and not locked_key.stdout
                  and 'Another installation' in locked_key.stderr,
                  'initial key retrieval also respects the shared maintenance lock')
            fcntl.flock(lock, fcntl.LOCK_UN)
        interrupted = json.loads(run('--status').stdout)
        check(interrupted['running'] is False and interrupted['interrupted'] is True
              and interrupted['progress']['status'] == 'interrupted'
              and (root / 'var/loader.lock').exists(),
              'a released lock with unfinished progress reports interruption without deleting lock files')

        # The CLI refuses revealing bootstrap material as soon as maintenance is configured.
        # Password content is irrelevant to that refusal; this is deliberately a fixture value.
        maintenance = root / 'var/maintenance.php'
        maintenance.write_text('<?php http_response_code(404); exit; ?>\n'
                               + json.dumps({'password_hash': 'fixture-hash-only'}))
        configured = run('--show-setup-token')
        check(configured.returncode == 1 and not configured.stdout
              and 'maintenance is already configured' in configured.stderr
              and token not in configured.stderr,
              'initial key command cannot expose a key after maintenance is configured')
        maintenance.unlink()
        environment_key = secrets.token_hex(32)
        configured_env = run('--show-setup-token',
                             extra_env={'PERTH_MAP_SETUP_TOKEN': environment_key})
        check(configured_env.returncode == 1 and not configured_env.stdout
              and 'PERTH_MAP_SETUP_TOKEN' in configured_env.stderr
              and environment_key not in configured_env.stderr
              and token not in configured_env.stderr,
              'configured environment credentials are never revealed by the initial-key command')

        # Complete an intentionally slow local fixture import. This exercises real
        # CLI streaming/status concurrency, without any provider or application DB.
        manifest_run = subprocess.run(
            [php, '-r', 'define("PM_LOADER_LIBRARY_ONLY", true); require $argv[1]; echo json_encode(pm_application_manifest());',
             str(root / 'loader.php')], capture_output=True, text=True, timeout=10, check=True)
        for name in json.loads(manifest_run.stdout):
            if name == 'loader.php':
                continue
            target = root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text('Fixture application file.\n')
        (root / 'bootstrap.php').write_text('''<?php
namespace {
    define('APP_ROOT', __DIR__);
    define('APP_VAR', __DIR__.'/var');
}
namespace App {
    final class GtfsImporter {
        public function import(?string $local=null, ?callable $progress=null): array {
            if ($local!==null) { throw new \\RuntimeException('Unexpected local fixture path'); }
            if ($progress) { $progress(['stage'=>'import','key'=>'stop_times','label'=>'Importing fixture stop visits',
                'message'=>'Local synthetic rows only.','completed'=>1000,'total'=>2000,'unit'=>'rows','percent'=>50]); }
            usleep(3000000);
            if ($progress) { $progress(['stage'=>'complete','label'=>'Fixture timetable imported','percent'=>100]); }
            return ['counts'=>['stops'=>1,'routes'=>1,'trips'=>1,'stop_times'=>1]];
        }
    }
    final class Payphones {
        public const COVERAGE=[115,-33,117,-31];
        public static function inBounds(array $bounds): array {
            return ['updatedAt'=>date(DATE_ATOM),'phones'=>[['id'=>'synthetic']], 'source'=>'Fixture phone cache'];
        }
    }
}
''')
        today = datetime.date.today()
        start = (today - datetime.timedelta(days=7)).strftime('%Y%m%d')
        end = (today + datetime.timedelta(days=7)).strftime('%Y%m%d')
        with sqlite3.connect(root / 'var/transit.sqlite') as db:
            db.executescript('''CREATE TABLE meta(key TEXT PRIMARY KEY,value TEXT);
                CREATE TABLE stops(id TEXT); INSERT INTO stops VALUES('A');
                CREATE TABLE routes(id TEXT); INSERT INTO routes VALUES('R');
                CREATE TABLE trips(id TEXT,service_id TEXT); INSERT INTO trips VALUES('T','DAILY');
                CREATE TABLE stop_times(trip_id TEXT); INSERT INTO stop_times VALUES('T');
                CREATE TABLE connections(trip_id TEXT); INSERT INTO connections VALUES('T');
                CREATE TABLE calendar(service_id TEXT,start TEXT,end TEXT,mon INTEGER,tue INTEGER,wed INTEGER,thu INTEGER,fri INTEGER,sat INTEGER,sun INTEGER);
                CREATE TABLE exceptions(service_id TEXT,date TEXT,type INTEGER);''')
            db.executemany('INSERT INTO meta VALUES(?,?)', [('start_date', start), ('end_date', end), ('imported_at', stamp)])
            db.execute('INSERT INTO calendar VALUES(?,?,?,1,1,1,1,1,1,1)', ('DAILY', start, end))
        streaming = subprocess.Popen([php, '-d', 'disable_functions=curl_init', str(root / 'loader.php'), '--refresh'],
                                     cwd=root, env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                     text=True, bufsize=1)
        try:
            with selectors.DefaultSelector() as selector:
                selector.register(streaming.stderr, selectors.EVENT_READ)
                check(bool(selector.select(timeout=2)), 'CLI emits progress before a slow import completes')
            first_line = streaming.stderr.readline()
            check(streaming.poll() is None and token not in first_line,
                  'progress is flushed while the real fixture process remains active')
            during = json.loads(run('--status').stdout)
            check(during['running'] is True and during['progress']['status'] == 'running'
                  and during['progress']['percent'] < 100,
                  'another terminal can inspect an active CLI import without waiting for its lock')
            stdout, remaining_stderr = streaming.communicate(timeout=12)
            completed = json.loads(stdout)
            transcript = first_line + remaining_stderr
            check(streaming.returncode == 0 and completed['complete'] is True
                  and completed['readiness']['ready'] is True and token not in stdout,
                  'successful CLI streaming retains one final machine-readable JSON result')
            check('Importing fixture stop visits' in transcript and 'rows' in transcript
                  and '100%' in transcript and token not in transcript,
                  'terminal progress includes useful counters and completion without credentials')
        finally:
            if streaming.poll() is None:
                streaming.terminate()
                streaming.wait(timeout=5)
        final_status = json.loads(run('--status').stdout)
        check(final_status['running'] is False and final_status['interrupted'] is False
              and final_status['progress']['status'] == 'complete',
              'successful import leaves an accurate completed status after its process exits')

        print(f'\n{checks} loader CLI checks passed. No network calls or key values were logged.')


if __name__ == '__main__':
    main()
