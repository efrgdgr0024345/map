# Nextstop — Perth in motion

A clean, mobile-friendly Perth public transport map built with PHP, Leaflet and OpenStreetMap. Your selected bus/train has a bright **YOUR NEXT BUS / TRAIN** marker. Other vehicles on the same line stay visible, so you can see the service behind it. Unrelated lines disappear when you select a journey; a switch brings back the network.

## What works

- Phone GPS with permission, searchable start/destination addresses with suggestions, or map clicks and draggable pins.
- Map-area vehicle and stop updates every 15 seconds.
- Focused journey view, walking legs, transfers, boarding countdown and a later service alternative when found.
- Official static GTFS import into SQLite, with calendar exceptions, after-midnight services and route shapes.
- A protected root-level `loader.php` for cPanel setup, timetable refresh and GitHub updates with backups.

**Honest movement:** the app does not have an actual vehicle GPS feed. After importing GTFS, positions are interpolated between scheduled stops along route shapes. Delays, cancellations, capacity and early/late running are unknown. Before import, the app explicitly shows an illustrative **DEMO** network with invented times; those journeys must not be used for travel. Phone GPS identifies the user, not the buses.

## cPanel installation

1. Use an HTTPS domain or subfolder. Select **PHP 8.1+** and enable **PDO SQLite**, **Zip**, **cURL** and **sessions**. Apache must honour `.htaccess`. Allow roughly 1–3 GB spare storage for a large GTFS import, and use a generous CLI memory/time limit if your host requires it.
2. Upload **only `loader.php`** into that folder. You do not need to upload or extract the application ZIP, create a database, or run Composer/Node commands.
3. Open `loader.php`. Through **cPanel → File Manager**, open the generated `var/setup-token.php`. Copy the `setup_token` value into the maintenance login, and save it in a password manager. The token is never sent to the public page; its file disappears after login. Alternatively provision a strong `PERTH_MAP_SETUP_TOKEN` in your hosting environment.
4. Choose **Install & set up**. The loader checks hosting requirements, downloads the application from the fixed GitHub repository, creates protected storage, imports the official Transperth timetable, and checks that installation is ready. It also installs the map library locally. If a download or import fails, the loader shows which step failed and lets you retry; it does not label a demo or missing timetable as a completed setup.

5. Open the map once setup is complete. The banner reads **Timetable estimates**. Return to the same `loader.php` for **Back up & update** or timetable refresh. Updates include the loader itself; there is no second file to upload.

**GitHub source:** the loader downloads `efrgdgr0024345/map` on branch `main`. A complete application must be published there before a fresh cPanel installation can download it; the loader refuses an incomplete or README-only archive. Future local development changes must also be published to `main` before the loader can install them. Uploading a copy of the loader does not publish application code.

**Hosting limits:** PHP cannot enable missing server extensions or override every hosting execution limit. The loader reports required cPanel settings when needed. It requests up to 600 seconds for setup/import; a host may impose a shorter limit. For a host that cannot complete a large feed import in a web request, cPanel Terminal is an optional fallback:

   ```sh
   cd /home/CPANEL_USER/public_html/YOUR_FOLDER
   php -d memory_limit=512M scripts/import-gtfs.php
   ```

   Or download the official GTFS ZIP yourself and import it locally:

   ```sh
   php -d memory_limit=512M scripts/import-gtfs.php /home/CPANEL_USER/google_transit.zip
   ```

Keep timetables fresh using the loader's refresh button or an optional daily cPanel cron task with the import command. A failed download, corrupt/expired archive, or interrupted staging import keeps the previous database intact.

**Updates:** `loader.php` downloads only that fixed GitHub source over verified HTTPS. It stages files, verifies backups and restores overwritten files on a failed update. It preserves `var/`, `.env`, `config.php` and hosting configuration. Application files and the loader are updated together; the loader is replaced last. Local edits to application files will be replaced; keep them in Git or back them up first. Old files removed upstream are not automatically deleted; review release notes for manual removals. Backups are in private `var/backups/`.

Keep HTTPS enabled. Confirm a request to `var/` and any private file is denied. On Nginx, add equivalent deny rules for `var`, `src`, `scripts`, `tests`, dotfiles and `bootstrap.php`; `.htaccess` is Apache-specific. The built-in development server must use the supplied router.

