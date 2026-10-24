# Perth route colours

The route strokes and vehicle badges use colours published in the official
[Transperth GTFS ZIP](https://www.transperth.wa.gov.au/TimetablePDFs/GoogleTransit/Production/google_transit.zip),
specifically `routes.txt` fields `route_color` and `route_text_color`. These are
published data values, not colours sampled from a screenshot or guessed from a
line name. A current valid feed colour takes priority over every fallback.

The snapshot inspected on 24 October 2026 covers 20 October 2026 through
18 January 2027. All 423 routes contain a route colour: 409 bus routes, 13 rail
routes, and one ferry route. ZIP SHA-256:
`77720aec64ea75d61012c89cfb0355b9013f3e83a3fac92fee2692e024150e9d`.
This is provenance for the inspected snapshot, not a permanent download checksum;
the official feed changes as timetables are updated.

| Current rail line | Published route colour |
| --- | --- |
| Airport | `#4FC7B5` |
| Armadale | `#F2A900` |
| Thornlie-Cockburn | `#AB72C2` |
| Ellenbrook | `#D32737` |
| Fremantle | `#002F6C` |
| Midland | `#910048` |
| Mandurah | `#CB6015` |
| Yanchep | `#949300` |
| Stadium Special | `#009645` |

Joondalup is not a separately named current rail route in this feed. The helper
accepts the legacy name as an alias for **Yanchep Line** and uses the current
Yanchep colour. This does not establish a historical official Joondalup hex value.
The feed also includes Australind, Prospector, MerredinLink, and AvonLink, each
with `#CF4520`.

| Bus/ferry service | Published route colour |
| --- | --- |
| Standard green bus routes | `#009645` |
| Blue CAT | `#002F6C` |
| Red CAT | `#9D2235` |
| Yellow CAT | `#F1B434` |
| Green CAT | `#509E2F` |
| Purple CAT | `#65428A` |
| Surf CAT | `#009645` |
| Ferry | `#009645` |

Some regional bus routes publish `#CF4520`; using each route's feed colour
preserves that distinction. CAT routes with the same colour name in Perth and
Joondalup publish the same values in this snapshot. A synthetic demo route may
use the matching palette for illustration, but its route geometry, vehicles and
times remain explicitly demo data.

## Styling contract

`App\NetworkStyle::forRoute($name, $type, $feedColor, $feedTextColor)` returns:

- `color`: uppercase `#RRGGBB`.
- `textColor`: black or white, with a WCAG contrast ratio of at least 4.5:1 against
  that colour. Published black/white text is retained when it meets that ratio;
  otherwise the higher-contrast black/white label is used. This intentionally
  adjusts some feed text colours, including white on the light Airport colour.
- `colorSource`: `feed` for a valid supplied colour, `network` for a known named
  route fallback, or `mode` for a generic fallback.
- `lineName`: the recognized current line name, or the supplied name for an
  unknown service. Empty unknown names become Bus, Train, or Ferry.

Only six hexadecimal digits with an optional leading `#` are accepted as colour
inputs. Short hex, whitespace, CSS functions and other strings are rejected.
Known rail names are matched only for rail modes; an Airport bus is not assigned
the Airport train colour. Known CAT colour names are matched only for bus modes.
Unknown buses and ferries fall back to the published common `#009645`. Unknown
rail services fall back to application colour `#007EA8`; that generic fallback is
not claimed to be an official Perth line colour.

Rail mode handling follows the application's existing GTFS mapping: types 0, 1,
2 and extended types 100–199. Ferry is type 4 or extended types 1000–1099.

## Attribution

The [official spatial-data access page](https://www.transperth.wa.gov.au/About/Spatial-Data-Access)
requires products using this data to state that it is available free of charge
from `www.transperth.wa.gov.au`. The application should show that attribution.
Published colours are data values; this project does not use official logos or
claim PTA endorsement. PTA/Transperth trademarks and copyrighted materials are
restricted by the source terms. Data is provided as-is and may change or cease.
