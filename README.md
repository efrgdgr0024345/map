# Nextstop — Perth in motion

A clean, mobile-friendly Perth public transport map built with PHP, Leaflet and OpenStreetMap. Your selected bus/train has a bright **YOUR NEXT BUS / TRAIN** marker. Other vehicles on the same line stay visible, so you can see the service behind it. Unrelated lines disappear when you select a journey; a switch brings back the network.

## What works

- Phone GPS with permission, searchable start/destination addresses with suggestions, or map clicks and draggable pins. Selecting a location field opens a clear map picker; confirming a point returns to the form. With both locations selected, the ride search starts automatically. GPS keeps watching for better high-accuracy fixes before a journey is selected; stale cached fixes do not freeze the location. The compact accuracy indicator/circle reflects the device's reported uncertainty.
- Map-area vehicle and stop updates every 15 seconds.
- Official rail, CAT and bus route colours, clear vehicle symbols and directional arrows.
- Focused journey view, walking legs, transfers, boarding countdown and a later service alternative when found. Follow mode fits the remaining journey, selected service, consented GPS position and destination; exploring the map pauses following until you recenter.
- In-app departure boards and full stop-by-stop service timetables, including service-day and after-midnight times. Tap a stop, vehicle, itinerary action or **View timetable** on your next-service card.
- A compact phone layout with search initially collapsed, a touch-draggable sheet, and the scheduled-service card directly above the bottom search pill. Pull the handle down to dismiss, drag the pill up to open, or use the accessible buttons. Normal explanatory notes are hidden on phones; errors and source details remain available.
- Street/footpath walking directions and a top-right circular **Leave home** clock: first departure minus the actual walking time and a two-minute boarding allowance. It pulses during the final three minutes, respects reduced motion, and disappears after the first boarding. A route-coloured pulse traces the incoming service's remaining path; arrival countdowns use timetable arrival rather than a later departure after dwell.
- Public-phone toggle above the zoom controls, using the official Telstra location register cached on your server. Larger street-level phone symbols have a 44-pixel touch target; overview clusters keep the map readable. The inspected register has **783 Perth-area sites containing 870 payphones**; a marker may represent multiple phones.
- Transfer guidance opens from an itinerary transfer or an accurate GPS approach to that stage of the trip. Locally stored station detail adds mapped platforms, buildings, footpaths, crossings, entrances, stairs and lifts where available. An A marker identifies alighting; the required boarding stand/platform flashes while dots reveal the verified pedestrian path. Official minimum connection times are enforced separately from walking duration. See [docs/station-data.md](docs/station-data.md) and [docs/transfer-guidance.md](docs/transfer-guidance.md).
- Zooming into a mapped interchange also opens a clean station map before a journey is planned. At zoom 17 and above, overlapping network-route variants are replaced by local station detail and stand labels; vehicles remain visible. **Show routes** restores the network view. Browsing never moves the camera or changes the selected starting point.
- Official static GTFS import into SQLite, with calendar exceptions, after-midnight services and route shapes.
- Persistent server and browser route caches: geometry redraws locally when panning; public vehicle positions refresh independently.
- A protected root-level `loader.php` for cPanel setup, timetable refresh and GitHub updates with backups.
- Black-cat branding using a lightweight portrait derived from the supplied mascot, with lime-eye accents and clear black/white controls. A compact cat peeks over the search launcher: its head rises before its paws land. Tap the cat to tuck it away; reduced-motion preferences are respected.

**Honest movement:** the app does not have an actual vehicle GPS feed. After importing GTFS, positions are interpolated between scheduled stops along route shapes. Delays, cancellations, capacity and early/late running are unknown. Before import, the app explicitly shows an illustrative **DEMO** network with invented times; those journeys must not be used for travel. Phone GPS identifies the user, not the buses.

Marker animation follows bounded 15-second timetable predictions along the road/rail geometry, including turns and station dwell. It stops at the end of its prediction window rather than inventing movement while disconnected. Direction arrows rotate independently of upright route labels; reduced-motion settings are respected. Consented GPS updates remain separate from the planned starting point so tracking does not clear the journey.

The phone may initially supply a coarse Wi-Fi/network location, then refine it from GPS. The app now keeps listening with high accuracy and zero cached-fix age even before planning, without resetting an active journey. Device accuracy still depends on permission, signal and hardware. A new explicit search uses the latest accepted phone position.

Walking geometry comes from a dedicated pedestrian routing service, not straight-line displacements. Actual walking durations are fed back into planning: a bus or transfer that cannot be reached is removed or replanned. Unchecked pedestrian links never receive a made-up leave deadline. Small unmapped endpoint access connections (up to 50 m) remain separately identified, and accessible routing is not guaranteed. Personal GPS/address walking paths stay request-local; only public stop-to-stop links may be cached persistently. See [docs/walking.md](docs/walking.md).

Place searches can identify a venue's centre rather than a walkable entrance. Reviewed access points in `assets/place-access.json` match exact Photon/OpenStreetMap object identities; they never choose a nearby gate by name or distance alone. Fremantle Oval's playing-field result uses its mapped Parry Street access, labelled explicitly in the search result. Unmapped endpoints receive a clear entrance-selection message; the app does not widen the 50-metre access limit or invent a shortcut through a fence.

