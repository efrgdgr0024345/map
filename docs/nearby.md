# Nearby map and confirmed onboard exploration

Enable location on the map. With a recent fix accurate to 80 m or better, a
light **500 m search** circle shows the geographic area being searched. This
is different from the blue GPS uncertainty circle and is not a promise that
all paths within it are walkable.

Up to six collision-aware labels show a route, its destination, scheduled
minutes and actual boarding stop. **Nearby options** expands the remaining
directions without leaving a large list open over the map. Other-direction
services use their own boarding stop and countdown. A tap checks the walking
path and selects the exact directional trip. **Show full route** and **Back to
stop** switch between the whole trip and the local boarding context. Panning
pauses automatic camera movement; **Back to stop** resumes local following.

## Riding and getting off

Select your current service, then **I'm on this service**. Confirmation is
explicit: estimated bus positions cannot prove which vehicle you are aboard.
The selected trip and service date stay pinned as GPS updates.

The next permitted alighting stop is calculated from GPS progress along its
ordered shape. A **300 m from alighting stop** circle replaces the walking
search area. The A pin is the place to get off. Nearby labels show onward
services; tapping one verifies its pedestrian path and checks the remaining
ride, walk, two-minute boarding allowance and applicable GTFS transfer minimum.
A later departure replaces one that cannot be reached. The existing pedestrian
router is used, with at most one route verification per nearby request and its
existing public stop-pair cache/pacing. Unverified candidates are not claims
of a catchable connection. A failed walk never becomes a straight-line shortcut.

To walk to a place after getting off, choose a map POI and **Go here**. The map
shows the remaining ride, alighting pin and verified walk to the place, without
forcing another bus leg. Area-centre POIs require an entrance choice first.

Use **Nearby options → Alighting stop** to choose a later stop. Ambiguous loop
geometry, a missing shape or an off-route position asks for a stop choice rather
than silently guessing. No-dropoff stops cannot be chosen. **I'm off** exits
immediately. Starting a planned journey takes priority and exits exploration.
A coarse/stale fix pauses guidance but retains the confirmed service. There is
no automatic bus identification or automatic alighting detection in this release.

## Data and compatibility

All arrivals and vehicle movement remain **scheduled estimates**. Delays and
cancellations are unknown. Confirmed onboard arrival estimates use current GPS
progress and scheduled segment durations, so they are also estimates.

The existing `nearby-bus` endpoint remains compatible and adds `centre`, a bounded
`radius` (100–1000 m), optional `trip/date/after/alight` context, and `walk=1`.
Onboard queries use a 300 m radius. Optional `to/name` supplies a final place
only after boarding confirmation. Backend validation resolves trip dates,
legal stop visits, shapes and transfer rules; client input does not supply
trusted walking times or public-stop IDs.

New imports retain public stop codes and direction IDs in optional metadata
tables. Existing databases work immediately with stop names and destination
headsigns; their next timetable import adds the optional metadata. No destructive
migration or mandatory reimport is required to try the interface.

## Verification and human testing

- `php tests/nearby_services.php`: isolated timetable fixtures, opposite
  directions, public metadata, loops/repeated visits, restricted alighting,
  transfer rules, missed connections, same-stop access and walking failures.
- `node tests/browser-nearby-bus.mjs`: actual Leaflet and UI with disclosed
  API/GPS fixtures at 320, 390, 430 and 1366 px.
- `node tests/browser-nearby-onboard.mjs`: confirmed boarding, alighting-origin
  search, verified walks, onward places, paused GPS and exit/journey priority at
  those sizes. Map tiles are explicit neutral fixtures in this suite.
- Existing incoming-walk checks cover independent selected vehicle rendering and
  planned-journey behaviour. Synthetic checks are not a completed physical ride
  or a basemap imagery verification.

On a phone, allow GPS in Perth, compare the two directions at an actual stop,
check the full-route and local views, and confirm the service when aboard it.
Check the alighting pin before following the onward walk. Try a junction, a
pause in traffic and leaving the bus; use **I'm off** after alighting. Timetable
estimates need comparison against actual operating services during human testing.

The historical `browser-mobile-v5.mjs` core suite currently times out when
reopening the origin picker immediately after synthetic touch drags. The same
timeout was reproduced with unchanged upstream JavaScript and CSS. Its later
checks are unrun; the focused nearby/onboard and incoming-walk suites above
exercise the changed flows separately.

## Publishing

Publish the checked commit to GitHub `main`, then use the authenticated
`https://easyai.com.au/bus/loader.php` console's **Back up, update & set up** action.
The loader downloads changed application files with backups and preserves the
installed timetable and maintenance credentials. A GitHub push alone does not
authenticate to cPanel or deploy the public PHP installation. After updating,
reload the public site and verify the 500 m circle and map labels with GPS.

## Automatic departure handoff and smooth updates

When the displayed departure leaves, the countdown requests its replacement
without clearing the route first. Only one handoff request runs at a time; normal
polling does not abort a pending request at the same GPS point. The response
selects the next available scheduled trip and updates its route, stop, ETA and
vehicle together. Expired data is hidden after 45 seconds, while an already-running
replacement request is allowed to complete. Leaving the area, losing accurate
GPS or choosing a journey still clears the preview immediately.

Unchanged service responses preserve the existing controls, route layers and
vehicle marker. Updated positions use the existing bounded vehicle animation;
changed route geometry fades over 220 milliseconds. Reduced-motion preferences
replace the geometry immediately. These are timetable estimates, not live vehicle
GPS or known delay/cancellation updates.

Stop destination labels now reserve their actual 62-pixel height and avoid GPS
markers, controls, panels and map warnings. A redundant permanent boarding tooltip
has been removed: the selected destination label/card retains the stop identity,
and the boarding dot still offers its tooltip on interaction. Where there is not
enough room, additional directions remain in the nearby options list.

The nearby browser suite checks marker/control reuse, actual label bounds, and
an expired departure with a deliberately held response. Repeated countdown ticks
must leave that one request intact, then load its replacement automatically.
API/GPS responses and map imagery in these tests are explicitly disclosed fixtures.
