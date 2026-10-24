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
  <script src="assets/vendor/leaflet/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" defer></script>
  <script src="<?= assetUrl('assets/app.js') ?>" defer></script>
</head>
<body class="mascot-visible">
  <div id="map" aria-label="Interactive Perth public transport map"></div>

  <header class="topbar">
    <a class="brand" href="./" aria-label="Nextstop home"><img class="brand-cat" src="<?= assetUrl(is_file(__DIR__ . '/assets/mascot-head.webp') ? 'assets/mascot-head.webp' : 'assets/black-cat.svg') ?>" alt="" width="46" height="46"><span>nextstop<span class="brand-dot">.</span></span></a>
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
      <div class="results-heading"><h2>Your journey</h2><button type="button" id="clear-journey" class="text-button">Clear <span aria-hidden="true">×</span></button></div>
      <div id="journey-options" class="journey-options"></div>
      <div id="next-service" class="next-service" hidden></div>
      <div id="itinerary" class="itinerary"></div>
      <button type="button" id="network-toggle" class="network-toggle" aria-pressed="false"><span class="toggle-track"><span></span></span><span>Show the whole network</span></button>
    </div>

    <div class="panel-footer"><details class="about-details"><summary>About &amp; data sources</summary><p class="timetable-attribution"><span class="data-source-label">Data source</span>Timetable data available free of charge from <a href="https://www.transperth.wa.gov.au/About/Spatial-Data-Access" target="_blank" rel="noopener" aria-label="Transperth timetable data source website">www.transperth.wa.gov.au</a></p><p class="about-note">Vehicle positions follow the timetable, rather than live GPS.</p><p id="phone-source-note" class="about-note">Mapped phone locations show their published source. Coverage and current availability can vary.</p></details></div>
  </main>

  <div class="map-hint" id="map-hint" hidden><span id="map-hint-text">Tap the map to choose your destination</span><button type="button" id="use-map-center" class="map-center-button">Use map centre</button><button type="button" id="cancel-map-pick" aria-label="Cancel map selection">×</button></div>
  <div class="map-warning" id="map-warning" role="status" hidden>Map tiles couldn’t load. Check your connection; you can still search and plan a route.</div>
  <aside id="leave-widget" class="leave-widget" aria-label="When to leave home" hidden><span id="leave-label" class="leave-label">LEAVE HOME</span><strong id="leave-title">—</strong><span id="leave-countdown" aria-live="off"></span><span id="boarding-eta" class="boarding-eta" hidden></span></aside>
  <div id="map-toast" class="map-toast" role="status" hidden></div>
  <aside id="station-guidance" class="station-guidance" aria-labelledby="station-guidance-title" tabindex="-1" hidden><div class="guidance-card-heading"><span>STATION GUIDANCE</span><button type="button" id="exit-guidance" aria-label="Back to journey">×</button></div><h2 id="station-guidance-title">Your transfer</h2><button type="button" id="guidance-target-name" title="Show this boarding stop"></button><p id="guidance-detail" role="status"></p><div class="guidance-actions"><button type="button" id="confirm-guidance">I can see this stop</button><button type="button" id="guidance-timetable" class="text-button">Timetable</button></div><details id="station-map-details" class="station-map-details"><summary>Map details <span id="station-map-state"></span></summary><div id="station-map-source"></div></details></aside>

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
</body>
</html>
