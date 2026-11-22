# Optional Uber for the final walking leg

The selected itinerary offers **Open in Uber** when it is a real timetable
journey, the user is following it, and its final leg is a walk longer than
**180 seconds** immediately after a bus, train or ferry. The previous transit
leg must identify a trip. Walking-only and demo journeys are excluded.

This card belongs to the selected itinerary. It does not appear on live vehicle
markers, nearby previews, stop/timetable boards, alternative cards or unchosen
day-planner drafts. Explicitly choosing **Show this journey on map** in the day
planner makes that real journey eligible. Exploring/pausing following hides the
card; recentering restores it. Clearing or completing the journey removes it.
The original transit/walking legs are never replaced or edited.

## Settings and estimate

`assets/app.js` contains the focused logic:

- `LAST_MILE_UBER_THRESHOLD_SECONDS = 180` near the top. To raise the trigger to
  ten minutes, change this to `600`; the comparison is strictly greater than.
- `LAST_MILE_UBER_FARE`: A$5 base, A$2.20/km, A$8 minimum and a 1.25 multiplier
  on straight-line pickup-to-destination distance as a rough road proxy.
- `lastMileUberOffer()` checks eligibility, builds the approximate range and
  link. The range is 85%–140% of the rough base estimate, with an A$8 floor.
- `renderLastMileUber()` fills the optional `#last-mile-uber` card in `index.php`.

Walking minutes avoided are the final leg's duration rounded up; timestamps are
used if its duration is absent. This is walking avoided, not a prediction of net
journey time saved. Pickup wait, actual road time and the actual Uber fare are
unknown. The UI labels the price approximate and asks the user to confirm fare
and pickup in Uber.

## Handoff

A normal user-activated link opens `https://m.uber.com/ul/` with
`action=setPickup`, `pickup[latitude/longitude/nickname]` and
`dropoff[latitude/longitude/nickname]`. Pickup is exactly the final walk's
`from` point (the alighting stop/platform); drop-off is its `to` destination.
Names are URL encoded. The user confirms a usable pickup and completes booking
in the official Uber app or mobile website, depending on device handling.

No Uber SDK, OAuth, pricing/partner API, automatic booking, prefetch or background
coordinate handoff is added. The link opens only when clicked and uses
`noopener noreferrer`. No Uber journey or fare is stored on this server.

`node tests/browser-last-mile-uber.mjs` covers threshold boundaries, bus/train/
ferry journeys, all excluded contexts, explicit day-planner selection, pickup/
drop-off encoding, unchanged transit plans and a click-only mobile handoff at
320, 390, 430 and 1366 px. Timetable, GPS, tiles and the Uber landing page are
explicit fixtures. It does not book a ride, obtain real fares or verify an
installed native Uber app. GitHub's browser job runs this suite before a release
can pass its deployment gate.
