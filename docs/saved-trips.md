# Resume the last trip on this device

The latest search is saved synchronously in `localStorage` under
`nextstop-active-trip-v1`: starting point, destination, arrival setting, selected
option ID, map centre/zoom and map-only mode. This is one replaceable trip, not a
history. It remains until Clear/cancel or a changed search. Editing an endpoint
removes the old saved search until both endpoints are chosen again.

Reopening restores the places and map view, then requests fresh departures.
Old vehicle positions, walking geometry and departure times are not replayed.
A past arrival deadline remains visible and must be changed; it is not silently
converted to a leave-now search. A saved GPS origin becomes “Saved starting
point” so a new device GPS fix cannot silently move the restored start.

These personal endpoints stay in this browser's storage, separate from the public
route cache. Clearing browser site data also removes them. Invalid saved data is
discarded; unavailable browser storage does not block ordinary planning.

In map-only mode every found nearby service direction is listed in a compact,
scrolling panel rather than a four-card limit. For GPS searches with a 500 m
radius, the map draws each found trip's exact route/shape pair from the public
route catalogue. It does not draw unrelated variants of the same route. The map
keeps its current camera; selecting a service still focuses its stop and route.
Onboard and selected-journey views keep their existing scope.

`tests/browser-trip-restore.mjs` covers eight nearby directions and shapes, reload,
fresh departure requests, camera and mode restoration, cancellation and malformed
storage at phone and desktop widths. API/GPS/tile responses are disclosed fixtures.
