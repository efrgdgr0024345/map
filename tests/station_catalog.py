#!/usr/bin/env python3
"""Validate bundled station data using only the Python standard library.

No network, GTFS/PBF download or pyosmium dependency is used by these checks.
"""
from __future__ import annotations

import collections
import hashlib
import json
import math
from pathlib import Path
import re
import datetime as dt


ROOT = Path(__file__).resolve().parents[1] / "assets" / "station-maps"
KINDS = {"platform", "building", "footway", "steps", "rail", "entrance", "lift", "crossing", "bus_stop"}
checks = 0


def check(condition, label):
    global checks
    if not condition:
        raise AssertionError(label)
    checks += 1
    print("PASS:", label)


def timestamp(value):
    date = dt.datetime.fromisoformat(value.replace("Z", "+00:00"))
    return date.tzinfo is not None


def point(value):
    return (isinstance(value, list) and len(value) == 2
            and all(isinstance(item, (float, int)) and not isinstance(item, bool) and math.isfinite(item) for item in value)
            and -180 <= value[0] <= 180 and -90 <= value[1] <= 90)


def line(value, minimum=2):
    return isinstance(value, list) and len(value) >= minimum and all(point(item) for item in value)


def geometry(value):
    if not isinstance(value, dict):
        return False
    coords = value.get("coordinates")
    if value.get("type") == "Point":
        return point(coords)
    if value.get("type") == "LineString":
        return line(coords)
    if value.get("type") == "Polygon":
        return isinstance(coords, list) and bool(coords) and all(line(ring, 4) and ring[0] == ring[-1] for ring in coords)
    return False