The public-phone overlay uses Telstra's published register coordinates and addresses, not bulk geocoding or guessed markers. It shows registered locations rather than live working/availability status and does not claim to include other operators. Source date, unit count, address and attribution are available from the markers/About. See [docs/payphones.md](docs/payphones.md).

## Map speed and route storage

All imported stops, trips, raw route shapes and timetables live on your server in `var/transit.sqlite`; the app does not fetch route geometry from Transperth while someone moves the map. Each successful import also prebuilds `var/routes.json` and a compressed sibling. The browsing layer preserves every used route/shape pair and simplifies it to about 20-metre accuracy; selected journey geometry still uses the detailed original shapes.

The verified feed produces 2,520 route variants, approximately **504 KB compressed** (3.9 MB uncompressed). These include 421 routes with trips; two regional route declarations have no trips and cannot supply geometry. The cache builds in about three seconds on this cloud machine. Timing varies by hosting.

The browser keeps public geometry in memory and IndexedDB, draws the visible area locally, and revalidates the catalog in the background using HTTP ETags. Moving around a cached region does not require another route-geometry download. A new import changes the revision; the browser refreshes automatically. Vehicle positions and personal journeys are not stored in the route cache. Overview routes include imported service variants, not a promise that every line is currently operating.

The first visit still downloads the catalog. Street-map tiles come from the configured OpenStreetMap tile service and have their own loading/cache behaviour; this is not an offline basemap. If a cache build fails, the valid timetable and previous route cache are retained, and the route endpoint can retry building it. You can optionally rebuild it in Terminal with `php scripts/build-route-cache.php`.

Official colour values, fallback behaviour and sources are documented in [docs/network-colours.md](docs/network-colours.md). Existing databases remain readable without a schema migration: named network colours apply immediately, and the next import adds optional feed colour metadata.

Station detail is also stored on the server under `assets/station-maps/`. The bundled GeoJSON catalog comes from Geofabrik's Western Australia OpenStreetMap extract, with source date and ODbL attribution. Opening a station view reads the relevant local files; it does not wait for Overpass or download geometry on each map movement. The normal cPanel loader downloads this catalog with the application, with no extra runtime extension or API key.

The inspected snapshot is dated 23 October 2026 and covers **119 station areas**, with 34,515 unique mapped features. The files total about 14 MB uncompressed (about 2.3 MB compressed across the catalog); only the relevant station file or pair is returned for a transfer. Source and licence details are in [assets/station-maps/README.md](assets/station-maps/README.md).

The current GTFS import preserves station parent/child relationships and official transfer rules. Connection feasibility uses the greater of the official minimum and the verified walk plus boarding allowance; it does not pretend that an allowance is extra walking. Older databases remain readable and gain the additional metadata on the next normal import/update. Maps show actual mapped features, not certified indoor or step-free routing. Verified official station-plan references are available in the collapsed map details.

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
php loader.php --status
php -d memory_limit=512M loader.php --install
php -d memory_limit=512M loader.php --update
php -d memory_limit=512M loader.php --refresh
php -d memory_limit=512M loader.php --refresh-phones
```

`--install` and `--update` download the application and loader, prepare storage, import timetables and validate readiness. `--refresh` retries only the timetable. Commands print structured results and exit nonzero when setup is incomplete. `--help` lists the commands.

Installation and refresh print live task details to Terminal on stderr: package bytes, file counts, timetable rows, index creation and validation. Stdout remains one final JSON result for scripts. The authenticated browser loader shows the same stages in a progress bar, with elapsed time, time since the last report and recent task history. Percentages describe stages rather than time remaining; database indexing can remain on one stage for a while.

`php loader.php --status` reads the current operation without starting another update. `running: true` means an OS maintenance lock is held; `interrupted: true` means the last operation stopped before reporting completion and its lock is now free. Otherwise inspect `progress.status` (`complete` or `failed`) and the message. Lock files normally remain on disk after completion: never delete one to force an update. Status contains no maintenance key. An older already-running loader keeps its original output until the next invocation; upload the refreshed single loader first if progress is needed immediately.

Setup also warms the optional public-phone cache. Its failure does not discard a valid timetable or prevent using the map; use `--refresh-phones` or the authenticated loader button to retry the download independently.

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
php tests/geo.php
php tests/timetable.php
php tests/route_cache.php
php tests/motion.php
php tests/approach.php
php tests/walking.php
php tests/geocoder.php
php tests/street_journeys.php
php tests/transfer.php
php tests/station_metadata.php
php tests/import_progress.php
php tests/transfer_rules.php
php tests/station_map.php
python3 tests/station_catalog.py
php tests/payphones.php
php tests/loader_install.php
php tests/loader_progress.php
PHP_BINARY=php python3 tests/loader_http.py
PHP_BINARY=php python3 tests/loader_cli.py
PHP_BINARY=php python3 tests/loader_progress_http.py
node --check assets/app.js
php loader.php --check
```

