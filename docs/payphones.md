# Perth public payphone overlay

The map uses the **public Telstra payphone register**, downloaded from the fixed
[official XLSX file](https://www.telstra.com.au/content/dam/tcom/personal/consumer-advice/payphones/payphone_register.xlsx)
linked on [Telstra's payphones page](https://www.telstra.com.au/consumer-advice/payphones).
The register supplies site identifiers, addresses, latitude/longitude, and the
number of payphones at each site. No address geocoding or user credentials are
required. Registered coordinates do not establish whether a payphone currently
works or can be reached safely.

On 24 October 2026 the published register downloaded successfully over verified
HTTPS. It contained 13,971 national records, including 1,481 Western Australian
records. Inside this application's Perth coverage rectangle it contained **783
unique sites representing 870 payphones**: 699 sites with one phone, 81 with two,
and three with three. The workbook modification metadata was
`2026-10-11T23:34:56Z`. These figures describe that inspected snapshot; later
refreshes can change them.

Show **Source: Telstra public payphone register**, linked to the official
payphones page. This is attribution to a publicly published factual register,
not a claim that Telstra endorses the application. Do not apply an OpenStreetMap
ODbL label to the Telstra records or reuse an official Telstra logo. The basemap's
separate OpenStreetMap attribution still applies to the basemap.

## Coverage and response

`App\Payphones::inBounds([$west,$south,$east,$north])` returns:

- `phones`: locations with `id`, `name`, `lat`, `lon`, `operator`, `ref`, optional
  `address`, and `phoneCount` when the register supplies a valid count.
- `source`, `sourceUrl`, `downloadUrl`, and `attribution`: the actual cached
  dataset's provenance.
- `updatedAt`: cache retrieval time. `sourceUpdatedAt` is workbook modification
  metadata, not a live availability timestamp.
- `siteCount`: number of locations in this response. `totalPhones` sums their
  known per-site counts; it is null when counts are not known.
- `coverage`, `stale`, and `message`: geographic and freshness information.

Coverage is west 115.4, south -32.75, east 116.5, north -31.3, a fixed Perth metro
rectangle including Mandurah and northern suburbs. The national register is
filtered to valid WA rows within that rectangle. Regional records outside it
are not shown. A viewport entirely outside coverage makes no provider request;
a world-sized viewport still returns only the cached metro locations.

`CAB_ID` is treated as text so leading zeros survive. `SITE_LAT` and
`SITE_LONGIT` supply coordinates directly. Street and locality fields supply the
address, and `payphones_at_site` supplies the per-site count. Identical repeated
IDs are deduplicated. Conflicting coordinates for the same ID reject the update.
Missing or invalid coordinates and zero-phone records are omitted. Metadata is
bounded and stripped of markup/control characters; clients should still render
it as text rather than HTML.

## Cache and installation

The full metro dataset is cached for seven days. Map pans filter the same saved
locations instead of downloading the register again. The fixed production
network destination is `www.telstra.com.au`; add it to restricted hosting access
when necessary. Browser coordinates and address searches are never sent to that
host.

Data is guarded JSON in private `var/payphones.php`; retry state is in
`var/payphones-status.php`. Both files exit with HTTP 404 if executed by PHP and
have mode 0600. Successful refreshes atomically publish a replacement. A
nonblocking lock prevents duplicate simultaneous downloads.

Downloads have a five-second connection limit, a fifteen-second total limit,
an eight-megabyte compressed cap, and TLS certificate/hostname verification.
Redirects and browser-selected source URLs are not accepted. The XLSX parser
uses existing PHP ZIP support without requiring DOM, SimpleXML, a spreadsheet
package, or a server process. It limits archives to 100 files and 50 MB expanded,
reads shared strings and one row at a time, validates required headers, and
rejects unsafe paths, DTD/entity declarations, and unsupported XML entities.
Only standard XML entity decoding occurs; no external resources are resolved.
The inspected national workbook parsed with approximately 13.7 MB PHP peak memory.

HTTP, download, workbook, empty-result, and storage errors cannot erase the last
useful dataset. Existing locations remain available with `stale: true`; without
a cache the layer explicitly reports unavailable data rather than implying no
payphones exist. Failed refreshes wait fifteen minutes before another automatic
attempt. Private diagnostics retain HTTP/cURL error details. Retry state is
scoped to the provider URL so failures from a retired source cannot block the
current source.

Setup may warm the layer with `Payphones::inBounds(Payphones::COVERAGE)` using
normal freshness rules. `Payphones::refresh()` explicitly forces an update and
may throw. Installation treats this overlay as optional so a telephone provider
outage cannot prevent the transport map from working.

## Source migration and tests

Early discovery through a stale locator URL was blocked and then returned 404.
A subsequent check of Telstra's current parent page found the official register.
The earlier OpenStreetMap/Overpass approach could not retrieve data reliably:
its status endpoint responded, but bounded interpreter queries timed out, as did
one public replica documented by Overpass Turbo. Production now downloads the
working official register directly and does not fall back through public query
servers.

Version-two cache records identify Telstra as their source. Any retained
version-one OSM cache remains explicitly attributed to OpenStreetMap/ODbL and
marked stale until replaced; it is never relabelled as official Telstra data.
The legacy `normalize()` parser remains for migration tests, not production
provider queries.

`php tests/payphones.php` uses isolated synthetic XLSX and OSM fixtures and never
calls a provider. It covers source fields, leading-zero identifiers, coordinates,
per-site counts, headers, deduplication, missing/invalid records, entity/ZIP
limits, viewport filtering, source attribution, migration, cache reuse, stale
retention, locking, and first-load failure. A fixture callback to `refresh()` is
accepted only in CLI tests with `PAYPHONES_TESTING=true`; HTTP requests cannot
select a fetcher, file path, or source URL. Actual source availability and counts
were verified separately against the official published register.
