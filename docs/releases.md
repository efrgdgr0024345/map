# Checked source → GitHub → passkey deployment → verified live files

This is the standing delivery workflow for Nextstop. Repository-root `AGENTS.md`
keeps it in the instructions used by future coding sessions. The fixed source is
`efrgdgr0024345/map`, branch `main`; the fixed deployment target is
`https://easyai.com.au/bus/loader.php`.

## Routine releases

1. Implement and review the change, run appropriate PHP/browser checks and commit
   the exact source. Push that commit to `main` and wait for its successful push
   run of `Check application and installer`.
2. Run `python3 scripts/release.py --deploy` from the project. The working tree
   must be clean, and its full commit must equal GitHub main. The client checks
   CI before authenticating; the server independently repeats the commit/CI gate.
3. The client logs into the existing maintenance console using the passkey,
   cookies and CSRF. It submits the exact commit, not a local ZIP or a new source
   URL. Redirects are refused; HTTPS verification stays enabled.
4. The loader resolves the commit's actual Git tree SHA. It fetches immutable
   source URLs and verifies Git blob hashes. Existing installations download only
   changed/missing bytes. Fresh installations fetch a commit-pinned archive and
   verify its complete file list and contents against the same Git tree.
5. Files are staged, backups verified, and previously recorded retired files
   backed up before removal. The loader is replaced last. Every resulting managed
   file is then hashed with SHA-256, including unchanged files and the loader.
   A mismatch prevents a success result and triggers restoration.
6. The protected `var/release.php` records the full commit, tree, CI run, file
   hashes, manifest digest and installation time. The client compares that result
   with an independently computed digest of the exact local Git objects, then
   requests a fresh authenticated verification. Only matching results count as
   deployed. Smoke-check the public app and report the release commit.

Do not describe a GitHub push, an upload, a timetable-only refresh or a remembered
commit number as proof of live source identity.

## Configure the passkey once

The server already uses `PERTH_MAP_SETUP_TOKEN` or a password hash in protected
`var/maintenance.php`. Continue using that maintenance key. Configure its value
as **`NEXTSTOP_MAINTENANCE_KEY` in the agent/cloud environment's private secrets**.
These are two names on different machines for the same credential; configuring
it on the server alone does not inject it into a coding session.

Do not hardcode the value in Python/PHP, commit it in GitHub, put it in command
arguments/URLs, or paste it into release logs. The release client reads it from
the environment, removes it from child-process environments and sends it only to
the fixed maintenance login over HTTPS. It keeps session cookies in memory.

The host needs the current published `loader.php` once to support this protocol.
Use the existing passkey updater or cPanel to install that published loader; if
an older loader performed that bootstrap, run its new update action again to
create the first verified release record. The client refuses an old protocol
rather than silently declaring its unverified installation complete. Subsequent
routine releases use the same automated command and require no new approvals.

If the key is absent, the client fails with the exact missing environment-secret
name before deployment. Finish the checked GitHub publication and report that
actual access limitation once. Do not reset the owner's key to acquire access.

## Inspection and recovery

- `python3 scripts/release.py --inspect`: describe clean local HEAD, its file
  count and SHA-256 manifest digest without a key or host request.
- `python3 scripts/release.py --verify-only`: authenticate and verify the live
  files against checked GitHub/local HEAD without updating code.
- `php loader.php --verify-release`: rehash installed application files from
  cPanel Terminal, with structured output and a failing exit code on mismatch.
- **Verify installed files** in the unlocked maintenance console performs the
  same fresh verification. The console also shows the recorded commit.

Unexpected source/executable files anywhere in the application, or unknown files
inside `assets`, `src`, `scripts` or `tests`, prevent a verified update. Unknown
files are reported rather than deleted. Review/quarantine legacy stray files in
cPanel before adopting strict releases. Only files listed in an earlier verified
manifest can be retired automatically, with a backup in `var/backups/`.

A normal update repairs changed/missing managed files. Interrupted requests must
be checked rather than assumed successful; the next retry compares actual bytes.
Application writes use atomic file replacements and rollback on handled errors;
they are not one atomic filesystem swap across the entire running site. Keep
backups for manual recovery if hosting kills a process or denies restoration.

## Identity boundary and evidence

The digest is SHA-256 over sorted `path + NUL + file SHA-256 + newline` entries.
It covers the deployed Git files except private `var/`, Git metadata, `.github/`,
`config.php`, `.env*`, `.user.ini` and `php.ini`. Those exclusions are the explicit
runtime/hosting data boundary. SQLite, source caches, credentials and owner
configuration are not alternative application builds.

This establishes matching application bytes under the trusted hosting/GitHub
model. Passing tests and hashes are not a claim of absolute security, actual
vehicle GPS, or cryptographic remote attestation against a compromised host.

`tests/releases.php` exercises immutable commit/CI binding, file identity, local
edits, corrupt packages, unexpected files, retirement, rollback and metadata
failure. `tests/releases_http.py` checks existing passkey/session/CSRF deployment
and fresh verification against an isolated host. `tests/release_client.py` checks
clean Git preflight, independent digests, wrong CI/main and credential redirect
policy. These are disclosed local fixtures; a live release is only verified by
the authenticated production result.