Tests create isolated temporary data and never replace the installed timetable. `loader.php --check` checks hosting prerequisites; it does not install the app.

`node tests/browser-loader-progress.mjs` checks live maintenance UI using disclosed, delayed local import fixtures and multiple PHP workers, without provider requests. `node tests/browser-station-browse.mjs` checks standalone station browsing against the real local catalog and APIs. Both need Playwright in a development environment; cPanel does not need Node.

Browser checks use Playwright and a running local app with an imported timetable. Install Playwright separately in a development environment if it is not already available. Browser map-tile rendering in this cloud can be blocked by proxy certificate trust; do not disable TLS verification to work around it.

The current suite is `node tests/browser-mobile-v5.mjs`. It covers the peeking mascot, map-first location selection, automatic planning, transfer guidance, continuous location updates, larger phone markers and desktop picker behaviour. It uses actual application/timetable/pedestrian/phone data, synthetic browser GPS, and a controlled browser clock when testing arrival at a real scheduled transfer. These checks do not simulate a completed physical journey. `CORE_ONLY=1` leaves provider-dependent checks explicitly unrun; only the full default run validates those integrations. The historical `browser-ui.mjs` and `browser-mobile-v4.mjs` suites target the preceding search flow and marker sizes.

`node tests/browser-station-v6.mjs` checks the circular leave clock and detailed station rendering. Its controlled UI fixtures and browser clock are explicitly separated from real station-map/transfer API checks. Rebuilding the bundled station catalog is a development task using `scripts/build-station-maps.py`, a trusted local Geofabrik PBF, the GTFS ZIP and pyosmium; none of those development tools are required on cPanel.

## Sources and network access

- Official schedule: <https://www.transperth.wa.gov.au/TimetablePDFs/GoogleTransit/Production/google_transit.zip>
- Official spatial-data terms: <https://www.transperth.wa.gov.au/About/Spatial-Data-Access>. Timetable data is available free of charge from www.transperth.wa.gov.au; the application displays that statement. The feed and its current service dates have been imported and checked in this cloud environment. The importer handles spaces in the official shape-file headers, calendar exceptions and services after midnight. Review the official terms for your deployment; no PTA/Transperth logos or implied endorsement are used.
- Map tiles and attribution: OpenStreetMap contributors, <https://tile.openstreetmap.org>. Follow the OSM tile usage policy; use a suitable tile provider for substantial traffic. There is no bulk downloading or offline prefetch.
- Address suggestions: Photon / OpenStreetMap, <https://photon.komoot.io>. Results are limited to the Perth region, cached on the server and debounced in the browser. The public service has no SLA; for production traffic, configure a supported hosted/self-hosted provider in `src/Geocoder.php`. Typed searches go to Photon; GPS/map-click coordinates go to this app's server for planning.
- Leaflet 1.9.4 is vendored locally under `assets/vendor/leaflet`, with upstream licence and verified upstream SRI checksums. No runtime CDN is required for the map library.
- Pedestrian routing: <https://routing.openstreetmap.de>, the dedicated FOSSGIS `routed-foot` backend. The `/driving/` URL token is OSRM's standard token; it does not turn this dedicated pedestrian backend into car routing. Requests use the selected coordinates; no API key is required. Public infrastructure has no uptime guarantee.
- Payphones: <https://www.telstra.com.au/content/dam/tcom/personal/consumer-advice/payphones/payphone_register.xlsx>, explicitly linked from Telstra's public payphone advice page. The inspected workbook is dated 11 October 2026. Fetches are bounded, cached for seven days and use no API key or additional PHP XML extension.
- Updates: <https://codeload.github.com/efrgdgr0024345/map/zip/refs/heads/main>.

Backend outbound HTTPS requires `www.transperth.wa.gov.au`, `photon.komoot.io`, `routing.openstreetmap.de`, `www.telstra.com.au` and `codeload.github.com`. Browsers need `tile.openstreetmap.org`. No Transperth account, ticket purchasing, user accounts, location history or actual realtime feed is implemented. Historical Overpass experiments are not the production phone-data source.

## First-version boundaries

The planner searches departures in the next three hours with up to four vehicle boardings, stops within a 1.5 km endpoint radius and transfers within a 250 m radius. It verifies chosen pedestrian paths and replans with their actual estimated durations, allowing two minutes before boarding after a walk. It can try an alternative that skips the first selected vehicle. Verification allows up to three planning rounds, eight distinct walking lookups, a 45-second overall admission budget and 24 seconds of pedestrian-provider work. Planning time counts toward the overall budget, and unchanged walking times do not trigger an unnecessary scan. Only verified feasible candidates are offered; a single SQLite query cannot be preempted by this application budget. This is not a complete production routing engine or an accessibility guarantee. Journeys should be checked against official information before travel.

Vehicle positions and active service days use `Australia/Perth`, including GTFS times beyond `24:00:00`. An expired feed is refused for current routing/movement. Untimed GTFS stops currently require interpolation before import; unsupported files fail clearly instead of silently generating bad positions. Display responses are capped for map performance. Actual vehicle GPS, delay-aware rerouting, accessible routing and stop alerts are useful next additions once suitable data is available.
