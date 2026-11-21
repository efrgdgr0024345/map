# Map-first nearby services and alight-to-walk plan

## Intended experience

Walking: enable location and see a light 500 m search circle, nearby boarding
stops, route destinations and scheduled minutes until the next buses directly
on the map. Selecting a route makes its direction and path clear. Also offer
the nearest stop for the same route in the other direction where identifiable.

Riding: confirm the current service, see the next valid alighting stop, then
explore places and connecting services reachable on foot from that stop. Every
onward option includes the remaining ride and walk before any next boarding.

Keep search collapsed. Use small stop labels and a single compact selected
option, with optional details. No persistent large departure panel is required.

## Findings in the existing checkout

Reviewed against `main` at `d80d4b5` before publication.

- `assets/app.js` watches GPS, draws a blue accuracy circle, fits planned
  journeys and independently displays their incoming vehicle and walking path.
- `api.php?action=vehicles` returns viewport-based stops and scheduled vehicle
  estimates. Separately, `action=nearby-bus` already searches within 500 m of
  GPS, returning next services grouped by public line and destination, including
  other nearby directions, full selected-trip geometry and an incoming vehicle.
- `renderNearbyBus()` already provides service selection, countdowns and
  "Show full route" in a floating card. Build on this rather than introducing
  a second nearby system. Move priority information onto the map and add the
  radius circle and onboard/alighting behaviour.
- General buses and stop detail are gated by street zoom 16, while trains,
  ferries and focused nearby layers have independent rendering. Preserve that
  separation when fitting a selected route at a wider zoom.
- `Transit::timetable()` already supplies trip stop sequences, service dates,
  coordinates and pickup/drop-off restrictions. `Transit::approach()` supplies
  an incoming service path for a specified boarding stop.
- The importer retains shapes and headsigns, but not GTFS `direction_id` or
  `stop_code`. Route number alone cannot identify the correct direction, branch
  or repeated visit to a stop.
- `WalkingRouter` and `TransferRules` provide pedestrian verification and
  connection constraints. Reuse these rather than drawing invented shortcuts.
- Fleet positions are inferred from static timetables, not measured vehicle
  GPS. Proximity to an estimated marker cannot establish that someone is aboard.

## 1. Establish a direction-aware nearby data contract

Implementation: `src/GtfsImporter.php`, `src/Transit.php`, a focused nearby
service class if needed, and `api.php`.

- Retain optional GTFS direction IDs and public stop codes on import. Use
  backward-compatible optional metadata tables or a tested schema migration;
  existing installed databases must remain usable before their next import.
- Extend the existing `action=nearby-bus` and `Transit::nearbyBus()` contract
  with a bounded radius; retain its validated Perth point and 500 m walking
  default. Preserve its independence from map bounds and zoom, including the
  existing bus, train and ferry coverage. Update hard-coded 500 m checks in
  both server queries and client rendering together for post-alight searches.
- Preserve existing fields and add the authoritative centre and timetable
  revision alongside the radius, generated time, estimate status and bounded
  candidate groups. Keep explicit empty, expired and truncated-result states.
- Extend each existing option to carry stop ID/code/name/coordinates, route ID/label/colours,
  direction ID when available, headsign, trip ID, service date, boarding stop
  sequence, scheduled arrival and departure. Identify repeated stop visits by
  sequence, not just stop ID.
- Continue including upcoming trips even if their vehicles have not started running. Do
  not derive departures solely from currently visible vehicle markers.
- Refine the current public-line/headsign grouping with direction/variant
  metadata, preserving intentional grouping of duplicate public services across
  operators. Return the closest boarding point for
  each useful direction and the next few departures so a missed bus can be
  replaced. Pair opposite directions using route identity, direction metadata
  and actual stop sequences; do not assume adjacent stops or different headsigns
  necessarily mean opposite directions. Handle loops and missing directions
  explicitly rather than inventing a pair.
- Keep ordinary results inside the displayed search circle. If an opposite
  direction stop is outside it, offer an explicit wider search, update the
  circle to the actual radius and label that change. Do not silently exceed it.
- Respect service calendars, after-midnight times, pickup restrictions and
  expired feeds. Rank primarily by access distance and useful departure time;
  preserve both directions and stable ordering rather than constantly replacing
  everything with the earliest bus.

## 2. Make nearby options legible on the map

Implementation: `assets/app.js`, `assets/style.css`, minimal `index.php` markup.

- Add a separate non-interactive `L.circle` for the search area: green dashed
  boundary, approximately 0.05 fill opacity and a small "500 m search" label.
  Keep the existing blue GPS accuracy circle visually distinct. The search
  radius describes geographic discovery distance, not guaranteed walking reach.
