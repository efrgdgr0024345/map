#!/usr/bin/env python3
"""Build small attributed station overlays from a trusted local Geofabrik PBF.

Development-only dependency: pyosmium 4.x. cPanel serves the resulting JSON and
does not need Python, osmium, an XML extension or an external station API.

Example:
  python3 scripts/build-station-maps.py --pbf /tmp/western-australia.osm.pbf \
    --gtfs var/official-gtfs.zip --source-date 2026-10-23T23:04:02Z

The source extract must be obtained from the fixed read-only Geofabrik URL
below. This tool never calls OSM's editing API or Overpass, and never invents a
connection between paths. Geometry is map context, not a routing graph.
"""
from __future__ import annotations

import argparse
import collections
import csv
import datetime as dt
import hashlib
import io
import json
import math
from pathlib import Path
import re
import tempfile
import zipfile

import osmium

SOURCE_URL = "https://download.geofabrik.de/australia-oceania/australia/western-australia-latest.osm.pbf"
ATTRIBUTION = "© OpenStreetMap contributors"
TAG_KEYS = ("name", "ref", "ref:pta", "local_ref", "level", "layer", "access", "foot",
            "wheelchair", "bridge", "tunnel", "indoor", "incline", "conveying", "surface", "entrance")
GRID = 0.01


def gtfs(path: Path):
    with zipfile.ZipFile(path) as archive:
        names = [name for name in archive.namelist() if name.rsplit("/", 1)[-1] == "stops.txt"]
        if len(names) != 1:
            raise ValueError("Choose a GTFS archive containing exactly one stops.txt.")
        source = csv.DictReader(io.TextIOWrapper(archive.open(names[0]), encoding="utf-8-sig"))
        rows = [{key.strip(): value.strip() for key, value in row.items()} for row in source]
    parents, children = {}, collections.defaultdict(list)
    known = {row["stop_id"] for row in rows if row.get("location_type", "0") != "1"}
    by_id = {row["stop_id"]: row for row in rows}
    for row in rows:
        if row.get("parent_station"):
            children[row["parent_station"]].append(row["stop_id"])
        if row.get("location_type") == "1":
            parents[row["stop_id"]] = row
    stations = {}
    lookup = {}
    for identifier, row in parents.items():
        if not re.fullmatch(r"[A-Za-z0-9_-]{1,80}", identifier):
            raise ValueError("Station IDs must be safe file-name identifiers.")
        lat, lon = float(row["stop_lat"]), float(row["stop_lon"])
        if not (math.isfinite(lat) and math.isfinite(lon) and -35 <= lat <= -13 and 112 <= lon <= 130):
            raise ValueError("A station centre is not in the Western Australia source region.")
        # A 350m baseline includes adjacent transfer stands. Expand for children,
        # with 100m margin; cap malformed/distant associations at 600m per axis.
        lats, lons = [lat], [lon]
        for child in children[identifier]:
            try:
                child_lat, child_lon = float(by_id[child]["stop_lat"]), float(by_id[child]["stop_lon"])
                if abs(child_lat-lat) < .02 and abs(child_lon-lon) < .02:
                    lats.append(child_lat); lons.append(child_lon)
            except (ValueError, KeyError):
                continue
        dlat = min(600/111000, max(350/111000, max(abs(value-lat) for value in lats)+100/111000))
        metre_lon = 111000*math.cos(math.radians(lat))
        dlon = min(600/metre_lon, max(350/metre_lon, max(abs(value-lon) for value in lons)+100/metre_lon))
        bounds = [round(lon-dlon, 7), round(lat-dlat, 7), round(lon+dlon, 7), round(lat+dlat, 7)]
        stations[identifier] = {"name": row["stop_name"], "lat": lat, "lon": lon, "bounds": bounds,
                                "file": f"station-{identifier}.json", "stopIds": sorted(children[identifier])}
        for stop in [identifier, *children[identifier]]:
            lookup[stop] = identifier
    return stations, lookup, known


