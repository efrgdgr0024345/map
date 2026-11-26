# Failed endpoint: boundary and nearest walking point

`api.php?action=walking-access&at=latitude,longitude` returns the containing
OpenStreetMap building/site polygon and a suggested outside pedestrian-network
point. It is used after a trip fails with a classified `no_segment` or `no_route`
walking issue. Generic provider outages do not trigger boundary guesses.

The main search displays the polygon and walking point, its offset from the
selection and OpenStreetMap attribution. **Use this walking point** starts a new
verified route search using that GPS coordinate. **Choose an entrance on the map**
is also available. The point is explicitly approximate, not a verified driveway,
entrance, gate or crossing. Arrival time refers to the chosen walking point, not
a bedroom or a location inside the site. A suggested starting point requires
extra time to reach it; the requested trip starts at that point.

`App\WalkingAccess` uses the working JSON map API at `api.openstreetmap.org`,
with one bounded bounding box and a 4 MB response cap, then FOSSGIS's separate
pedestrian `nearest` endpoint. The original Overpass host was blocked during
research and another timed out; it is not a runtime dependency. Landgate's
personal-use/subscription services are not used by this implementation.

Closed building/site ways and complete multipolygon rings are supported. A
containing smaller polygon wins; courtyards/holes and incomplete rings are
excluded. Boundary geometry is not claimed to be a cadastral lot. Nearest
candidates must be outside the polygon (or within one metre of its boundary),
within 75 metres of the boundary and within 500 metres of the selected point.
The closest qualifying returned candidate is offered. This is a bounded lookup,
not proof of a globally nearest open entrance. Without usable geometry or an
outside candidate, the user is asked to choose an entrance. No straight route
through a building is fabricated.

Requests use verified HTTPS, fixed hosts, no redirects, bounded connection/total
timeouts and request-local data. No personal endpoint map query or polygon is
persisted by this resolver. OSM is licensed under ODbL: see
https://www.openstreetmap.org/copyright. Existing map attribution is retained.
Allow outbound HTTPS to `api.openstreetmap.org` and `routing.openstreetmap.de`.
Public services have availability/rate limits; this does not establish a 99% SLA.

## Real checks on 26 November 2026

An OSM map request around RAC Arena returned usable geometry (about 1.5 MB).
The resolver selected an outside walking point at -31.948822, 115.851219, about
79.7 metres from the failed centre click. The public Nextstop plan endpoint
returned two street-verified journeys to that point. It previously rejected the
centre with `no_segment`. This validates the data path; it does not verify that
this point is an official arena entrance.

The separate reproducible 3.8-metre failure is fixed in `WalkingRouter`: an OSRM
`Ok` response with identical snapped points and zero on-network distance is
accepted only when it has a genuine, bounded access gap and matching geometry.
Access time stays positive, the connector stays separately approximate, and the
itinerary marks `accessOnly`. Empty, disconnected or inconsistent routes remain
invalid. The real captured response now validates as four seconds / 3.8 metres.

`tests/walking_access.php` uses offline OSM/network fixtures for polygon selection,
holes, outside candidates and failures. The browser mode suite tests the rendered
polygon, GPS point and user-triggered fresh search. Walking and street-journey
suites cover access-only route validation and its itinerary metadata.
