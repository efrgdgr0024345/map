#!/usr/bin/env python3
"""Test the real loader CLI in an isolated folder without network or key output.

Usage: PHP_BINARY=/path/to/php python3 tests/loader_cli.py
Only Python's standard library and the application's PHP extensions are needed.
"""
import fcntl
import json
import os
from pathlib import Path
import re
import secrets
import shutil
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
              and '--show-setup-token' in help_result.stdout,
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
        check(incomplete.returncode == 1 and not incomplete.stderr
              and result['complete'] is False and result['readiness']['ready'] is False
              and 'Application files are incomplete' in result['error'],
              'real refresh command exits 1 with structured incomplete-application results')
        check(isinstance(result['steps'], list) and len(result['steps']) >= 1
              and any(step['status'] == 'failed' for step in result['steps'])
              and token not in incomplete.stdout,
              'failed refresh reports its failed step without exposing the maintenance key')
        status_file = root / 'var/setup-status.php'
        saved = json.loads(status_file.read_text().split('\n', 1)[1])
        check(saved['complete'] is False and saved['error'] == result['error']
              and not (root / 'bootstrap.php').exists(),
              'CLI refresh saves retryable status and never downloads application files')

        # PHP flock and Python fcntl share the same local advisory lock mechanism.
        # These commands must fail before downloading or importing anything.
        with open(root / 'var/loader.lock', 'a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            for flag in ['--refresh', '--install', '--update']:
                locked = run(flag)
                failure = json.loads(locked.stdout)
                check(locked.returncode == 1 and failure['complete'] is False
                      and failure['steps'] == [] and 'Another installation' in failure['error'],
                      flag + ' respects the shared maintenance lock before any work')
            locked_key = run('--show-setup-token')
            check(locked_key.returncode == 1 and not locked_key.stdout
                  and 'Another installation' in locked_key.stderr,
                  'initial key retrieval also respects the shared maintenance lock')
            fcntl.flock(lock, fcntl.LOCK_UN)

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

        print(f'\n{checks} loader CLI checks passed. No network calls or key values were logged.')


if __name__ == '__main__':
    main()
