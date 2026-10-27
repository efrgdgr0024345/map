# Day planner and Google Calendar

The Day planner opens from the map header on phone and desktop. Saved places
(Home, Gym, Shops or any name) live in this browser's local storage, not a shared
server address book. Add an address result or the current map centre, choose a
place, enter the stay duration, and plan the day. **Leave now** is the default;
**Choose a departure time** reveals the Perth date/time picker. A usual stay can
be saved with each place. **Remember this stay** saves a chosen visit duration,
and **Edit stay** changes an already-added visit. Up to five visits plus a return
home are supported. Each leg is calculated in order: the next search begins at
the preceding arrival plus its stay. There is no visit-order optimiser.

The start clock and displayed times use Australia/Perth, including when the
browser is elsewhere. Planning dates range from now to fourteen days ahead,
subject to the imported feed's coverage. Arrival-by searches work backwards
through scheduled connections in the three hours before the deadline, preserve
service-day identity, pickup/drop-off and official transfer rules, then refine
walking paths using the existing bounded StreetJourneys process. Only verified
walks that still meet the appointment deadline are accepted. Ordinary searches
retain their existing departure-time behaviour.

An official-feed integration on 27 October 2026 returned two walking-verified
Perth Busport → Fremantle Oval access journeys for arrival by 10:00 am on
28 October. The first arrived at 9:59:25 am with a 9:14:09 am leave time. That
cloud run took about 34 seconds and peaked at 26 MB; it is evidence for that
request, not a cPanel performance guarantee or a physical journey test.

## One-time Google setup

The map, saved places, day planning and calendar-file downloads work without
Google credentials. To enable Google sign-in for visitors:

1. In Google Cloud, enable Google Calendar API and configure an OAuth consent
   screen with the site identity, authorised domain and appropriate published
   privacy information. Select External when accounts outside your organisation
   should connect. Add test users while the app is in Testing.
2. Create an OAuth client of type **Web application**. Register exactly
   `https://easyai.com.au/bus/calendar.php` as an authorised redirect URI.
3. Unlock `loader.php`, open **Google Calendar connection**, and enter the
   client ID, client secret and that same redirect URI. These values are saved
   in guarded `var/google-calendar.php`, preserved by updates. The password
   field never echoes the saved secret; leave it blank to retain the old one.
4. Request these scopes: `https://www.googleapis.com/auth/calendar.events.readonly`
   and `https://www.googleapis.com/auth/calendar.app.created`.
5. Connect a permitted test account from **Day planner → Google Calendar**.
   Google may require app verification before broad public use; refresh tokens
   for external apps in Testing can expire after seven days.

No live OAuth sign-in has been validated without owner credentials. The current
Google tests use a simulated HTTP transport, and owner setup/session/CSRF tests
use an isolated local PHP server. They must not be presented as a successful
real Google account connection.

## User choices and storage

Connecting requests calendar access through Google, with state, PKCE and a
one-use callback. The user explicitly loads a selected day's first 100 primary
calendar events; pagination is disclosed when more exist. All-day events are
shown but cannot be arrival targets without a timed appointment. Shared and
secondary calendars are not listed in this version.

Selecting an event does not start a trip search or change a calendar. The user
chooses its entrance and checks **Plan public transport for this selected event**.
The event's start becomes the arrival deadline; its end becomes the earliest
return departure. Existing appointments are never edited. After planning, a
separate review lists travel blocks; the user chooses blocks and agrees before
adding them to the app-created **Nextstop Trips** calendar. Event IDs are
deterministic to avoid duplicate inserts on normal retries.

Access/refresh tokens are held only in private PHP session files under
`var/calendar-sessions/`. The browser receives a Secure, HttpOnly, SameSite=Lax
session cookie, not Google tokens. Sessions are separate from loader/admin
sessions and configured for thirty days. Disconnect clears the visitor's
calendar state and attempts Google token revocation. Owner credential changes
invalidate connections. No background event import or automatic event writing
runs. A pending visit list can briefly use tab-scoped sessionStorage to survive
Google's redirect; it contains no OAuth credentials or tokens.

## Phone reminders

Travel blocks begin at the time to leave, allowing for walking and boarding.
Google travel events request popup reminders ten minutes before leaving and at
departure. Visitors must enable Nextstop Trips, sync and notifications in Google
Calendar on their phone. Delivery depends on the calendar app and device.

The downloadable `.ics` file includes the same travel blocks and VALARM records.
Calendar apps can ignore or alter imported reminders; the visitor must check them.
Optional browser alerts are driven by timers in the open page, using service
worker notifications where supported. The worker does not cache maps, inspect
requests, use push, or schedule independent background alarms. Closing or
suspending the page can prevent these alerts. This website does not install
native Clock alarms or guarantee an alert on a locked phone.

## Source references

- [Google web-server OAuth flow](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Calendar creation scopes](https://developers.google.com/workspace/calendar/api/v3/reference/calendars/insert)
- [Event listing scopes](https://developers.google.com/workspace/calendar/api/v3/reference/events/list)
- [Event insertion and reminders](https://developers.google.com/workspace/calendar/api/v3/reference/events/insert)