- Reuse layers and update their positions rather than rebuilding them on every
  GPS event. Keep a displayed result set and its circle bound to the same query
  centre until the replacement response arrives; discard outdated responses.
- Show at most 4–6 priority labels, subject to screen-space collision checks.
  Example structure: `[route] → [destination] · [N] min`, anchored to the
  actual boarding stop. Reveal stop name/code and distance on focus. Retain
  lower-priority stops as quiet dots and allow expansion without a large sheet.
- Treat selected stop, confirmed onboard service, incoming bus and background
  network as separate visual priorities. Fade unrelated route lines, fleet and
  POIs during focused viewing; restore them when focus clears.
- Give focused stop/vehicle controls at least 44 px hit areas, keyboard access
  and accessible names. Colour and arrows supplement destination text.
- Auto-tuck the mascot while nearby labels are active. Reserve its layout space
  before animation so neither labels nor status text can be obscured. Reduce
  the count card to a stable, secondary status badge with an unobtrusive loading
  state. Allow narrower top chrome and preserve readable map attribution.
- Recalculate label placement for zoom, rotation and safe-area changes. Respect
  reduced motion and avoid announcing every countdown tick to screen readers.

## 3. Connect selection, route fitting and countdowns

Implementation: extend the existing nearby selection/rendering in `assets/app.js`
and the trip geometry already returned by the nearby endpoint.

- Extend `nearbyServiceKey`/`nearbyBusData` selection separately from
  `state.selected`, which is an existing planned journey. Use trip ID + service
  date + stop sequence to identify the exact boarding instance while retaining
  a stable public-service group for successive departures.
- On a stop label or bus selection, highlight its exact directional trip
  geometry, boarding stop and incoming vehicle. Do not combine every variant
  with the same route number into one line.
- Initially fit the local boarding context: user, boarding stop, verified
  walking path when available and a useful incoming segment. Provide "Show full
  route" and "Back to stop". Full-route mode fits the complete trip with padding
  based on actual visible controls; keep focused layers visible below zoom 16.
- Reuse the independent incoming-service rendering so an empty/failed fleet
  request does not erase the selected bus. An upcoming trip begins at its first
  stop; it has no invented approach path before its origin.
- Retain each direction's existing counter and make it prominent on its map
  label. Derive it
  from absolute scheduled arrival times and the current clock; use "Due" during
  boarding dwell and expire it after departure. Check catchability against
  departure, walking time and boarding allowance. Refresh results every 15
  seconds; update counters locally between refreshes and on tab resume.
- Label counters "Scheduled" and positions "Estimated". Preserve data age on
  failures and replace stale arrivals with an unavailable state, never fake
  continued arrivals. Cancel older selection requests/animations on a new tap.
- Manual pan/zoom pauses camera following, while selection and countdowns stay
  active. A refresh must not repeatedly force the camera back.

## 4. Add an explicit onboard state

Implementation: GPS samples and a small state machine in `assets/app.js`, plus
an exact-trip context lookup on the server.

- States: walking, possible onboard, confirmed onboard, alighting/walking, and
  location unavailable. Planned journey guidance retains priority and uses its
  existing selected-trip context rather than competing with nearby exploration.
- Preserve reported GPS speed/heading when present and a short in-memory sample
  window. Use sustained movement, accuracy, route alignment and estimated trip
  proximity only to suggest a candidate; speed alone cannot distinguish a bus
  from a car, train or GPS jump.
- Offer "I'm on this bus" on a selected service. Confirmation pins that trip
  and service date. Allow changing it or "I'm off" at any time. Do not silently
  show a "YOU" marker on an unconfirmed estimated bus.
- For a confirmed service, resolve progress against ordered route geometry and
  stop visits, combining GPS with the schedule as an estimate. Find the next
  valid drop-off stop ahead, including repeated stops and loop routes. At a
  terminus show the final alighting stop. If progress is ambiguous, request a
  stop choice rather than presenting a guessed stop as certain.
- A traffic light or short GPS dropout must not clear the confirmed service.
  Use sustained departure from the route/walking evidence to offer an exit;
  manual "I'm off" immediately exits. Poor/stale GPS suspends confident next-stop
  guidance without silently switching to an incorrect walking origin.

## 5. Calculate alight → walk → board (or arrive)

Implementation: the nearby service, `Transit`, `WalkingRouter`, `TransferRules`
and compact map rendering.