def kind(tags, node=False):
    if node:
        if tags.get("highway") == "elevator": return "lift"
        if tags.get("railway") == "subway_entrance": return "entrance"
        if tags.get("entrance") in ("yes", "main", "entrance"): return "entrance"
        if tags.get("highway") == "crossing": return "crossing"
        if tags.get("highway") == "bus_stop": return "bus_stop"
        return None
    if tags.get("railway") == "platform" or tags.get("public_transport") == "platform": return "platform"
    if tags.get("highway") == "steps": return "steps"
    if tags.get("highway") in ("footway", "path", "pedestrian"):
        return "footway"
    if tags.get("railway") in ("rail", "light_rail", "subway") and tags.get("service") not in ("yard", "siding", "spur"):
        return "rail"
    if tags.get("building") and tags.get("building") != "no": return "building"
    return None


class StationHandler(osmium.SimpleHandler):
    def __init__(self, stations, known):
        super().__init__()
        self.stations, self.known = stations, known
        self.features = {identifier: {} for identifier in stations}
        self.generic_entrances = set()
        self.station_building_nodes = set()
        self.grid = collections.defaultdict(set)
        for identifier, station in stations.items():
            west, south, east, north = station["bounds"]
            for x in range(math.floor(west/GRID), math.floor(east/GRID)+1):
                for y in range(math.floor(south/GRID), math.floor(north/GRID)+1):
                    self.grid[(x, y)].add(identifier)

    def properties(self, tags, category):
        props = {"kind": category}
        for key in TAG_KEYS:
            if key in tags:
                props[{"ref:pta": "ptaRef", "local_ref": "localRef"}.get(key, key)] = tags[key]
        # Platform numbers such as ref=1 must never match a GTFS parent ID=1.
        matches = []
        for key in ("ref:pta", "ref"):
            matches = [value.strip() for value in tags.get(key, "").split(";") if value.strip() in self.known]
            if matches: break
        if len(matches) == 1: props["gtfsStopId"] = matches[0]
        elif matches: props["gtfsStopIds"] = matches
        return props

    def store(self, identifier, points, category, tags, osm_type):
        candidates = set()
        for lon, lat in points:
            candidates.update(self.grid.get((math.floor(lon/GRID), math.floor(lat/GRID)), ()))
        if not candidates: return
        west, east = min(p[0] for p in points), max(p[0] for p in points)
        south, north = min(p[1] for p in points), max(p[1] for p in points)
        closed = len(points) > 3 and points[0] == points[-1]
        polygon = category in ("platform", "building") and closed
        if osm_type == "node": geometry = {"type": "Point", "coordinates": points[0]}
        elif polygon: geometry = {"type": "Polygon", "coordinates": [points]}
        else: geometry = {"type": "LineString", "coordinates": points}
        feature = {"type": "Feature", "id": f"{osm_type}/{identifier}",
                   "properties": self.properties(tags, category), "geometry": geometry}
        for station_id in candidates:
            box = self.stations[station_id]["bounds"]
            if east < box[0] or west > box[2] or north < box[1] or south > box[3]: continue
            self.features[station_id][feature["id"]] = feature

    def node(self, node):
        category = kind(node.tags, node=True)
        if category and node.location.valid():
            if category == "entrance" and node.tags.get("railway") != "subway_entrance":
                # A generic house front door is not a station entrance. Keep a
                # candidate only when a mapped station building later confirms
                # that this same OSM node belongs to its footprint.
                self.generic_entrances.add(f"node/{node.id}")
            self.store(node.id, [[round(node.location.lon, 7), round(node.location.lat, 7)]], category, node.tags, "node")

    def way(self, way):
        category = kind(way.tags)
        if not category: return
        if way.tags.get("building") in ("train_station", "transportation") or way.tags.get("railway") == "station" or way.tags.get("public_transport") == "station":
            self.station_building_nodes.update(f"node/{node.ref}" for node in way.nodes)
        try:
            points = [[round(node.lon, 7), round(node.lat, 7)] for node in way.nodes]
        except osmium.InvalidLocationError:
            return
        if len(points) < 2: return
        self.store(way.id, points, category, way.tags, "way")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--pbf", required=True, type=Path)
    parser.add_argument("--gtfs", required=True, type=Path)
    parser.add_argument("--source-date", required=True, help="ISO timestamp of Geofabrik Last-Modified/header")
    parser.add_argument("--output", default=Path("assets/station-maps"), type=Path)
    parser.add_argument("--stations", help="Optional comma-separated GTFS parent IDs for a sample build")
    args = parser.parse_args()
    source_date = dt.datetime.fromisoformat(args.source_date.replace("Z", "+00:00"))
    if source_date.tzinfo is None: raise ValueError("Source timestamp must include a timezone.")
    stations, lookup, known = gtfs(args.gtfs)
    if args.stations:
        chosen = set(args.stations.split(","))
        stations = {key: value for key, value in stations.items() if key in chosen}
        lookup = {key: value for key, value in lookup.items() if value in stations}
    if not stations: raise ValueError("No stations were selected.")
    handler = StationHandler(stations, known)
    with tempfile.TemporaryDirectory(prefix="nextstop-osm-index-") as temporary:
        # Populate locations in C++, then discard untagged/unrelated objects
        # before invoking Python. A regional extract contains millions of plain
        # geometry nodes; running Python on each one is needlessly slow.
        locations = osmium.index.create_map(f"sparse_file_array,{temporary}/nodes.idx")
        locate = osmium.NodeLocationsForWays(locations)
        locate.ignore_errors()
        with osmium.io.Reader(str(args.pbf)) as reader:
            osmium.apply(reader, locate, osmium.filter.KeyFilter("building", "highway", "railway", "public_transport", "entrance"), handler)
    args.output.mkdir(parents=True, exist_ok=True)
    total = 0
    for identifier, station in stations.items():
        features = sorted((feature for feature in handler.features[identifier].values()
                           if feature["id"] not in handler.generic_entrances or feature["id"] in handler.station_building_nodes),
                          key=lambda feature: feature["id"])
        station["counts"] = dict(sorted(collections.Counter(feature["properties"]["kind"] for feature in features).items()))
        station["featureCount"] = len(features)
        body = {"type": "FeatureCollection", "stationId": identifier, "features": features}
        output = json.dumps(body, ensure_ascii=False, separators=(",", ":"), allow_nan=False)
        (args.output/station["file"]).write_text(output+"\n", encoding="utf-8")
        total += len(output.encode("utf-8"))+1
    source = {"name": "Geofabrik / OpenStreetMap", "url": SOURCE_URL,
              "updatedAt": source_date.astimezone(dt.timezone.utc).isoformat().replace("+00:00", "Z"),
              "sha256": hashlib.file_digest(args.pbf.open("rb"), "sha256").hexdigest(),
              "license": "ODbL-1.0", "licenseUrl": "https://opendatacommons.org/licenses/odbl/1-0/",
              "attribution": ATTRIBUTION, "attributionUrl": "https://www.openstreetmap.org/copyright"}
    manifest = {"version": 1, "generatedAt": dt.datetime.now(dt.timezone.utc).isoformat().replace("+00:00", "Z"),
                "source": source, "stations": stations, "stopToStation": lookup}
    (args.output/"manifest.json").write_text(json.dumps(manifest, ensure_ascii=False, separators=(",", ":"), allow_nan=False)+"\n", encoding="utf-8")
    (args.output/"LICENSE.txt").write_text(
        "Station geometry is © OpenStreetMap contributors, available under the Open Database License (ODbL) 1.0.\n"
        "https://www.openstreetmap.org/copyright\nhttps://opendatacommons.org/licenses/odbl/1-0/\n"
        "Derived from the Geofabrik Western Australia extract listed in manifest.json.\n"
        "GTFS station names/groupings locate these excerpts; timetable data is available free of charge from www.transperth.wa.gov.au.\n"
        "This is mapped context, not a certified station floor plan, accessibility audit or live lift-status feed.\n", encoding="utf-8")
    print(json.dumps({"stations": len(stations), "features": sum(value["featureCount"] for value in stations.values()),
                      "geometryBytes": total, "source": source}, indent=2))


if __name__ == "__main__":
    main()
