# Arrive at the destination by a chosen time

The main trip form offers **Leave now** and **Arrive by**. Arrival mode exposes
native date/time pickers and 30-minute, one-hour and two-hour shortcuts, with
44-pixel or larger touch targets. All entry and result times are explicitly
Perth time, including when the phone is set to another timezone. Fields use
16-pixel text to avoid unwanted iOS input zoom.

Choose the destination, select **Arrive by**, and enter when you need to be there.
The form sends the existing `api.php?action=plan` endpoint an `arriveBy` timestamp
with an explicit timezone. It does not send a departure `at` value for this flow.
The backend's reverse timetable search chooses the latest feasible start, and
`StreetJourneys` checks pedestrian routes, transfer timing and the final walk to
the actual destination before accepting a journey. The result shows **Leave by**,
the scheduled destination arrival and the requested deadline.

Changing either date/time or planning mode cancels an earlier request and
invalidates its result. A stale response cannot overwrite the new deadline.
Past, incomplete and more-than-fourteen-day deadlines are explained before a
planning request. The browser also rejects late or malformed arrival responses
and demo responses for arrival mode. A deadline with no feasible journey produces
an explicit message rather than falling back silently to leaving now.

The controls and request wiring live in `index.php` and `assets/app.js`. Native
inputs are interpreted with the explicit `+08:00` Perth offset, never with the
phone's local timezone. The existing planning algorithms live in
`src/Transit.php::planArrivingBy` and `src/StreetJourneys.php`; their walking and
boarding checks remain unchanged. Day-planner appointments continue to use the
same backend deadline and retain it when opened on the main map.

`tests/arrive_by.php` verifies backward timetable search with different final
walks, transfer restrictions, latest departures and after-midnight services.
`tests/browser-arrival-colours.mjs` checks the actual mobile controls at 320, 390
and 430 pixels plus desktop, phone timezone differences, input validation,
changed deadlines, cancellation, unavailable trips, and leave-now restoration.
Those automated browser cases use explicitly disclosed timetable, GPS, clock and
tile fixtures; service times remain estimates rather than a guarantee of actual
on-time travel.