- In confirmed onboard mode, move the discovery origin to the next valid
  alighting stop, not the moving bus position. Initially search 300 m around
  that stop, with a clearly labelled "300 m from next stop" circle. Keep the
  phone position and alighting marker visible so the origin change is clear.
- Draw the remaining ride to an "Alight here · [stop]" pin. Show other stops and
  places around that pin. Tapping an option verifies the walk from alighting
  to the new boarding stop or the destination itself. Same-stop transfers
  need no invented walk, but applicable transfer/boarding requirements remain.
- Compute feasibility in seconds:

  `ready = estimated alight arrival + verified walk + applicable connection allowance`

  Apply existing boarding/GTFS transfer rules consistently, avoiding double
  counting. Only a departure at or after `ready` is catchable; otherwise use
  the following service. Show arrival countdowns separately from this test.
- The map shows the ride segment, alighting pin, verified dotted walk and
  boarding/destination pin. A compact caption can read
  "Off at [stop] → walk [N] min → [route] to [destination] in [M] min".
- For a place rather than a transfer, end the itinerary at the place after the
  walk. Do not force another bus leg.
- Discover candidates cheaply, then verify walking for the selected option and
  a bounded number of priority options. Label unverified access as approximate;
  never claim a connection is reachable before checking the walk. Reuse cached
  public stop-pair walks and obey the existing provider budget/pacing. On routing
  failure, say "Walking route unavailable" instead of drawing a straight line
  across roads, fences or platforms as a verified walk.
- Keep a tapped alighting option stable while it remains feasible. As the bus
  advances, replace expired options and explain any change to the alighting
  stop. Avoid automatically shuffling a focused itinerary every refresh.

## 6. Refresh, lifecycle and graceful failure

- Keep the existing 15-second cadence. Coalesce meaningful GPS movement into
  the latest nearby request; abort obsolete work instead of starting a request
  for every fix. Do not repeatedly fetch full shapes or reroute every option.
- Pause background work while hidden. On resume refresh the clock, GPS and
  timetable status; never retain a previously catchable bus on an old clock.
- No consent means no nearby-you claims. Coarse or stale location produces a
  visible uncertainty state; distinguish the last searched area from a current
  position. Keep candidate requests bounded and reject invalid input.
- Retain short GPS history only in memory; reuse the existing rules against
  persisting personal walking origins. Preserve demo labels and never treat
  illustrative demo services as actual boarding advice.

## Delivery order and verification

1. Direction/stop metadata and nearby endpoint, with compatibility and calendar
   tests. Cover two directions, absent/ambiguous pairs, loops, pickup rules,
   repeated stops, upcoming trips, expired feeds and midnight service dates.
2. Walking mode: search circle, labels, paired countdowns, exact-route selection
   and fit controls. Test geometry/viewport behaviour at 320, 390 and 430 px,
   landscape and desktop; stale request ordering, zoom-out persistence, reduced
   motion, mascot overlap and keyboard/touch use.
3. Manual onboard confirmation, next valid stop and alight/walk feasibility.
   Test walks that miss the first bus, opposite-side stops, same-stop transfers,
   final destinations, terminus, unavailable footpaths and repeated stop visits.
4. Optional candidate suggestions and simulated GPS movement. Test walking,
   sustained bus movement, stopped traffic, parallel routes, GPS jumps/dropouts
   and explicit exit. Keep fixture-based evidence separate from any physical
   journey or live pedestrian-provider check.
5. Run the existing nearby-services PHP and nearby-bus browser checks, plus
   motion, approach, transfer, walking, route-cache, incoming walk and mobile
   browser checks appropriate to the changed code. Document any
   provider-dependent checks that cannot run. Review a real phone experience
   before claiming that the moving-bus interaction is validated.

## Acceptance criteria

- Walking mode makes route destination, scheduled minutes and exact boarding
  stop understandable directly on the map, including the same route's opposite
  direction when one can be identified.
- Every visible radius matches the query centre/distance for its result set;
  GPS accuracy and post-alight search areas have distinct labels.
- Selecting a service highlights the correct trip, stop and incoming bus;
  full-route fitting and returning to boarding context need no manual hunt.
- Confirmed onboard options start with a valid future alighting stop and account
  for the walk and connection time. They never propose stepping off mid-road.
- The map remains the primary surface; focused text, controls and countdowns
  stay readable without mascot overlap or an always-open blocking panel.

This document records the design. The first implementation and its verification
steps are described in [nearby.md](nearby.md); optional automatic onboard
suggestions remain future work. Publishing still requires the authenticated
hosting updater after the checked commit reaches GitHub.
