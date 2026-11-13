# Live public toilet layer

The **WC pin beside the map zoom controls** queries the Australian Government
[National Public Toilet Map](https://toiletmap.gov.au/). It is off until selected.
No key, account, paid map service or external JavaScript library is required.
The app uses its existing Leaflet basemap; it does not copy the provider's map
imagery, photos or branding.

## Data and refresh

The server calls the same public endpoints used by the source's web map:

- `https://toiletmap.gov.au/api/AppAPI/GetFacilitysInBoundingBox` for nearby
  locations and summary information around the displayed map centre.
- `https://toiletmap.gov.au/api/AppAPI/GetFacilityFull/{numeric-id}` when a user
  opens a pin, for additional access, location, parking and other notes.

Verified against live Perth responses on 13 November 2026. These are public web
application endpoints, without a documented availability guarantee or versioned
schema. Invalid responses fail without overwriting valid cached records.
The source currently returns up to 36 nearby results, including results outside
its requested bounds. At street zoom, facilities at the same coordinates open a choice of named facilities (for example, different floors). The app filters returned coordinates to the viewport and
states that this is **nearby coverage, not a complete metro inventory**. Pan or
zoom to query another map centre. Coverage is the same fixed Perth metro area
as the payphone layer.

Public results are cached in guarded, owner-only files under `var/toilets/`
for **120 seconds**. Nearby users share coarse map-centre cache entries. The
provider receives a rounded map centre, not a separate device GPS fix. There
is no browser-persisted facility cache. While enabled and visible, the layer
refreshes every two minutes and after map movement; opening a pin requests its
current details through the same short cache. Toggle off cancels requests.

An outage retains any saved records and labels them **Saved data · refresh
unavailable**, with the original fetch date/time. The source is retried after a
30-second failure backoff. A source-fetch time indicates retrieval, not when a
facility was last inspected. No fetch failure can fabricate an empty successful
response or an “open now” status. Cache entries are bounded to 256 files; a
nonblocking lock avoids simultaneous provider requests from multiple visitors.

## Information shown

Where supplied, each popup includes the published schedule; explicit closure
flag; address and facility type; male, female, unisex and all-gender toilets;
accessible/ambulant toilets and left/right transfer; accessible parking; key and
MLAK requirements; payment requirements; baby change and care rooms; adult change
and Changing Places; sling requirements; showers; drinking water; sharps and
sanitary disposal; dump points; operator, public contact number and source notes.
Absent flags are not presented as confirmed absence. **Hours not supplied** is
separate from 24-hour access. Source opening text is displayed as published
hours, not verified live availability, occupancy or an accessibility guarantee.
Notes may mention holidays or access restrictions and are preserved as text.

The source's facility bitmask extends beyond JavaScript's 32-bit bitwise range;
it is decoded on the server. All displayed text uses DOM text nodes. Facility
IDs and source hosts are fixed/validated, and the browser cannot select another
provider URL. HTTPS certificate and hostname verification stay enabled; redirects
are disabled. Transfers have a 5-second connection timeout, 15-second total
limit and 2 MB response cap.

Source credit, the Commonwealth copyright notice and a link to the official
facility record appear in every popup. Provider factual records remain subject
to the [source's copyright and data terms](https://toiletmap.gov.au/copyright).
No separate downloadable toilet dataset is republished with this repository.

## Validation

`php tests/toilets.php` checks source normalization, higher-order flags, unknown
hours, closures, detail notes, cache freshness, stale fallback, retry backoff,
input validation and safe storage using isolated fixtures.

`node tests/browser-toilets.mjs` checks the actual map UI with disclosed API
fixtures at 320, 390 and 1366 pixels: opt-in requests, toggle reachability,
popups, source links, details, overview clusters and cancellation. Start the
local PHP server first. A separate manual browser run also exercised the live
provider, including a real Perth facility detail response.
