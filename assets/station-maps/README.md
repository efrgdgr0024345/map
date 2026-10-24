# Bundled station map data

These openly downloadable GeoJSON files are a derived OpenStreetMap database,
available under the **Open Database License (ODbL) 1.0**. Attribution:
**© OpenStreetMap contributors** — <https://www.openstreetmap.org/copyright>.
Licence: <https://opendatacommons.org/licenses/odbl/1-0/>.

The source is the read-only **Geofabrik Western Australia** extract:

- <https://download.geofabrik.de/australia-oceania/australia/western-australia-latest.osm.pbf>
- Source file Last-Modified: **2026-10-23T23:04:02Z**.
- Source size: **115,362,675 bytes**.
- SHA-256: `ea33f43b0278ceeeec37fb0d18f54dde17bfddb475eacdceb7fe8a4fc6253412`.

The catalogue covers areas around all **119 parent stations/interchanges** in
the inspected official Transperth GTFS. Station names and child-stop groupings
come from that feed; timetable data is available free of charge from
www.transperth.wa.gov.au. Each area has at least a 350-metre margin around its
centre, expanded when needed for child stops, with a 600-metre cap per axis.

Mapped platforms, buildings, footpaths, stairs, rail tracks, crossings, lifts,
bus stops and identifiable station entrances are retained. A generic entrance
node is included only when it belongs to a mapped station building; ordinary
house front doors are excluded. Original OSM IDs, levels and access restrictions
remain attached. Exact recognised `ref:pta`/`ref` values can link a feature to a
GTFS boarding stop; local platform numbers never match a GTFS parent identifier.

This is **map context**, not a certified indoor floor plan, routing graph,
accessibility audit or live lift-status service. OSM ways are not joined because
their lines happen to cross. Station building multipolygon relations are not
assembled by this small builder, and incomplete mapping remains incomplete.
The route shown by the application still comes from its pedestrian router.

`manifest.json` records the source and station-to-file/child-stop mappings.
Each `station-{parentID}.json` is a standard GeoJSON FeatureCollection using
`[longitude,latitude]` coordinates and stable IDs such as `way/51379370`.
The whole catalogue is about **14 MB uncompressed / 2.3 MB gzip-equivalent**;
the server reads only the one or two local areas needed for a transfer. There
is no runtime download on map movement and no Python requirement on cPanel.

## Rebuild in development

Use Python 3.11+ and pyosmium 4.x in an isolated development environment. Do not
install these dependencies on cPanel. Obtain a current extract from the fixed
Geofabrik URL, verify its size/hash and source timestamp, then run from the
repository root:

```sh
python3 -m venv /tmp/nextstop-osm-build
/tmp/nextstop-osm-build/bin/pip install osmium==4.3.2
/tmp/nextstop-osm-build/bin/python scripts/build-station-maps.py \
  --pbf /tmp/western-australia-latest.osm.pbf \
  --gtfs var/official-gtfs.zip \
  --source-date SOURCE_LAST_MODIFIED_ISO_TIMESTAMP \
  --output assets/station-maps
```

The builder does not download data and never uses the OpenStreetMap editing
API. Keep the large PBF, its temporary location index and development packages
outside the repository. Commit the small output files, source metadata and
attribution together. Overpass was tested for a small station query during this
review but timed out on three public instances; it is not a runtime dependency.