def main():
    manifest_path = ROOT / "manifest.json"
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    check(manifest.get("version") == 1 and isinstance(manifest.get("stations"), dict)
          and isinstance(manifest.get("stopToStation"), dict), "versioned station catalog has the agreed object/lookup contract")
    stations, lookup = manifest["stations"], manifest["stopToStation"]
    check(len(stations) == 119, "catalog covers all 119 parent stations in the inspected official GTFS snapshot")
    source = manifest.get("source", {})
    check(source.get("name") == "Geofabrik / OpenStreetMap"
          and source.get("url") == "https://download.geofabrik.de/australia-oceania/australia/western-australia-latest.osm.pbf",
          "source is the approved read-only Geofabrik Western Australia extract")
    check(source.get("license") == "ODbL-1.0"
          and source.get("licenseUrl") == "https://opendatacommons.org/licenses/odbl/1-0/"
          and source.get("attribution") == "© OpenStreetMap contributors"
          and source.get("attributionUrl") == "https://www.openstreetmap.org/copyright",
          "derived database declares OpenStreetMap attribution and ODbL licensing")
    check(bool(re.fullmatch(r"[a-f0-9]{64}", source.get("sha256", "")))
          and timestamp(source["updatedAt"]) and timestamp(manifest["generatedAt"]),
          "catalog records a source hash and timezone-qualified source/build timestamps")
    check((ROOT / "LICENSE.txt").is_file() and (ROOT / "README.md").is_file(),
          "downloadable derived data includes licensing and reproducible build notes")

    files, seen_stops, unique, feature_total, byte_total = set(), {}, {}, 0, 0
    catalog_counts = collections.Counter()
    collections_by_id = {}
    for identifier, station in stations.items():
        assert re.fullmatch(r"[A-Za-z0-9_-]{1,80}", identifier), "unsafe station identifier"
        assert isinstance(station.get("name"), str) and station["name"], "station name missing"
        assert point([station.get("lon"), station.get("lat")]), "invalid station centre"
        bounds = station.get("bounds")
        assert isinstance(bounds, list) and len(bounds) == 4 and point(bounds[:2]) and point(bounds[2:]), "invalid station bounds"
        assert bounds[0] < bounds[2] and bounds[1] < bounds[3], "inverted station bounds"
        assert bounds[0] <= station["lon"] <= bounds[2] and bounds[1] <= station["lat"] <= bounds[3], "station centre outside its bounds"
        assert station.get("file") == f"station-{identifier}.json", "station file mapping changed unexpectedly"
        path = ROOT / station["file"]
        assert path.is_file() and not path.is_symlink() and 0 < path.stat().st_size < 4*1024*1024, "station file missing, unsafe or oversized"
        files.add(path.name)
        body = path.read_bytes(); byte_total += len(body)
        collection = json.loads(body)
        assert collection.get("type") == "FeatureCollection" and collection.get("stationId") == identifier, "station collection metadata mismatch"
        features = collection.get("features")
        assert isinstance(features, list) and len(features) == station.get("featureCount") and features, "station feature count mismatch or empty area"
        assert isinstance(station.get("stopIds"), list) and len(set(station["stopIds"])) == len(station["stopIds"]), "invalid or duplicate child stops"
        for stop in [identifier, *station["stopIds"]]:
            assert isinstance(stop, str) and stop and lookup.get(stop) == identifier, "stop-to-station lookup differs from declared children"
            assert stop not in seen_stops or seen_stops[stop] == identifier, "stop belongs to multiple GTFS parents"
            seen_stops[stop] = identifier
        area_counts, feature_ids = collections.Counter(), set()
        for feature in features:
            assert feature.get("type") == "Feature" and re.fullmatch(r"(?:node|way)/[0-9]+", feature.get("id", "")), "invalid stable OSM feature ID"
            assert feature["id"] not in feature_ids, "duplicate feature within a station"
            feature_ids.add(feature["id"])
            assert isinstance(feature.get("properties"), dict) and feature["properties"].get("kind") in KINDS, "unknown station feature category"
            assert geometry(feature.get("geometry")), "invalid geometry, nonfinite coordinate or open polygon"
            canonical = json.dumps(feature, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()
            digest = hashlib.sha256(canonical).digest()
            assert feature["id"] not in unique or unique[feature["id"]] == digest, "overlapping station areas disagree about the same OSM feature"
            unique[feature["id"]] = digest
            area_counts[feature["properties"]["kind"]] += 1
        assert dict(area_counts) == station.get("counts"), "station category totals do not match its features"
        catalog_counts.update(area_counts)
        feature_total += len(features)
        if identifier in ("83", "96"):
            collections_by_id[identifier] = features

    check(files == {path.name for path in ROOT.glob("station-*.json")}, "manifest references every generated station file exactly once")
    check(seen_stops == lookup, "all parent and child stop mappings are complete and mutually consistent")
    check(feature_total > 1000 and len(unique) > 1000, "all station files contain valid geometry and consistent feature counts")
    check(len(unique) < feature_total, "overlapping station extracts preserve identical stable OSM feature data for safe deduplication")
    check(all(value > 0 for value in catalog_counts.values()) and KINDS == set(catalog_counts), "catalog includes platforms, footways, stairs, rail, buildings, entrances, lifts, crossings and bus stops")
    check(byte_total < 32*1024*1024, "whole station catalog remains bounded for single-loader distribution")
    for station_id, expected in (("83", {"99631", "99632"}), ("96", {"99651", "99652"})):
        matches = {feature["properties"].get("gtfsStopId") for feature in collections_by_id[station_id]
                   if feature["properties"]["kind"] == "platform"}
        check(expected <= matches, f"station {station_id} keeps distinct boarding platforms linked by exact GTFS stop references")
    check(all(feature["properties"].get("gtfsStopId") not in stations
              for features in collections_by_id.values() for feature in features),
          "small local platform numbers cannot masquerade as GTFS parent station references")
    print(f"\n{checks} catalog integrity checks passed: {len(stations)} areas, {feature_total:,} records, "
          f"{len(unique):,} unique OSM features, {byte_total:,} bytes. No network or PBF dependency.")


if __name__ == "__main__":
    main()
