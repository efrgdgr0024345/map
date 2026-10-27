# Owner-managed Perth places

`assets/perth-pois.json` is the bundled seed: exactly 100 curated Perth-area
places, with 85 OpenStreetMap records and 15 Wikidata coordinate records. This
is an editorial selection of well-known attractions, museums, parks, beaches,
shopping centres and venues, not a measured visitor-popularity ranking. OSM
records come from the same Geofabrik WA snapshot as the station maps, dated
23 October 2026. Wikidata points were retrieved on 27 October 2026. Per-place
provenance and the PBF SHA-256 are retained. See `assets/perth-pois.LICENSE.txt`.

The map loads the catalogue once from `api.php?action=pois`, renders only local
visible markers, and clusters them at wider zooms. Panning does not fetch POI
geometry. The POI pin button hides/shows the layer. ETags support revalidation;
a focus/visibility check may reload an older catalogue after five minutes.
No external venue or event scraping runs in a user's map session.

Address autocomplete checks the local catalogue first, including aliases such
as Optus Stadium → Perth Stadium. Matches return immediately without waiting
for Photon. **Search other addresses** explicitly requests the normal address
provider when the local results are not the desired address. The same search
works in saved-place and calendar-event location fields.

## Edit and publish

1. Update normally using the single `loader.php`.
2. Unlock the loader and open **Perth places catalogue**.
3. Download the active JSON, edit its records, and upload it through
   **Validate & publish places**. Validation runs before replacing the current
   catalogue. The upload limit is 1 MB and the list limit is 500 places.
4. Refresh the map to load the revision. The upload is stored as valid JSON in
   protected `var/perth-pois.json`; normal GitHub updates preserve it. When a
   custom catalogue exists, new bundled POIs do not silently overwrite it.
5. To return to the GitHub seed, explicitly confirm **Restore bundled places**.

The catalogue is public map data. Keep source credits when updating it. A
malformed manually edited override falls back to the bundled seed; it cannot
break the transport planner. An invalid upload retains the last active file.

## Record fields

Each place has a stable unique `id`, a display `name`, searchable `aliases`, a
`category`, and numeric `lat`/`lon` within the app's Perth region. Reserved
structures include:

- `location`: coordinate kind, source and entrance confidence.
- `navigation`: a separately mapped entrance/reviewed access point, or `null`.
- `address`: house number, street, suburb, postcode and display text.
- `openingHours`: recorded text, Australia/Perth timezone, source, source date
  and optional verification date. Unknown hours stay `null`.
- `contact`: website, phone, email, booking URL and social-link fields.
- `whatsOn`: event list, source URL and last-checked date.
- `accessibility`: mapped wheelchair value, step-free information and notes.
- `defaultStayMinutes`: 5–720, suggested when planning a visit.
- `display`: `enabled` boolean and editorial `priority` number.
- `editorial`: public summary and last-reviewed date.
- `pricing`: description, AUD currency and booking requirement.
- `sources`: source URLs, licences, snapshot/retrieval dates.

Museum, shopping and park area centres are not assumed to be entrances.
Search/map selection asks the user to choose an entrance where none is known.
Known mapped boundary entrances and the existing reviewed Fremantle Oval
Parry Street access can be used directly. A mapped door is not a guarantee of
current access or step-free suitability. Street walking is still independently
checked by the pedestrian router.

The initial event lists are empty. Opening-hours strings, contacts and websites
are snapshots of supplied source tags, not live venue verification. Popups show
recorded hours with the source date and offer the venue website; the app does
not claim Open now from an unparsed hours string.

To add an event, put an object like this in `whatsOn.events`:

```json
{
  "id": "venue-event-001",
  "title": "Your confirmed event title",
  "start": "2026-11-01T10:00:00+08:00",
  "end": "2026-11-01T12:00:00+08:00",
  "url": "https://example.com/official-event"
}
```

That is a schema example, not a real event. The popup hides expired events,
shows On now only inside the recorded start/end interval, and can show future
event dates. Links are restricted to HTTP/HTTPS and popup text is rendered as
text rather than HTML. Use a source URL and review dates when publishing events.
The software cannot verify that an owner's event has not been cancelled.

## Development builds

The selected source IDs, aliases and frozen Wikidata points are in
`scripts/data/perth-poi-selection.json`. The reviewed OSM source records are in
`scripts/data/perth-poi-osm-records.json`, including the extract source date/hash.
Rebuild the public JSON with Python's standard library:

```sh
python3 scripts/build-poi-catalog.py
```

For a later trusted local Geofabrik extract, `scripts/extract-poi-records.py`
requires pyosmium 4.x and writes source records without network calls:

```sh
python3 scripts/extract-poi-records.py --pbf /path/to/western-australia.osm.pbf \
  --source-date 2026-10-23T23:04:02Z --output /tmp/poi-source-records.json
python3 scripts/build-poi-catalog.py --records /tmp/poi-source-records.json
```

Use the actual date for the new source. Recheck selected objects, entrances,
changed names and contacts before publishing; the scripts do not certify them.
Neither pyosmium nor a PBF is needed on cPanel. The local JSON catalogue is all
that a deployed app needs.
