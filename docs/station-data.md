# Station and interchange data

This review checked the official Transperth sources on **24 October 2026**, alongside the imported timetable. Station geometry, timetable boarding identity and connection timing come from different datasets; none alone establishes a complete indoor route.

## Official timetable metadata

The [official GTFS feed](https://www.transperth.wa.gov.au/TimetablePDFs/GoogleTransit/Production/google_transit.zip) contains **14,884 stops**, including **119 station parents** and **789 records linked to a parent station**. Its stop fields include `location_type`, `parent_station`, `stop_code`, `stop_name`, `stop_desc`, coordinates, `supported_modes` and `iptiscode`. Some CSV headers have spaces after commas and must be normalized.

This metadata groups named bus stands and train platforms into their actual interchange. A platform's exact GTFS identifier remains important: similar names or coordinates only metres apart do not mean two platforms are interchangeable.

The feed also contains **5,012 transfer rules**, all currently type 2, with `min_transfer_time` in seconds. Rules can name individual stops or parent stations and are directional. Examples from the inspected archive:

| Connection | Official minimum |
|---|---:|
| Canning Hwy Stand 1 (`23846`) → Canning Bridge Platform 2 (`99632`) | 239 seconds |
| Canning Bridge Ramp Stand 2 (`11969`) → Platform 2 (`99632`) | 239 seconds |
| Murdoch Stand 10 (`23716`) → Murdoch Platform 2 (`99652`) | 239 seconds |
| Perth station (`56`) ↔ Perth Underground (`64`) | 300 seconds each way |
| Mandurah Platform 1 (`99731`) → Mandurah Stand 1 (`17383`) | 299 seconds |

These are minimum **connection times**, not measured walking durations or path geometry. A short pedestrian result must not override a longer official connection requirement; the connection calculation must also avoid adding the same boarding allowance twice.

The archive has **no `pathways.txt` or `levels.txt`**. Its stops do not supply entrances, stairs, lifts, barriers or platform polygons. The station coordinates cannot provide those missing features or identify a phone's floor. Walking directions therefore need separate mapped pedestrian data.

## Official station access plans

Transperth's [Stations & Maps directory](https://www.transperth.wa.gov.au/Using-Transperth/Station-Facilities/Stations-Maps) publishes station facilities and PDF access plans. These six documents were downloaded and inspected successfully:

| Station / GTFS parent | Official access plan | Printed “current as” date | Contents |
|---|---|---|---|
| Canning Bridge / `83` | [Station map](https://www.transperth.wa.gov.au/timetablePDFs/BusStationMaps/Canning%20Bridge%20Station.pdf) | 5 July 2026 | One page: stands, crossings, stairs and direction-specific lifts. |
| Murdoch / `96` | [Station maps](https://www.transperth.wa.gov.au/timetablePDFs/BusStationMaps/Murdoch%20Station.pdf) | 1 February 2026 | Three pages: overview, station/concourse detail and parking. |
| Perth Underground / `64` | [Access maps](https://www.transperth.wa.gov.au/timetablePDFs/BusStationMaps/Perth%20Underground%20Station.pdf) | 15 July 2024 | Two pages: separate concourse and platform levels, lifts, escalators and barriers. |
| Perth / `56` | [Station map](https://www.transperth.wa.gov.au/timetablePDFs/BusStationMaps/Perth%20Station.pdf) | 13 October 2025 | One page: platforms, concourse, stairs, lifts and connecting access. |
| Fremantle / `87` | [Station maps](https://www.transperth.wa.gov.au/timetablePDFs/BusStationMaps/Fremantle%20Station.pdf) | 1 February 2026 | Three pages: overview, detailed station/stands and parking. |
| Mandurah / `94` | [Station maps](https://www.transperth.wa.gov.au/timetablePDFs/BusStationMaps/Mandurah%20Station.pdf) | 10 September 2026 | Three pages: station/stands/access and two parking maps. |

The website's `sid` values differ from GTFS parent IDs: Canning Bridge `33`, Murdoch `36`, Underground `4`, Perth `1`, Fremantle `19` and Mandurah `42`. Do not construct website station links by substituting a GTFS parent ID.

These are illustrated, sometimes rotated A3 diagrams. They are useful official references, but are **not a supplied georeferenced pedestrian graph**. They can be registered against known stops or physical landmarks; a phone GPS overlay requires a separately calibrated and validated transform. Illustrated lines alone do not establish an indoor routing graph. Canning Bridge's plan specifically labels lifts “to Mandurah only” and “to Perth only”: arriving at approximately the right coordinates is insufficient to establish the correct platform access.

A research test aligned Canning Bridge's printed stands 1–3 to their current GTFS positions using translation, rotation and uniform scale, then overlaid the result on real aerial imagery. Stand 4 was withheld from fitting and differed by **14.6 metres**. Other leave-one-out checks ranged from roughly 4.6 to 23 metres. This demonstrates useful approximate registration, not validated platform-level positioning. The mismatch cannot distinguish printed symbol placement, diagram geometry, GTFS accuracy and different source dates. The experimental PDF overlay is not the application's station basemap.

Canning Bridge includes a 12-metre scale bar; Murdoch's detailed page has a 15-metre bar, and Fremantle's detailed page has a 50-metre bar. The latter two include station structures and some building outlines. They are not complete building-footprint datasets, and a printed scale alone does not prove every corner is uniformly survey-accurate. Physical bridge, road and building corners are useful additional control points; independent checks should determine whether a particular station plan is accurate enough to use.

Facilities pages describe station-wide lifts, escalators, toilets, hearing loops, tactile paving and accessibility categories. They do not establish live lift availability or a particular route's accessibility. Some retain older line names, so current services should come from the timetable rather than the PDF route lists or facilities text.

The [Spatial Data Access licence](https://www.transperth.wa.gov.au/About/Spatial-Data-Access) grants reuse of the data downloadable from that page, with its stated attribution and other terms. It does **not automatically grant the same redistribution licence to these separately published station PDFs**. An original-source plan link keeps the reference with its publisher; copying or redrawing the plans requires its own applicable rights.

## Other official spatial data

The [official spatial ZIP](https://www.transperth.wa.gov.au/TimetablePDFs/Other/SpatialData/Spatial_Data.zip) contains stop-point and vehicle-service shapefiles plus a data dictionary. It has no station floor plans or pedestrian network.

The inspected stop shapefile has 16,574 records and attributes including `STOPNUMBER`, `STATUS`, `LOCATION`, `STOPTYPE`, `SUBURB`, `ACCESSIBLE`, `BAY`, `SHELTER` and `ASSET`. Its DBF is dated **5 June 2026**, older than the current GTFS. `STOPID` is an internal identifier; `STOPNUMBER` is the passenger-facing number. These are potential enrichment fields, not proof of a complete step-free walking route.

## OpenStreetMap station context

The verified context source is Geofabrik's [Western Australia OpenStreetMap extract](https://download.geofabrik.de/australia-oceania/australia/western-australia-latest.osm.pbf). The research download was **115,362,675 bytes**, with HTTP `Last-Modified` **23 October 2026**. Individual mapped features can be older or incomplete; that download timestamp is not a survey date for every station.

The development extraction groups mapped context around the official GTFS station parents and their child stops. Useful features include platform shapes, station buildings, pedestrian ways, crossings, entrances, steps and lifts where mapped. Level and access tags must be preserved; a geometric crossing does not prove two paths connect on the same floor. Missing features must not be filled in with invented station detail.

Public geometry can be packaged on the server and drawn locally so entering a station view does not depend on a new public map-data query. The OpenStreetMap editing API was investigated only for research and is not the runtime data source. Small Overpass queries timed out on the main service and two alternatives during this review, so their availability is not a reliable dependency for opening each station map.

OpenStreetMap data requires **© OpenStreetMap contributors** attribution and is licensed under [ODbL 1.0](https://opendatacommons.org/licenses/odbl/1-0/); see [OpenStreetMap copyright and licence information](https://www.openstreetmap.org/copyright). Retain source/snapshot attribution with generated geometry. These map features provide context, not certified indoor navigation or live accessibility status.

## Walking and leaving home

The first-stop departure reminder must use the **routed walk to the first boarding stop**, plus the boarding allowance. Interchange minimum times concern later vehicle-to-vehicle connections and must not accidentally be subtracted again from the home-to-stop walk.

Personal walking endpoints and phone GPS remain separate from the reusable public station map. An unavailable pedestrian connection must stay unavailable rather than being replaced by a straight shortcut through roads, rail tracks or station barriers. Follow actual platform signs where mapped indoor connectivity is incomplete.