### cPanel Terminal

The same single file supports command-line installation and updates. Run these from the folder containing `loader.php`; **no `sudo` is needed**:

```sh
php loader.php --check
php -d memory_limit=512M loader.php --install
php -d memory_limit=512M loader.php --update
php -d memory_limit=512M loader.php --refresh
```

`--install` and `--update` download the application and loader, prepare storage, import timetables and validate readiness. `--refresh` retries only the timetable. Commands print structured results and exit nonzero when setup is incomplete. `--help` lists the commands.

To obtain the initial key for the browser console from your own terminal, use `php loader.php --show-setup-token`. This command never serves the key over HTTP and refuses to reveal a key after maintenance has been initialized. If you are already inside the generated `var` folder, `cat setup-token.php` lets you read the initial `setup_token` value directly. This guarded file stores data; do not try to execute it or visit its URL. Keep your key private.

## Local development

No Node build, Composer packages, API key or database server is required. Install PHP 8.1+ with the above extensions, then:

```sh
cd /path/to/map
php -d session.save_path=/tmp -S 127.0.0.1:8080 dev-router.php
```

This starts an explicitly labelled demo until a timetable is imported. In the prepared cloud workspace the equivalent PHP executable is `/workspace/.tools/php/run`. Use the existing isolated checkout; a separate Git worktree is unnecessary.

Run the checks:

```sh
php tests/transit.php
php tests/loader_install.php
PHP_BINARY=php python3 tests/loader_http.py
PHP_BINARY=php python3 tests/loader_cli.py
node --check assets/app.js
php loader.php --check
```

Tests create isolated temporary data and never replace the installed timetable. `loader.php --check` checks hosting prerequisites; it does not install the app.

## Sources and network access

- Official schedule: <https://www.transperth.wa.gov.au/TimetablePDFs/GoogleTransit/Production/google_transit.zip>
- Official spatial-data terms: <https://www.transperth.wa.gov.au/About/Spatial-Data-Access>. Timetable data is available free of charge from www.transperth.wa.gov.au; the application displays that statement. The feed and its current service dates have been imported and checked in this cloud environment. The importer handles spaces in the official shape-file headers, calendar exceptions and services after midnight. Review the official terms for your deployment; no PTA/Transperth logos or implied endorsement are used.
- Map tiles and attribution: OpenStreetMap contributors, <https://tile.openstreetmap.org>. Follow the OSM tile usage policy; use a suitable tile provider for substantial traffic. There is no bulk downloading or offline prefetch.
- Address suggestions: Photon / OpenStreetMap, <https://photon.komoot.io>. Results are limited to the Perth region, cached on the server and debounced in the browser. The public service has no SLA; for production traffic, configure a supported hosted/self-hosted provider in `src/Geocoder.php`. Typed searches go to Photon; GPS/map-click coordinates go to this app's server for planning.
- Leaflet 1.9.4 is vendored locally under `assets/vendor/leaflet`, with upstream licence and verified upstream SRI checksums. No runtime CDN is required for the map library.
- Updates: <https://codeload.github.com/efrgdgr0024345/map/zip/refs/heads/main>.

Backend outbound HTTPS requires `www.transperth.wa.gov.au`, `photon.komoot.io` and `codeload.github.com`. Browsers need `tile.openstreetmap.org`. No Transperth account, ticket purchasing, user accounts, location history or actual realtime feed is implemented.

## First-version boundaries

The planner searches departures in the next three hours with up to four vehicle boardings, 1.5 km endpoint walks and 250 m walking transfers, allowing two minutes for changes. It compares earliest arrival, including waits and walking, and can try an alternative that skips the first selected vehicle. It is not a complete production transit routing engine: walking links use straight lines with an estimated distance factor and do not know footpaths, accessibility, station entrances or barriers. Journeys should be checked against official information before travel.

Vehicle positions and active service days use `Australia/Perth`, including GTFS times beyond `24:00:00`. An expired feed is refused for current routing/movement. Untimed GTFS stops currently require interpolation before import; unsupported files fail clearly instead of silently generating bad positions. Display responses are capped for map performance. Actual vehicle GPS, delay-aware rerouting, accessible routing and stop alerts are useful next additions once suitable data is available.
