# Nextstop delivery rules — read before changing this project

The user's standing goal is a working release ready for human testing at
https://easyai.com.au/bus/. Complete routine implementation, checks, GitHub
publication and the authorized hosting update without repeatedly asking for
advice or permission. A plan or a GitHub push alone is not a live deployment.

## One source of application code

1. Make the change here and run the checks appropriate to its behaviour. Review
   the diff and protect credentials, location data and existing timetable data.
2. Commit the exact checked source and push it to `efrgdgr0024345/map`, `main`.
3. Wait for the `Check application and installer` workflow to succeed for that
   exact commit. Fix failures and publish a new checked commit when necessary.
4. Deploy that immutable commit using the existing passkey-protected
   `https://easyai.com.au/bus/loader.php`. Do not hand-edit production application
   files, deploy an uncommitted working tree or upload a different build.
5. Verify the installed commit and every managed application file against the
   GitHub source, then smoke-check the public app. Report the commit and any
   actual limitation. Call it live only after successful deployment verification.

## Deployment access and tools

- The maintenance passkey is an existing credential, not a new approval step.
  Its server-side source is `PERTH_MAP_SETUP_TOKEN` or protected
  `var/maintenance.php`. Keep it outside Git and outside generated application code.
- Agent-side deployments use `NEXTSTOP_MAINTENANCE_KEY`, injected through a
  private environment secret, with `python3 scripts/release.py --deploy`.
  `--verify-only` verifies a live release; `--inspect` describes the clean local
  commit without contacting the host or needing the key.
- Never print a key, put it in a URL, pass it as a command-line argument, commit
  it, or reuse the GitHub credential as the maintenance passkey. Use verified
  HTTPS, the fixed production URL, and the existing session/CSRF login.
- The one-time hosting bootstrap is the published `loader.php`, installed through
  the existing authenticated updater or cPanel. Do not silently work around an
  old loader's missing release-verification support.
- If the actual key/session is unavailable, finish and check the code, push it,
  and state the precise missing configuration once. Do not claim deployment,
  reset the owner's key, or ask again for already-authorized release actions.

## What “the same code” means

The installed application files must match the pinned GitHub commit byte for
byte. Record and recheck a SHA-256 file manifest, including the loader. Only
previously recorded application files may be retired automatically, with backups.
Unexpected application code must be reported rather than silently accepted.

Private `var/` data (including release records, credentials, caches and SQLite),
Git metadata and host-specific configuration are deliberately excluded from
application identity. These are not alternative copies of the application.
Checks and hashes establish release identity, not an absolute security guarantee.

See [docs/releases.md](docs/releases.md) for the protocol and recovery steps.
