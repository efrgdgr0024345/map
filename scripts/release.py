#!/usr/bin/env python3
"""Publish/verify the exact checked GitHub commit using the existing maintenance login.

Credentials come only from NEXTSTOP_MAINTENANCE_KEY. They are never arguments,
URLs, output, repository files, or forwarded through redirects.
"""
import argparse
import hashlib
import http.cookiejar
import io
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

REPOSITORY = 'efrgdgr0024345/map'
TARGET = 'https://easyai.com.au/bus/loader.php'
ROOT = Path(__file__).resolve().parents[1]
SHA = re.compile(r'^[a-f0-9]{40}$')


def preserved(path):
    first = path.split('/')[0].lower()
    return first in {'var', '.git', '.github', 'config.php', '.env', '.user.ini', 'php.ini'} or first.startswith('.env.')


def digest(files):
    payload = ''.join(f'{path}\0{sha}\n' for path, sha in sorted(files.items()))
    return hashlib.sha256(payload.encode()).hexdigest()


def git(*arguments):
    return subprocess.check_output(['git', '-C', str(ROOT), *arguments], stderr=subprocess.PIPE)


def local_release():
    if git('status', '--porcelain', '--untracked-files=normal').strip():
        raise RuntimeError('Commit and check the working tree before releasing; uncommitted files are not deployable.')
    revision = git('rev-parse', 'HEAD').decode().strip()
    if not SHA.fullmatch(revision):
        raise RuntimeError('A full GitHub commit SHA is required.')
    entries = []
    for record in git('ls-tree', '-rz', 'HEAD').split(b'\0'):
        if not record:
            continue
        metadata, path = record.split(b'\t', 1)
        mode, kind, blob = metadata.decode().split()
        path = path.decode('utf-8')
        if preserved(path):
            continue
        if kind != 'blob' or mode not in {'100644', '100755'} or len(path)>240 or any(ord(c)<32 or ord(c)==127 or c in '\\:' for c in path) or any(p in {'','.','..'} for p in path.split('/')):
            raise RuntimeError('The release contains unsupported paths or symbolic links.')
        entries.append((path, blob))
    process = subprocess.run(['git', '-C', str(ROOT), 'cat-file', '--batch'],
                             input=''.join(blob+'\n' for _, blob in entries).encode(),
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
    stream = io.BytesIO(process.stdout)
    files = {}
    for path, blob in entries:
        header = stream.readline().decode().strip().split()
        if len(header) != 3 or header[0] != blob or header[1] != 'blob':
            raise RuntimeError('Cannot inspect the exact Git commit contents.')
        content = stream.read(int(header[2]))
        if stream.read(1) != b'\n':
            raise RuntimeError('Invalid Git object output.')
        files[path] = hashlib.sha256(content).hexdigest()
    return {'revision': revision, 'manifestDigest': digest(files), 'fileCount': len(files)}


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        raise RuntimeError('Deployment redirects are refused; credentials stay on the configured host.')


class Maintenance:
    def __init__(self, target=TARGET):
        # Loopback is allowed only for isolated tests; CLI always uses TARGET.
        parsed = urllib.parse.urlsplit(target)
        if target != TARGET and not (parsed.scheme == 'http' and parsed.hostname in {'127.0.0.1', 'localhost'}):
            raise RuntimeError('The production deployment target is fixed.')
        self.target = target
        self.client = urllib.request.build_opener(NoRedirects(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(self, fields=None):
        data = urllib.parse.urlencode(fields).encode() if fields is not None else None
        request = urllib.request.Request(self.target, data=data)
        try:
            response = self.client.open(request, timeout=660)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = response.read(8 * 1024 * 1024 + 1)
            if len(body) > 8 * 1024 * 1024:
                raise RuntimeError('The maintenance response is too large.')
            return response.status, body.decode('utf-8')

    def csrf(self):
        status, body = self.request()
        match = re.search(r'name="csrf" value="([a-f0-9]{64})"', body)
        if status != 200 or not match:
            raise RuntimeError('Cannot open the configured maintenance console.')
        return match[1], body

    def login(self, key):
        csrf, body = self.csrf()
        if 'name="nextstop-release-protocol" content="1"' not in body:
            raise RuntimeError('The host needs the published loader.php installed once before verified deployments are supported.')
        status, response = self.request({'action': 'login', 'csrf': csrf, 'key': key, 'live': '1'})
        try:
            payload = json.loads(response)
        except ValueError:
            raise RuntimeError('Maintenance did not return a release-protocol response.') from None
        if status != 200 or payload.get('error'):
            raise RuntimeError('Maintenance login failed. Check the privately configured passkey.')

    def action(self, action, **fields):
        csrf, _ = self.csrf()
        status, response = self.request({'action': action, 'csrf': csrf, 'live': '1', **fields})
        try:
            payload = json.loads(response)
        except ValueError:
            raise RuntimeError('Maintenance returned an invalid release response.') from None
        if status != 200 or payload.get('error'):
            raise RuntimeError('The host rejected the release operation; inspect the authenticated maintenance console.')
        return payload.get('result') or {}


def checked_on_github(revision, read=None):
    read = read or github_json
    base = 'https://api.github.com/repos/' + REPOSITORY
    commit = read(base+'/commits/main')
    if commit.get('sha') != revision:
        raise RuntimeError('Local HEAD must be the exact commit published on GitHub main.')
    runs = read(base+'/actions/workflows/check.yml/runs?head_sha='+revision+'&event=push&per_page=20')
    if not any(r.get('head_sha') == revision and r.get('head_branch') == 'main'
               and r.get('event') == 'push' and r.get('conclusion') == 'success'
               for r in runs.get('workflow_runs', [])):
        raise RuntimeError('Wait for successful GitHub checks on this exact commit before deployment.')


def github_json(url):
    # This opener carries neither the maintenance cookie jar nor the passkey.
    request = urllib.request.Request(url, headers={'User-Agent': 'Nextstop-Verified-Release/1.0'})
    with urllib.request.build_opener(NoRedirects()).open(request, timeout=30) as response:
        data = response.read(8 * 1024 * 1024 + 1)
        if len(data) > 8 * 1024 * 1024:
            raise RuntimeError('The GitHub release response is too large.')
        return json.loads(data)


def matching(report, expected):
    return (report.get('verified') is True and report.get('revision') == expected['revision']
            and report.get('manifestDigest') == expected['manifestDigest']
            and report.get('fileCount') == expected['fileCount'])


class SafeParser(argparse.ArgumentParser):
    def error(self, message):
        self.print_usage(sys.stderr)
        self.exit(2, 'Invalid release arguments. Use --inspect, --deploy or --verify-only; keep the passkey in environment secrets.\n')


def main():
    parser = SafeParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument('--inspect', action='store_true', help='Describe a clean local commit without a key or network.')
    modes.add_argument('--deploy', action='store_true', help='Deploy checked GitHub main, then verify its exact bytes.')
    modes.add_argument('--verify-only', action='store_true', help='Verify that the live app matches checked local HEAD.')
    args = parser.parse_args()
    key = os.environ.pop('NEXTSTOP_MAINTENANCE_KEY', '')
    if not args.inspect and not 20 <= len(key) <= 512:
        raise RuntimeError('Configure NEXTSTOP_MAINTENANCE_KEY as a private environment secret once; no deployment was attempted.')
    expected = local_release()
    if args.inspect:
        print(json.dumps(expected, indent=2)); return
    checked_on_github(expected['revision'])
    host = Maintenance(); host.login(key); key = ''
    if args.deploy:
        result = host.action('install', confirm='yes', revision=expected['revision'])
        if not result.get('complete') or not matching(result.get('release') or {}, expected):
            raise RuntimeError('Deployment did not finish with the expected verified application and timetable.')
    report = host.action('verify-release')
    if not matching(report, expected):
        raise RuntimeError('The installed commit/files differ from checked GitHub source; deployment is not verified.')
    print(json.dumps({'deployed': args.deploy, 'verified': True, **expected, 'url': 'https://easyai.com.au/bus/'}, indent=2))


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, ValueError, subprocess.CalledProcessError):
        # Never print raw subprocess, HTTP, HTML, cookie or exception contents.
        error = sys.exc_info()[1]
        print(str(error) if isinstance(error, RuntimeError) else 'Release operation failed; no verified deployment is claimed.', file=sys.stderr)
        sys.exit(1)
