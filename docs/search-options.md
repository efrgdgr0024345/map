# Search travel options

The main search form has five 44-pixel touch controls: Bus, Train, Ferry, Uber and
Walk. They start enabled. Changes are kept on this device under
`nextstop-search-modes-v1` and apply when reopening saved trips. Main journey
planning and day-planner API calls share `Nextstop.searchPreferences()`.

Bus/Train/Ferry alter the actual forward and reverse connection scans, including
transfers and the older SQLite implementation. The API accepts `modes=bus,train,ferry`
(or any subset, including an empty string). Values are validated against those
three names; they never become unchecked SQL. Excluding a mode can produce a
different connection rather than hiding legs after choosing an incompatible trip.

Walk off means `longWalks=0`: walking-only suggestions over ten minutes are
suppressed, using the refined walking duration as well as the initial estimate.
Short local walks remain valid. Walks to stops, between services and from the
last stop are retained. Walk on preserves the existing 40-minute direct-walk
search bound. Searches with no compatible journey return no route rather than
inventing a transit service.

Uber controls the existing optional last-leg deep-link card. Toggling it does
not change the selected transit journey or book a ride. There are no Uber APIs,
OAuth, fare requests or direct Uber trip searches. The official app confirms any
booking. Demo data does not claim to support filtered timetable searches.

Coverage: arrival-by tests exercise all three transit modes in forward and reverse
scans, older SQLite, access walking, short local walks and refined long-walk limits.
The search-mode browser suite checks phone touch targets, re-planning, Uber
visibility, saved preferences and the walking-access suggestion. API/GPS fixtures
are disclosed; actual Transperth timetables remain estimates.
