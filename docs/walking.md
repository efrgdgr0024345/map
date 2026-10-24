# Walking routes and boarding times

Walking uses the OpenStreetMap pedestrian network through FOSSGIS's dedicated
OSRM **foot** service. Successful responses follow mapped streets and footpaths;
a provider failure is never replaced with a straight line labelled as a street.
This is pedestrian routing, not turn-by-turn accessibility or step-free routing.
It cannot establish that a private entrance, crossing, gate, or currently closed
path is usable.

The fixed server endpoint is:

```
https://routing.openstreetmap.de/routed-foot/route/v1/driving/
```

`routed-foot` selects the pedestrian backend. The final `driving` path token is
the OSRM client's conventional profile token, not a request to use car routing.
OSRM's travel profile is fixed when its dataset is extracted. Evidence:

- The [FOSSGIS frontend configuration](https://github.com/fossgis-routing-server/osrm-frontend/blob/af16769961dee0958393c427aeda37e481662e98/src/leaflet_options.js)
  labels `/routed-foot/route/v1` as **Foot**, separately from its car and bike backends.
- [OSRM's HTTP protocol](https://github.com/Project-OSRM/osrm-backend/blob/master/docs/http.md)
  states that the transport profile is determined statically by the Lua profile
  used in `osrm-extract`.
- The [OSRM foot profile](https://github.com/Project-OSRM/osrm-backend/blob/master/profiles/foot.lua)
  uses walking mode, pedestrian access rules, and walking speeds.

This public demonstration service has no application SLA. Each uncached request
uses verified HTTPS, disables redirects, allows a maximum 10 MB response, and
has a three-second connection limit and six-second total limit. Callers may
supply a smaller total timeout. Requests are paced to at most one new provider
request per second from this installation. Plan refinement must also impose an
overall time/lookup budget. A busy or unavailable provider produces a clear
routing failure, not an invented route. Use a suitable hosted or self-managed
pedestrian router before offering a service that requires guaranteed capacity.

## Planning contract

`App\WalkingRouter::route($from, $to, $timeoutSeconds = 6)` returns:

- `geometry`: actual routed coordinates in `[latitude, longitude]` order;
- `duration`: conservative walking seconds including short endpoint access;
- `distance`: metres including short endpoint access;
- `routingSource`, `routing: "street"`, and `approximate: false`;
- `snappedFrom`, `snappedTo`, and `accessConnectors`.

The main geometry begins and ends on the mapped network. Endpoints more than
50 m from that network are rejected. Shorter gaps remain separate
`accessConnectors` with `routing: "unrouted_access"` and `approximate: true`.
They are not appended to the street geometry and must be shown and labelled
separately. Their time and distance count toward the journey. Walking duration
is at least the routed distance divided by 1.25 m/s, plus access allowance.
It is a planning estimate, not a promise of a person's walking pace.

Refine walking legs before accepting a transit journey. If a routed access walk
and the boarding allowance no longer fit before departure, replan with that
walking duration or discard the infeasible service. Compute a suggested time
to leave from the departure minus routed access time and boarding allowance;
never retain a bus that the refined walk cannot reach. Plain transit fixtures
must not make external routing calls.

## Privacy and caching

GPS, map-click, and typed-address endpoint pairs are memoized only in the
current PHP request. Their coordinates, paths, and coordinate-derived lock
filenames are **not persisted by this router**. Coordinates necessarily travel
to FOSSGIS over HTTPS when an uncached pedestrian route is requested; the
provider can observe that request.

Only pairs where **both endpoints carry trusted server-supplied GTFS stop IDs**
may use the 30-day persistent cache in protected `var/walking/`. Callers must
never copy a user-supplied arbitrary `id` onto personal endpoints. Public stop
pairs are directional and keyed by their normalized six-decimal coordinates.
Cached payloads and provider pacing files are PHP-guarded, protected by
`.htaccess`, and private to the hosting account. The single pacing file stores
only the timestamp of the last request, never endpoint coordinates.

`parseResponse($json, $from, $to)` validates provider fixtures without network
access. Run `php tests/walking.php` for offline route validation, TLS/request
settings, cache, TTL, failure, and privacy checks.

## Environment and verification

Allow outbound HTTPS to **`routing.openstreetmap.de`** in the cloud environment
and on the cPanel host. There are no required API keys. A saved environment
allowlist change does not itself prove that the running environment can connect.

During implementation, the cloud proxy rejected connection to this host with
CONNECT HTTP 403 before reaching FOSSGIS. The official source configuration and
offline fixtures were verified, but no successful live Perth pedestrian request
was established in that runtime. After network access is applied, validate a
representative Perth footpath request and inspect returned geometry, times, and
snap distances before claiming live routing works.
