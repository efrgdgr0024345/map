<?php
declare(strict_types=1);
header('Cache-Control: no-cache, must-revalidate');
function assetUrl(string $path): string
{
    $file = __DIR__ . '/' . $path;
    $revision = is_file($file) ? substr(hash_file('sha256', $file), 0, 12) : 'pending';
    return htmlspecialchars($path . '?v=' . $revision, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#f6f7f3">
  <meta name="description" content="Find your next Perth bus or train, follow your journey, and see the network in motion on an open map.">
  <title>Nextstop — Perth in motion</title>
  <link rel="icon" type="image/svg+xml" href="<?= assetUrl('assets/black-cat.svg') ?>">
  <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=">
  <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
  <link rel="stylesheet" href="<?= assetUrl('assets/planner.css') ?>">
  <script src="assets/vendor/leaflet/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" defer></script>
  <script src="<?= assetUrl('assets/app.js') ?>" data-station-catalog="<?= assetUrl('assets/station-maps/manifest.json') ?>" defer></script>
  <script src="<?= assetUrl('assets/planner.js') ?>" defer></script>
</head>
<body class="mascot-visible">
  <div id="map" aria-label="Interactive Perth public transport map"></div>

  <header class="topbar">
    <a class="brand" href="./" aria-label="Nextstop home"><img class="brand-cat" src="<?= assetUrl(is_file(__DIR__ . '/assets/mascot-head.webp') ? 'assets/mascot-head.webp' : 'assets/black-cat.svg') ?>" alt="" width="46" height="46"><span>nextstop<span class="brand-dot">.</span></span></a>
    <button type="button" id="open-day-planner" class="day-planner-launch" aria-haspopup="dialog">Day planner</button>
    <div class="topbar-right"><span class="city-label"><span class="city-dot"></span>PERTH, WA</span><span class="clock" id="perth-clock">--:--</span></div>
  </header>

  <button type="button" id="open-panel" class="panel-launcher" aria-controls="journey-panel" aria-expanded="false"><span class="launcher-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg></span><span class="launcher-copy"><strong id="launcher-title">Where are you heading?</strong><span id="launcher-detail">Search, choose a stop, or plan a journey</span></span><svg class="launcher-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg></button>
  <button type="button" id="mascot-peek" class="mascot-peek is-arriving" aria-label="Tuck away the cat mascot"><img class="mascot-peek-head" src="<?= assetUrl('assets/mascot-head.webp') ?>" alt="" width="56" height="56"><span class="mascot-paw mascot-paw-left" style="background-image: url('<?= assetUrl('assets/mascot-paws.webp') ?>')" aria-hidden="true"></span><span class="mascot-paw mascot-paw-right" style="background-image: url('<?= assetUrl('assets/mascot-paws.webp') ?>')" aria-hidden="true"></span></button>

  <main class="journey-panel" id="journey-panel" aria-label="Plan your journey" tabindex="-1">
    <button type="button" id="sheet-handle" class="panel-handle" aria-label="Drag down or tap to hide journey panel" aria-controls="journey-panel"><span aria-hidden="true"></span></button>
    <button type="button" id="close-panel" class="panel-close" aria-label="Hide journey panel"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button>
    <div class="panel-intro">
      <div class="eyebrow">A BETTER WAY TO GET THERE</div>
      <h1>Your city.<br><span>In motion.</span></h1>
      <p>Find your next ride. See what’s coming.</p>
    </div>

    <div class="data-banner" id="data-banner" role="status"><span class="status-dot"></span><span id="data-banner-text">Connecting to the network…</span><span id="compact-schedule-status" class="compact-schedule-status">Scheduled</span></div>

    <form id="journey-form" autocomplete="off">
      <div class="location-fields">
        <div class="location-rail" aria-hidden="true"><span class="origin-dot"></span><span class="rail-line"></span><span class="destination-dot"></span></div>
        <div class="location-inputs">
          <div class="location-row">
            <label class="sr-only" for="origin-input">Starting point</label>
            <input id="origin-input" name="origin" type="text" value="Perth Busport" placeholder="Starting point" spellcheck="false" aria-controls="search-results" readonly aria-description="Choose a point on the map, or use Search starting address.">
            <button class="icon-button location-search" type="button" data-search="origin" aria-label="Search starting address" title="Search starting address">⌕</button>
          </div>
          <div class="location-row">
            <label class="sr-only" for="destination-input">Destination</label>
            <input id="destination-input" name="destination" type="text" placeholder="Where are you going?" spellcheck="false" aria-controls="search-results" readonly aria-description="Choose a point on the map, or use Search destination address.">
            <button class="icon-button location-search" type="button" data-search="destination" aria-label="Search destination address" title="Search destination address">⌕</button>
          </div>
        </div>
      </div>
      <div id="search-results" class="search-results" aria-label="Address search results" hidden></div>
      <div class="location-options">
        <button type="button" id="use-location" class="text-button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m20 4-6.1 16-3.5-6.4L4 10.1 20 4Z"/></svg>Use my location</button>
        <button type="button" id="choose-on-map" class="text-button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 10c0 5-7 10-7 10S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2"/></svg>Choose on map</button>
      </div>
      <button type="submit" class="primary-button" id="plan-button"><span>Find my next ride</span><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 19 19 5M6 5h13v13"/></svg></span></button>
    </form>

    <div class="message" id="message" role="status" aria-live="polite" hidden></div>
    <div id="journey-results" hidden>
      <div class="results-heading"><h2>Your journey</h2><button type="button" id="overview-journey" class="text-button">Overview</button><button type="button" id="clear-journey" class="text-button">Clear <span aria-hidden="true">×</span></button></div>
      <div id="journey-options" class="journey-options"></div>
      <div id="next-service" class="next-service" hidden></div>
      <div id="itinerary" class="itinerary"></div>
      <button type="button" id="network-toggle" class="network-toggle" aria-pressed="false"><span class="toggle-track"><span></span></span><span>Show the whole network</span></button>
    </div>

    <div class="panel-footer"><details class="about-details"><summary>About &amp; data sources</summary><p class="timetable-attribution"><span class="data-source-label">Data source</span>Timetable data available free of charge from <a href="https://www.transperth.wa.gov.au/About/Spatial-Data-Access" target="_blank" rel="noopener" aria-label="Transperth timetable data source website">www.transperth.wa.gov.au</a></p><p class="about-note">Vehicle positions follow the timetable, rather than live GPS.</p><p id="phone-source-note" class="about-note">Mapped phone locations show their published source. Coverage and current availability can vary.</p></details></div>
  </main>

  <div class="map-hint" id="map-hint" hidden><span id="map-hint-text">Tap the map to choose your destination</span><button type="button" id="use-map-center" class="map-center-button">Use map centre</button><button type="button" id="cancel-map-pick" aria-label="Cancel map selection">×</button></div>
  <div class="map-warning" id="map-warning" role="status" hidden>Map tiles couldn’t load. Check your connection; you can still search and plan a route.</div>
  <button type="button" id="leave-widget" class="leave-widget" aria-label="When to leave home. Show walking route to the first stop." title="Show walk to your first stop" hidden><span id="leave-label" class="leave-label">LEAVE HOME</span><strong id="leave-title">—</strong><span id="leave-countdown" aria-live="off"></span><span id="boarding-eta" class="boarding-eta" hidden></span></button>
  <div id="map-toast" class="map-toast" role="status" hidden></div>
  <aside id="station-guidance" class="station-guidance" aria-labelledby="station-guidance-title" tabindex="-1" hidden><div class="guidance-card-heading"><span>STATION GUIDANCE</span><button type="button" id="exit-guidance" aria-label="Back to journey">×</button></div><h2 id="station-guidance-title">Your transfer</h2><button type="button" id="guidance-target-name" title="Show this boarding stop"></button><p id="guidance-detail" role="status"></p><div class="guidance-actions"><button type="button" id="confirm-guidance">I can see this stop</button><button type="button" id="guidance-timetable" class="text-button">Timetable</button></div><details id="station-map-details" class="station-map-details"><summary>Map details <span id="station-map-state"></span></summary><div id="station-map-source"></div></details></aside>
  <aside id="station-browse" class="station-browse-card" aria-labelledby="station-browse-name" hidden><div class="station-browse-heading"><div><span id="station-browse-state">STATION MAP</span><strong id="station-browse-name"></strong></div><button type="button" id="station-browse-toggle" aria-pressed="true">Show routes</button></div><details id="station-browse-details"><summary>Map details</summary><div id="station-browse-source"></div></details></aside>

  <aside class="network-card" aria-label="Network activity">
    <div class="network-pulse" aria-hidden="true"><span></span></div>
    <div><strong id="network-count">Finding services…</strong><span id="network-detail">In this map area</span></div>
    <span class="estimate-label" id="estimate-label">TIMETABLE</span>
  </aside>
  <div class="map-controls"><button type="button" id="map-locate" title="Show my location" aria-label="Show my location"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3"/></svg></button><button type="button" id="map-phones" aria-label="Show public phones" title="Mapped payphone locations" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 3 3 5-3 3c1 3 2 4 5 5l3-3 5 3-2 5C10 22 2 14 3 5l5-2Z"/></svg><span id="phone-count" class="phone-count" hidden></span></button><button type="button" id="map-zoom-in" aria-label="Zoom in">+</button><button type="button" id="map-zoom-out" aria-label="Zoom out">−</button><span id="gps-status" class="gps-status" role="status" hidden></span></div>
  <button type="button" id="follow-journey" class="follow-control" aria-pressed="true" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 11 16-7-7 16-2-7-7-2Z"/></svg><span>Following journey</span></button>
  <div class="map-legend"><span><i class="legend-next"></i>Your next ride</span><span><i class="legend-other"></i>Other services</span><span class="legend-note" id="legend-note">Positions estimated from timetable</span><span class="route-cache-status" id="route-cache-status" role="status">Loading network lines…</span></div>
  <dialog id="timetable-dialog" class="timetable-dialog" aria-labelledby="timetable-title">
    <div class="timetable-header"><div><div class="eyebrow">YOUR NETWORK, ALL HERE</div><h2 id="timetable-title">Timetable</h2></div><button type="button" id="timetable-close" class="dialog-close" aria-label="Close timetable"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button></div>
    <button type="button" id="timetable-back" class="timetable-back" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 6-6 6 6 6M8 12h12"/></svg>Back</button>
    <p id="timetable-description" class="timetable-description">Scheduled times in Perth, Western Australia.</p>
    <div id="timetable-content" class="timetable-content" aria-live="polite"></div>
    <p class="timetable-disclaimer">Timetable estimates, not live vehicle tracking. All times are shown in Perth time.</p>
  </dialog>
  <noscript><div class="noscript">Enable JavaScript to use the map and plan a journey.</div></noscript>
  <dialog id="day-planner" class="day-planner" aria-labelledby="day-planner-title">
    <header class="planner-heading"><div><span class="planner-eyebrow">MAKE TIME FOR YOUR DAY</span><h2 id="day-planner-title">A plan that gets you home.</h2></div><button type="button" id="close-day-planner" class="planner-close" aria-label="Close day planner">×</button></header>
    <nav class="planner-tabs" aria-label="Day planner sections"><button type="button" data-planner-tab="day" aria-pressed="true">My day</button><button type="button" data-planner-tab="places" aria-pressed="false">Saved places</button><button type="button" data-planner-tab="calendar" aria-pressed="false">Google Calendar</button></nav>
    <p id="planner-message" class="planner-message" role="status" hidden></p>
    <section id="planner-day" class="planner-section">
      <div class="planner-row"><label>Start from<select id="planner-origin"></select></label><label>Leave no earlier than <span>(Perth time)</span><input type="datetime-local" id="planner-start" required></label></div>
      <h3>Where are you heading?</h3><div id="planner-place-chips" class="planner-place-chips"></div><p id="planner-empty-places" class="planner-help">Save Home, Gym or Shops to build your day.</p>
      <ol id="planner-stops" class="planner-stops"></ol>
      <label class="planner-check"><input type="checkbox" id="planner-return" checked><span id="planner-return-label">Return home after my last stop</span></label>
      <button type="button" id="planner-build" class="planner-primary">Plan my day</button>
      <div id="planner-results" class="planner-results" hidden></div>
      <div id="planner-reminder-actions" class="planner-actions" hidden><button type="button" id="planner-download">Download phone calendar reminders</button><button type="button" id="planner-google-preview">Add travel to Google Calendar</button><button type="button" id="planner-alerts">Enable alerts while this page is open</button></div>
      <p class="planner-help">Trips use the timetable. Phone reminders depend on calendar sync and notification settings.</p>
    </section>
    <section id="planner-places" class="planner-section" hidden>
      <h3>Your regular places</h3><p class="planner-help">Saved on this device. Add the entrance you normally use.</p><div id="planner-saved-list"></div>
      <form id="planner-place-form"><label>Place name<input id="saved-place-name" maxlength="60" placeholder="Home, Gym, Shops…" required></label><label>Address<input id="saved-place-address" maxlength="160" placeholder="Search a Perth address or place" autocomplete="off"></label><div class="planner-actions"><button type="button" id="saved-place-search">Search address</button><button type="button" id="saved-place-map">Use map centre</button></div><div id="saved-place-results" class="planner-search-results"></div><p id="saved-place-selected" class="planner-help"></p><button type="submit" id="saved-place-save" class="planner-primary" disabled>Save place</button></form>
    </section>
    <section id="planner-calendar" class="planner-section" hidden>
      <h3>Your events, your choice</h3><p class="planner-help">Select an event to plan its travel. Nothing is planned or added automatically.</p><p id="calendar-state" role="status"></p><div class="planner-actions"><button type="button" id="calendar-connect">Connect Google Calendar</button><button type="button" id="calendar-disconnect" hidden>Disconnect</button></div>
      <div id="calendar-browser" hidden><label>Calendar day <span>(Perth time)</span><input type="date" id="calendar-date"></label><button type="button" id="calendar-load">Load events</button><div id="calendar-events"></div></div>
      <div id="calendar-event-choice" hidden><h3 id="calendar-event-title"></h3><p id="calendar-event-time" class="planner-help"></p><label>Event entrance / address<input id="calendar-event-address" maxlength="160" autocomplete="off"></label><button type="button" id="calendar-event-search">Find event location</button><div id="calendar-event-results" class="planner-search-results"></div><p id="calendar-event-place" class="planner-help"></p><label class="planner-check"><input id="calendar-event-consent" type="checkbox"> Plan public transport for this selected event</label><button type="button" id="calendar-event-add" class="planner-primary" disabled>Add this event to my day</button></div>
    </section>
    <section id="planner-reminder-preview" class="planner-section" hidden><h3>Review your travel reminders</h3><p class="planner-help">These travel blocks go into a separate Nextstop Trips calendar. Your appointments stay as they are. Reminders are set for ten minutes before leaving and at departure.</p><div id="planner-reminder-list"></div><label class="planner-check"><input type="checkbox" id="planner-reminder-consent"> I agree to add these selected travel reminders to Google Calendar</label><button type="button" id="planner-reminder-save" class="planner-primary" disabled>Add confirmed reminders</button><button type="button" id="planner-reminder-back">Back to my day</button></section>
  </dialog>
  <dialog id="planner-stay" class="planner-stay" aria-labelledby="planner-stay-title"><form id="planner-stay-form"><h2 id="planner-stay-title">How long will you stay?</h2><label>Stay for <span>(minutes)</span><input id="planner-stay-minutes" type="number" min="5" max="720" step="5" value="60" required></label><div class="planner-actions"><button type="button" data-stay="30">30 min</button><button type="button" data-stay="60">1 hour</button><button type="button" data-stay="120">2 hours</button></div><div class="planner-actions"><button type="button" id="planner-stay-cancel">Cancel</button><button type="submit" class="planner-primary">Add to my day</button></div></form></dialog>
</body>
</html>
