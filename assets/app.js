/* Nextstop: open maps, Perth address search, and clearly labelled timetable estimates. */
(() => {
  'use strict';

  const $ = id => document.getElementById(id);
  const make = (tag, className, text) => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  };
  const icon = mode => {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    const paths = {
      walk: 'M13 4a1 1 0 1 0 0 .1M9 21l3-8 3 4v4M5 12l4-5h4l3 5h3M12 8l-1 5-4 3',
      bus: 'M6 18V6c0-2 12-2 12 0v12ZM6 12h12M9 7v5M6 8h12M8 15h1m6 0h1M8 18v2m8-2v2',
      train: 'M6 16V6c0-3 12-3 12 0v10c0 2-12 2-12 0ZM6 6h12M6 12h12M8 15h1m6 0h1M9 18l-3 4m9-4 3 4M8 20h8',
      ferry: 'M3 13l9-3 9 3-4 6H7ZM7 11V6h10v5M10 6V3h4v3M2 21l3-1 4 1 3-1 3 1 4-1 3 1',
      arrow: 'M5 19 19 5M6 5h13v13',
      destination: 'M7 7h10v10H7Z'
    };
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', paths[mode] || paths.arrow);
    svg.append(path);
    return svg;
  };
  const state = {
    origin: { lat: -31.9505, lon: 115.8586, name: 'Perth Busport' },
    destination: null, field: 'destination', pickField: null,
    journeys: [], selected: null, wholeNetwork: false, status: null,
    vehicles: [], markers: new Map(), placeMarkers: {},
    requestNumber: 0, searchNumber: 0, vehicleController: null,
    nextTrip: null, planNumber: 0, searchTimer: null, searchController: null, needsJourneyFit: false,
    panelOpen: false, timetableController: null, timetableHistory: [], timetableNumber: 0, timetableFocus: null,
    routeCatalog: null, routeRevision: '', routeEtag: '', routeRefresh: null, routeDb: null,
    renderedNetworkKey: '', fallbackLines: [], routesEpoch: 0, routeStored: false, routeRetryTimer: null, routeRetryCount: 0,
    followJourney: true, programmaticView: false, lastFollowFit: null, viewTimer: null,
    userLocation: null, locationConsent: false, locationWatch: null, gpsMarker: null, accuracyCircle: null,
    zooming: false, fitAfterZoom: false
  };
  const perthTime = new Intl.DateTimeFormat('en-AU', { timeZone: 'Australia/Perth', hour: 'numeric', minute: '2-digit', hour12: true });
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let map, journeyLayers, networkLayers, stopLayers, moveTimer;

  function message(text, error = false) {
    $('message').textContent = text || '';
    $('message').classList.toggle('is-error', error);
    $('message').hidden = !text;
  }

  function timeValue(value) {
    if (typeof value === 'number') return value < 1e12 ? value * 1000 : value;
    const parsed = Date.parse(value);
    return Number.isFinite(parsed) ? parsed : NaN;
  }

  function clock(value) {
    const parsed = timeValue(value);
    return Number.isFinite(parsed) ? perthTime.format(parsed) : '—';
  }

  function minutes(seconds) {
    return Math.max(1, Math.round(Number(seconds) / 60) || 1);
  }

  function serviceLabel(vehicle) {
    const name = String(vehicle.routeName || '·');
    if (name.length <= 5) return name;
    return name.replace(/\b(line|route)\b/gi, '').trim().split(/\s+/).map(part => part[0]).join('').slice(0, 5) || name.slice(0, 5);
  }

  const isMobile = () => window.matchMedia('(max-width: 760px)').matches;

  function routeColor(service) {
    const value = String(service.routeColor || '').replace(/^#/, '');
    return /^[0-9a-f]{6}$/i.test(value) ? `#${value}` : service.mode === 'train' ? '#1675d1' : service.mode === 'ferry' ? '#009db9' : '#159558';
  }

  function routeTextColor(service) {
    const value = String(service.routeTextColor || '').replace(/^#/, '');
    if (/^[0-9a-f]{6}$/i.test(value)) return `#${value}`;
    const color = routeColor(service).slice(1);
    const brightness = parseInt(color.slice(0, 2), 16) * .299 + parseInt(color.slice(2, 4), 16) * .587 + parseInt(color.slice(4, 6), 16) * .114;
    return brightness > 160 ? '#14201d' : '#ffffff';
  }

  function styleRoute(element, service) {
    element.style.setProperty('--route-color', routeColor(service));
    element.style.setProperty('--route-text', routeTextColor(service));
    return element;
  }

  function updateLauncher() {
    if (state.selected) {
      const next = nextLeg();
      const duration = Number(state.selected.duration) || (timeValue(state.selected.arrival) - timeValue(state.selected.departure)) / 1000;
      $('launcher-title').textContent = next ? `${next.routeName} · your next ${next.mode}` : 'Your walking journey';
      $('launcher-detail').textContent = `${minutes(duration)} min · view route and timetable`;
    } else {
      $('launcher-title').textContent = state.destination ? state.destination.name : 'Where are you heading?';
      $('launcher-detail').textContent = state.destination ? 'Destination set · tap to find your next ride' : 'Search, choose a stop, or plan a journey';
    }
  }

  function setPanel(open, focus = false) {
    state.panelOpen = Boolean(open);
    document.body.classList.toggle('panel-open', state.panelOpen);
    const hidden = isMobile() && !state.panelOpen;
    $('journey-panel').inert = hidden;
    $('journey-panel').setAttribute('aria-hidden', String(hidden));
    $('open-panel').setAttribute('aria-expanded', String(!hidden));
    updateLauncher();
    if (focus && !hidden) $('journey-panel').focus({ preventScroll: true });
    if (hidden && $('journey-panel').contains(document.activeElement)) document.activeElement.blur();
    if (map && state.selected && isMobile() && state.followJourney) requestAnimationFrame(() => fitJourneyBounds(state.selected));
  }

  function timetableButton(label, query, className = '') {
    const button = make('button', `timetable-button ${className}`.trim(), label);
    button.type = 'button';
    button.addEventListener('click', () => openTimetable(query));
    return button;
  }

  function tripQuery(service) {
    const query = { trip: service.tripId || service.id };
    if (/^\d{8}$/.test(String(service.serviceDate || ''))) query.date = service.serviceDate;
    return query;
  }

  function openRouteDatabase() {
    if (!('indexedDB' in window)) return Promise.resolve(null);
    return new Promise(resolve => {
      let settled = false;
      const done = value => { if (!settled) { settled = true; resolve(value); } };
      let request;
      try { request = indexedDB.open('nextstop-public-network', 1); } catch { done(null); return; }
      request.onupgradeneeded = () => { if (!request.result.objectStoreNames.contains('routes')) request.result.createObjectStore('routes'); };
      request.onsuccess = () => done(request.result);
      request.onerror = () => done(null);
      request.onblocked = () => done(null);
      setTimeout(() => done(null), 2500);
    });
  }

  async function readRouteCache() {
    state.routeDb = await openRouteDatabase();
    if (!state.routeDb) return null;
    return new Promise(resolve => {
      try {
        const transaction = state.routeDb.transaction('routes', 'readonly');
        const request = transaction.objectStore('routes').get('perth');
        request.onsuccess = () => resolve(request.result || null);
        request.onerror = () => resolve(null);
      } catch { resolve(null); }
    });
  }

  function saveRouteCache(record) {
    if (!state.routeDb) return;
    try {
      const transaction = state.routeDb.transaction('routes', 'readwrite');
      transaction.objectStore('routes').put(record, 'perth');
      transaction.oncomplete = () => { state.routeStored = true; $('route-cache-status').textContent = 'Network routes ready · saved on this device'; };
      transaction.onabort = () => { $('route-cache-status').textContent = 'Network lines in memory · browser storage unavailable'; };
      transaction.onerror = () => {}; // A full or private browser still uses the in-memory catalogue.
    } catch { /* IndexedDB is optional; public geometry remains available for this session. */ }
  }

  function acceptRouteCatalog(record, cached = false) {
    if (!record || !Array.isArray(record.lines) || !record.lines.length) return false;
    state.routeCatalog = record.lines;
    state.routeRevision = String(record.revision || record.generatedAt || '');
    state.routeEtag = record.etag || '';
    state.routeStored = cached;
    clearTimeout(state.routeRetryTimer);
    state.routeRetryCount = 0;
    state.routesEpoch++;
    $('route-cache-status').textContent = cached ? 'Saved network routes · checking for updates' : 'Network routes ready · kept in memory';
    $('route-cache-status').title = 'Public route geometry only. Vehicle positions, journeys, and map tiles are not stored in this cache.';
    drawNetworkLines();
    return true;
  }

  async function refreshRouteCatalog() {
    if (state.routeRefresh) return state.routeRefresh;
    state.routeRefresh = (async () => {
      try {
        const url = new URL('api.php', window.location.href);
        url.searchParams.set('action', 'routes');
        const headers = { Accept: 'application/json' };
        if (state.routeEtag) headers['If-None-Match'] = state.routeEtag;
        const response = await fetch(url, { headers, cache: 'no-cache' });
        if (response.status === 304 && state.routeCatalog) {
          $('route-cache-status').textContent = state.routeStored ? 'Network routes ready · saved on this device' : 'Network routes ready · kept in memory';
          return;
        }
        if (!response.ok) throw new Error('Network line download unavailable');
        const record = await response.json();
        if (record.error) throw new Error(record.error);
        record.etag = response.headers.get('ETag') || '';
        if (record.revision && String(record.revision) === state.routeRevision && state.routeCatalog) {
          state.routeEtag = record.etag;
          $('route-cache-status').textContent = state.routeStored ? 'Network routes ready · saved on this device' : 'Network routes ready · kept in memory';
          return;
        }
        if (!acceptRouteCatalog(record)) throw new Error('No network lines are available yet');
        saveRouteCache(record);
      } catch {
        $('route-cache-status').textContent = state.routeCatalog ? 'Saved network routes · update unavailable' : 'Network routes unavailable · vehicles still update';
        if (!state.routeCatalog && !document.hidden && state.routeRetryCount < 4) {
          clearTimeout(state.routeRetryTimer);
          const delay = 3000 * (2 ** state.routeRetryCount++);
          $('route-cache-status').textContent = 'Preparing network routes · retrying shortly';
          state.routeRetryTimer = setTimeout(() => { if (!state.routeCatalog && !document.hidden) refreshRouteCatalog(); }, delay);
        }
      } finally { state.routeRefresh = null; }
    })();
    return state.routeRefresh;
  }

  async function loadRouteCatalog() {
    const cached = await readRouteCache();
    if (cached) acceptRouteCatalog(cached, true);
    await refreshRouteCatalog();
  }

  function drawNetworkLines() {
    if (!map || !networkLayers) return;
    if (state.selected && !state.wholeNetwork) {
      if (state.renderedNetworkKey !== 'focused') networkLayers.clearLayers();
      state.renderedNetworkKey = 'focused';
      $('map').dataset.networkLines = '0';
      return;
    }
    const catalog = state.routeCatalog || state.fallbackLines;
    if (!catalog.length) return;
    const bounds = map.getBounds().pad(.15);
    const visible = [];
    catalog.forEach((line, index) => {
      const bbox = line.bbox;
      if (Array.isArray(bbox) && bbox.length === 4 && (bbox[2] < bounds.getWest() || bbox[0] > bounds.getEast() || bbox[3] < bounds.getSouth() || bbox[1] > bounds.getNorth())) return;
      if (Array.isArray(line.geometry) && line.geometry.length > 1) visible.push([line, index]);
    });
    const key = `${state.routesEpoch}:${visible.map(([, index]) => index).join(',')}`;
    if (key === state.renderedNetworkKey) return;
    state.renderedNetworkKey = key;
    $('map').dataset.networkLines = String(visible.length);
    $('map').dataset.routeRevision = state.routeRevision;
    networkLayers.clearLayers();
    visible.forEach(([line]) => {
      const weight = line.mode === 'train' ? 4.5 : 3.5;
      L.polyline(line.geometry, { color: '#ffffff', weight: weight + 3, opacity: .9, interactive: false }).addTo(networkLayers);
      L.polyline(line.geometry, { color: routeColor(line), weight, opacity: .9, interactive: false }).addTo(networkLayers);
    });
    journeyLayers.eachLayer(layer => { if (layer.bringToFront) layer.bringToFront(); });
  }

  function openTimetable(query, addHistory = true) {
    const dialog = $('timetable-dialog');
    if (!dialog.open) {
      state.timetableFocus = document.activeElement;
      state.timetableHistory = [];
      dialog.showModal();
    }
    if (addHistory) state.timetableHistory.push(query);
    $('timetable-back').hidden = state.timetableHistory.length < 2;
    loadTimetable(query);
  }

  async function loadTimetable(query) {
    if (state.timetableController) state.timetableController.abort();
    state.timetableController = new AbortController();
    const request = ++state.timetableNumber;
    $('timetable-title').textContent = query.trip ? 'Service timetable' : 'Stop timetable';
    $('timetable-description').textContent = 'Finding the scheduled times…';
    $('timetable-content').replaceChildren(make('p', 'timetable-empty', 'Loading timetable…'));
    try {
      const data = await api('timetable', query, state.timetableController.signal);
      if (request !== state.timetableNumber) return;
      const container = $('timetable-content');
      container.replaceChildren();
      const tripView = Boolean(data.trip);
      $('timetable-title').textContent = tripView ? `${data.trip.routeName} · ${data.trip.headsign || 'Service timetable'}` : data.stop ? data.stop.name : 'Stop timetable';
      const serviceDate = String(data.serviceDate || (data.trip && data.trip.serviceDate) || '');
      const when = /^\d{8}$/.test(serviceDate) ? timeValue(`${serviceDate.slice(0, 4)}-${serviceDate.slice(4, 6)}-${serviceDate.slice(6, 8)}T12:00:00+08:00`) : timeValue(data.generatedAt);
      const day = Number.isFinite(when) ? new Intl.DateTimeFormat('en-AU', { timeZone: 'Australia/Perth', weekday: 'long', day: 'numeric', month: 'short' }).format(when) : 'Today';
      $('timetable-description').textContent = `${data.mode === 'demo' ? 'Illustrative demo · ' : ''}${day} · ${tripView ? 'Stop-by-stop schedule' : 'Upcoming departures'} · Perth time`;
      if (data.message) container.append(make('p', 'timetable-note', data.message));
      if (tripView && Array.isArray(data.stops)) {
        const summary = styleRoute(make('div', 'timetable-trip-summary'), data.trip);
        const route = make('span', 'route-chip', data.trip.routeName);
        summary.append(route, make('span', '', data.trip.headsign || 'Scheduled service'));
        container.append(summary);
        const list = make('ol', 'timetable-stop-list');
        data.stops.forEach(stop => {
          const row = make('li', 'timetable-stop-row');
          if (timeValue(stop.departure || stop.arrival) < Date.now()) row.classList.add('is-past');
          const stopButton = timetableButton(stop.name, { stop: stop.id }, 'stop-timetable-link');
          const times = make('span', 'timetable-stop-time', clock(stop.departure || stop.arrival));
          if (stop.arrival && stop.departure && timeValue(stop.arrival) !== timeValue(stop.departure)) times.append(make('small', '', `arr. ${clock(stop.arrival)}`));
          row.append(stopButton, times);
          list.append(row);
        });
        container.append(list);
        if (!data.stops.length) container.append(make('p', 'timetable-empty', 'No stop times are available for this service.'));
      } else {
        const departures = Array.isArray(data.departures) ? data.departures : [];
        if (!departures.length) container.append(make('p', 'timetable-empty', 'No scheduled departures in the next six hours. Try another nearby stop.'));
        departures.forEach(departure => {
          const button = styleRoute(make('button', 'departure-row'), departure);
          button.type = 'button';
          button.setAttribute('aria-label', `${departure.routeName} towards ${departure.headsign || 'destination'}, ${clock(departure.departure)}. View all stops.`);
          const route = make('span', 'route-chip', serviceLabel(departure));
          route.title = departure.routeName;
          const description = make('span', 'departure-description');
          description.append(make('strong', '', departure.headsign || departure.routeName), make('small', '', `${departure.mode || 'bus'} · view all stops`));
          const time = make('span', 'departure-time', clock(departure.departure));
          const wait = Math.ceil((timeValue(departure.departure) - Date.now()) / 60000);
          time.append(make('small', '', wait > 0 ? `in ${wait} min` : 'scheduled now'));
          button.append(route, description, time);
          if (Number(departure.pickupType) === 2 || Number(departure.pickupType) === 3) description.append(make('small', 'pickup-note', 'Booking or driver arrangement may be required'));
          button.addEventListener('click', () => openTimetable(tripQuery(departure)));
          container.append(button);
        });
      }
    } catch (error) {
      if (error.name === 'AbortError' || request !== state.timetableNumber) return;
      $('timetable-description').textContent = 'The timetable could not load.';
      const retry = make('button', 'timetable-button', 'Try again');
      retry.type = 'button';
      retry.addEventListener('click', () => loadTimetable(query));
      $('timetable-content').replaceChildren(make('p', 'timetable-empty', error.message), retry);
    }
  }

  function transitLegs(journey = state.selected) {
    return journey ? (journey.legs || []).filter(leg => leg.mode !== 'walk') : [];
  }

  function nextLeg() {
    const now = Date.now();
    return transitLegs().find(leg => !Number.isFinite(timeValue(leg.arrival)) || timeValue(leg.arrival) >= now) || null;
  }

  async function api(action, params = {}, signal) {
    const url = new URL('api.php', window.location.href);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
    const response = await fetch(url, { signal, headers: { Accept: 'application/json' } });
    let data;
    try { data = await response.json(); } catch { throw new Error('The server returned an unreadable response. Please try again.'); }
    if (!response.ok || data.error) throw new Error(data.error || `The request failed (${response.status}).`);
    return data;
  }

  function updateClock() {
    $('perth-clock').textContent = perthTime.format(new Date());
  }

  async function loadStatus() {
    try {
      const status = await api('status');
      state.status = status;
      const demo = status.mode === 'demo';
      $('data-banner').classList.toggle('is-demo', demo);
      $('data-banner-text').textContent = demo ? 'Demo network · illustrative journeys and vehicles' : 'Timetable estimates · vehicle locations are not live GPS';
      $('estimate-label').textContent = demo ? 'DEMO' : 'TIMETABLE';
      $('legend-note').textContent = demo ? 'Illustrative demo positions' : 'Positions estimated from timetable';
      $('data-banner').title = status.message || '';
      if (status.routeRevision && state.routeCatalog && String(status.routeRevision) !== state.routeRevision) refreshRouteCatalog();
      updateNextService();
    } catch (error) {
      $('data-banner-text').textContent = 'Network status unavailable · retrying shortly';
      $('data-banner').classList.add('is-demo');
    }
  }

  function placeIcon(name, destination) {
    const container = make('div');
    container.append(make('div', `place-dot${destination ? ' destination' : ''}`), make('span', 'place-label', name));
    return L.divIcon({ html: container, className: 'place-marker', iconSize: [20, 20], iconAnchor: [10, 10] });
  }

  function updatePlaceMarker(field) {
    const place = state[field];
    if (state.placeMarkers[field]) map.removeLayer(state.placeMarkers[field]);
    if (!place) return;
    state.placeMarkers[field] = L.marker([place.lat, place.lon], {
      icon: placeIcon(place.name, field === 'destination'), zIndexOffset: 900,
      title: `${field === 'origin' ? 'Starting point' : 'Destination'}: ${place.name}`,
      draggable: true, bubblingMouseEvents: false
    }).addTo(map);
    state.placeMarkers[field].on('dragend', event => {
      const point = event.target.getLatLng();
      setPlace(field, { lat: point.lat, lon: point.lng, name: field === 'origin' ? 'Starting point on map' : 'Destination on map' });
    });
  }

  function setPlace(field, place, pan = false) {
    clearTimeout(state.searchTimer);
    state.searchNumber++;
    if (state.searchController) state.searchController.abort();
    state[field] = { lat: Number(place.lat), lon: Number(place.lon), name: String(place.name) };
    $(`${field}-input`).value = state[field].name;
    clearJourney(false);
    updatePlaceMarker(field);
    $('search-results').hidden = true;
    setMapPick(null);
    if (pan) map.panTo([place.lat, place.lon]);
    if (state.origin && state.destination) message('Ready when you are. Find a route to see your next ride.');
    updateLauncher();
  }

  function setMapPick(field) {
    state.pickField = field;
    $('map-hint').hidden = !field;
    $('map').classList.toggle('map-picking', Boolean(field));
    if (field) $('map-hint-text').textContent = `Tap the map to choose your ${field === 'origin' ? 'starting point' : 'destination'}`;
  }

  function updateUserLocation(position) {
    const point = { lat: position.coords.latitude, lon: position.coords.longitude, accuracy: position.coords.accuracy, timestamp: position.timestamp };
    state.userLocation = point;
    const latlng = [point.lat, point.lon];
    if (!state.gpsMarker) {
      state.gpsMarker = L.marker(latlng, { icon: L.divIcon({ className: 'gps-marker', iconSize: [18, 18], iconAnchor: [9, 9] }), zIndexOffset: 1500, bubblingMouseEvents: false, title: 'Your phone location' }).addTo(map);
      state.gpsMarker.bindPopup(make('div', 'gps-popup', 'Your phone location'));
    } else state.gpsMarker.setLatLng(latlng);
    if (state.accuracyCircle) map.removeLayer(state.accuracyCircle);
    if (Number.isFinite(point.accuracy) && point.accuracy < 2000) state.accuracyCircle = L.circle(latlng, { radius: point.accuracy, color: '#4689d0', weight: 1, opacity: .2, fillOpacity: .05, interactive: false }).addTo(map);
    state.gpsMarker.setPopupContent(make('div', 'gps-popup', `Your phone location · accuracy about ${Math.round(point.accuracy)} m`));
    if (state.selected && state.followJourney) fitJourneyBounds(state.selected, false);
  }

  function stopLocationWatch() {
    if (state.locationWatch !== null && navigator.geolocation) navigator.geolocation.clearWatch(state.locationWatch);
    state.locationWatch = null;
  }

  function startLocationWatch() {
    if (!state.locationConsent || !state.selected || document.hidden || state.locationWatch !== null || !navigator.geolocation) return;
    state.locationWatch = navigator.geolocation.watchPosition(updateUserLocation, error => {
      if (error.code === 1) { state.locationConsent = false; stopLocationWatch(); }
      // A watch remains useful after a temporary timeout or unavailable GPS fix.
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 5000 });
  }

  function setFollow(enabled) {
    state.followJourney = Boolean(enabled);
    if (!state.followJourney) state.fitAfterZoom = false;
    $('follow-journey').setAttribute('aria-pressed', String(state.followJourney));
    $('follow-journey').querySelector('span').textContent = state.followJourney ? 'Following journey' : 'Recenter journey';
    if (state.followJourney && state.selected) { state.lastFollowFit = null; fitJourneyBounds(state.selected); }
  }

  async function locate() {
    if (!navigator.geolocation) {
      message('This browser does not support location. Search for a starting address or choose it on the map.', true);
      return;
    }
    if (!window.isSecureContext) {
      message('Phone location needs HTTPS. You can still search for a starting address or choose it on the map.', true);
      return;
    }
    $('use-location').disabled = true;
    message('Finding your location…');
    navigator.geolocation.getCurrentPosition(position => {
      $('use-location').disabled = false;
      const { latitude: lat, longitude: lon } = position.coords;
      if (lat < -33.6 || lat > -30.6 || lon < 114.8 || lon > 117.2) {
        message('Your location is outside the Perth service area. Choose a Perth starting point to plan a journey.', true);
        return;
      }
      state.locationConsent = true;
      updateUserLocation(position);
      if (state.selected) { startLocationWatch(); setFollow(true); }
      else {
        setPlace('origin', { lat, lon, name: 'Your location' }, true);
        map.setZoom(15);
        message(state.destination ? 'Starting from your location. Find your next ride below.' : 'Starting from your location. Where would you like to go?');
      }
    }, error => {
      $('use-location').disabled = false;
      message(error.code === 1 ? 'Location permission was declined. Search for an address or choose your starting point on the map.' : 'Could not get your location. Search for an address or choose your starting point on the map.', true);
    }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
  }

  async function search(field, automatic = false) {
    clearTimeout(state.searchTimer);
    const query = $(`${field}-input`).value.trim();
    if (query.length < 3) {
      if (!automatic) message('Enter at least three characters, or choose a point on the map.', true);
      return;
    }
    if (state.searchController) state.searchController.abort();
    state.searchController = new AbortController();
    const request = ++state.searchNumber;
    const results = $('search-results');
    results.replaceChildren(make('div', 'search-note', 'Searching Perth addresses…'));
    results.hidden = false;
    try {
      const data = await api('geocode', { q: query }, state.searchController.signal);
      if (request !== state.searchNumber) return;
      results.replaceChildren();
      if (!data.results || data.results.length === 0) {
        results.append(make('div', 'search-note', 'No matching Perth addresses. Try a suburb, landmark, or choose a point on the map.'));
        return;
      }
      data.results.forEach(place => {
        const button = make('button', 'search-result');
        button.type = 'button';
        button.append(icon('arrow'));
        const text = make('span');
        const parts = String(place.name).split(',');
        text.append(make('span', '', parts.slice(0, 2).join(',')));
        if (parts.length > 2) text.append(make('small', '', parts.slice(2).join(',').trim()));
        button.append(text);
        button.addEventListener('click', () => {
          setPlace(field, place, true);
          message(field === 'destination' ? 'Destination set. Find your next ride below.' : 'Starting point updated.');
        });
        results.append(button);
      });
      results.append(make('div', 'search-note', 'Address search by Photon · © OpenStreetMap contributors.'));
    } catch (error) {
      if (error.name === 'AbortError') return;
      if (request !== state.searchNumber) return;
      results.replaceChildren(make('div', 'search-note', error.message));
    }
  }

  async function plan() {
    if (!state.origin) { await search('origin'); return; }
    if (!state.destination) {
      if ($('destination-input').value.trim()) await search('destination');
      else { setMapPick('destination'); message('Search for a destination above, or tap the map.'); }
      return;
    }
    const request = ++state.planNumber;
    $('plan-button').disabled = true;
    $('plan-button').firstElementChild.textContent = 'Finding your route…';
    message('Comparing departures, walking time, and connections…');
    $('search-results').hidden = true;
    try {
      const data = await api('plan', { from: `${state.origin.lat},${state.origin.lon}`, to: `${state.destination.lat},${state.destination.lon}` });
      if (request !== state.planNumber) return;
      if (!data.journeys || data.journeys.length === 0) {
        clearJourney(false);
        message(data.message || 'No upcoming journey was found. Try a closer stop or another destination.', true);
        return;
      }
      state.journeys = data.journeys;
      renderJourneyOptions();
      $('journey-results').hidden = false;
      document.querySelector('.journey-panel').classList.add('has-journey');
      selectJourney(data.journeys[0]);
      message(data.mode === 'demo' ? 'Illustrative demo route. Import Perth timetables before using this for travel.' : (data.message || 'Your route is ready. All times and positions are timetable estimates.'));
      if (isMobile()) { document.querySelector('.journey-panel').scrollTop = 0; setPanel(false); }
    } catch (error) {
      if (request === state.planNumber) message(error.message, true);
    } finally {
      if (request === state.planNumber) {
        $('plan-button').disabled = false;
        $('plan-button').firstElementChild.textContent = 'Find my next ride';
      }
    }
  }

  function renderJourneyOptions() {
    const container = $('journey-options');
    container.replaceChildren();
    state.journeys.slice(0, 3).forEach((journey, index) => {
      const button = make('button', 'journey-option');
      button.type = 'button';
      button.dataset.journey = String(journey.id);
      button.setAttribute('aria-pressed', 'false');
      const duration = Number(journey.duration) || (timeValue(journey.arrival) - timeValue(journey.departure)) / 1000;
      const time = make('span', 'option-time', String(minutes(duration)));
      time.append(make('small', '', 'min'));
      const legs = transitLegs(journey);
      const label = index === 0 ? 'Quickest arrival' : `${Number(journey.transfers) || 0} ${Number(journey.transfers) === 1 ? 'transfer' : 'transfers'}`;
      button.append(time, make('span', 'option-label', label));
      button.title = `${clock(journey.arrival)} arrival · ${legs.map(leg => leg.routeName).join(' → ') || 'Walking'}`;
      button.addEventListener('click', () => selectJourney(journey));
      container.append(button);
    });
  }

  function selectJourney(journey) {
    state.selected = journey;
    state.followJourney = true;
    state.lastFollowFit = null;
    $('follow-journey').hidden = false;
    $('follow-journey').setAttribute('aria-pressed', 'true');
    $('follow-journey').querySelector('span').textContent = 'Following journey';
    state.wholeNetwork = false;
    $('network-toggle').setAttribute('aria-pressed', 'false');
    document.querySelectorAll('.journey-option').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.journey === String(journey.id))));
    journeyLayers.clearLayers();
    networkLayers.clearLayers();
    state.renderedNetworkKey = 'focused';
    $('map').dataset.networkLines = '0';
    (journey.legs || []).forEach(leg => {
      const fallback = leg.from && leg.to ? [[leg.from.lat, leg.from.lon], [leg.to.lat, leg.to.lon]] : [];
      const geometry = Array.isArray(leg.geometry) && leg.geometry.length ? leg.geometry : fallback;
      if (geometry.length >= 2) {
        const transit = leg.mode !== 'walk';
        L.polyline(geometry, { color: '#fff', weight: transit ? 11 : 6, opacity: .98, interactive: false }).addTo(journeyLayers);
        L.polyline(geometry, { color: transit ? routeColor(leg) : '#63756e', weight: transit ? 6 : 3, opacity: .98, dashArray: transit ? null : '3 7', lineCap: 'round', interactive: false }).addTo(journeyLayers);
      }
    });
    renderItinerary();
    updateNextService();
    updateLauncher();
    state.needsJourneyFit = true;
    fitJourneyBounds(journey);
    startLocationWatch();
    fetchVehicles();
  }

  function remainingGeometry(geometry, point) {
    if (!point || !Array.isArray(geometry) || geometry.length < 2) return geometry || [];
    const scale = Math.cos(point.lat * Math.PI / 180);
    let nearest = { distance: Infinity, index: 0, point: geometry[0] };
    for (let index = 0; index < geometry.length - 1; index++) {
      const a = geometry[index], b = geometry[index + 1];
      const dx = (b[1] - a[1]) * scale, dy = b[0] - a[0];
      const fraction = Math.max(0, Math.min(1, (((point.lon - a[1]) * scale * dx) + ((point.lat - a[0]) * dy)) / (dx * dx + dy * dy || 1)));
      const projected = [a[0] + (b[0] - a[0]) * fraction, a[1] + (b[1] - a[1]) * fraction];
      const distance = Math.hypot((point.lon - projected[1]) * scale, point.lat - projected[0]);
      if (distance < nearest.distance) nearest = { distance, index, point: projected };
    }
    return [nearest.point, ...geometry.slice(nearest.index + 1)];
  }

  function fitJourneyBounds(journey, force = true) {
    if (!state.followJourney || !journey || !map) return;
    if (state.zooming) { state.fitAfterZoom = true; return; }
    const bounds = L.latLngBounds([]);
    const vehicle = state.vehicles.find(item => state.nextTrip && String(item.tripId) === state.nextTrip);
    const now = Date.now();
    (journey.legs || []).forEach(leg => {
      if (Number.isFinite(timeValue(leg.arrival)) && timeValue(leg.arrival) < now) return;
      let geometry = leg.geometry && leg.geometry.length ? leg.geometry : leg.from && leg.to ? [[leg.from.lat, leg.from.lon], [leg.to.lat, leg.to.lon]] : [];
      if (timeValue(leg.departure) <= now) {
        const currentPoint = leg.mode === 'walk' ? state.userLocation : vehicle && String(vehicle.tripId) === String(leg.tripId) ? vehicle : null;
        if (currentPoint) geometry = remainingGeometry(geometry, currentPoint);
      }
      geometry.forEach(point => bounds.extend(point));
    });
    if (vehicle) bounds.extend([vehicle.lat, vehicle.lon]);
    if (state.userLocation) bounds.extend([state.userLocation.lat, state.userLocation.lon]);
    if (state.destination) bounds.extend([state.destination.lat, state.destination.lon]);
    if (bounds.isValid()) {
      const mobile = isMobile();
      const panel = $('journey-panel').getBoundingClientRect();
      const launcher = $('open-panel').getBoundingClientRect();
      const size = map.getSize();
      const topLeft = L.point(mobile ? 35 : panel.right + 24, mobile ? state.panelOpen ? 150 : 225 : 105);
      const bottomRight = L.point(mobile ? 70 : 95, mobile ? state.panelOpen ? size.y - panel.top + 16 : size.y - launcher.top + 20 : 145);
      if (size.x - topLeft.x - bottomRight.x < 50 || size.y - topLeft.y - bottomRight.y < 35) return;
      const desiredZoom = Math.max(map.getMinZoom(), Math.min(16, map.getBoundsZoom(bounds, false, topLeft.add(bottomRight))));
      const previous = state.lastFollowFit;
      const legKey = String(state.nextTrip || 'walking');
      const changedLeg = previous && previous.leg !== legKey;
      const currentZoom = map.getZoom();
      const zoom = force || changedLeg || desiredZoom < currentZoom || desiredZoom >= currentZoom + 2 ? desiredZoom : currentZoom;
      const projectedCenter = map.project(bounds.getSouthWest(), zoom).add(map.project(bounds.getNorthEast(), zoom)).divideBy(2).add(bottomRight.subtract(topLeft).divideBy(2));
      const center = map.unproject(projectedCenter, zoom);
      if (!force && previous && !changedLeg) {
        if (now - previous.at < 8000 && zoom >= currentZoom) return;
        if (map.distance(previous.center, center) < 65 && zoom === currentZoom) return;
      }
      state.lastFollowFit = { center, at: now, leg: legKey, zoom };
      state.programmaticView = true;
      clearTimeout(state.viewTimer);
      map.stop();
      map.setView(center, zoom, { animate: !force && !reducedMotion, duration: .65 });
      state.viewTimer = setTimeout(() => { state.programmaticView = false; }, 850);
    }
  }

  function renderItinerary() {
    const container = $('itinerary');
    container.replaceChildren();
    (state.selected.legs || []).forEach(leg => {
      const row = make('div', 'itinerary-leg');
      const walk = leg.mode === 'walk';
      const modeIcon = make('span', `leg-icon${walk ? '' : ' transit'}`);
      modeIcon.append(icon(leg.mode));
      if (!walk) styleRoute(modeIcon, leg);
      modeIcon.setAttribute('aria-hidden', 'true');
      const info = make('div', 'leg-text');
      const duration = (timeValue(leg.arrival) - timeValue(leg.departure)) / 1000;
      info.append(make('strong', '', walk ? `Walk to ${leg.to ? leg.to.name : 'your stop'}` : `${leg.mode === 'train' ? 'Train' : leg.mode === 'ferry' ? 'Ferry' : 'Bus'} ${leg.routeName || ''} · ${leg.headsign || (leg.to ? leg.to.name : '')}`));
      info.append(make('span', '', walk ? `${minutes(duration)} min walk · approximate walking path` : `${leg.from ? leg.from.name : 'Board'} → ${leg.to ? leg.to.name : 'Alight'}`));
      if (!walk && leg.tripId) info.append(timetableButton('View service timetable', tripQuery(leg), 'itinerary-timetable'));
      row.append(modeIcon, info, make('span', 'leg-time', clock(leg.departure)));
      container.append(row);
    });
    const arrival = make('div', 'itinerary-leg');
    const arrivalIcon = make('span', 'leg-icon');
    arrivalIcon.append(icon('destination'));
    arrival.append(arrivalIcon);
    const destination = make('div', 'leg-text');
    destination.append(make('strong', '', state.destination ? state.destination.name : 'Arrive at destination'), make('span', '', 'Scheduled arrival · Perth time'));
    arrival.append(destination, make('span', 'leg-time', clock(state.selected.arrival)));
    container.append(arrival);
  }

  function updateNextService() {
    const container = $('next-service');
    const leg = nextLeg();
    state.nextTrip = leg ? String(leg.tripId) : null;
    if (!state.selected || !leg) {
      container.hidden = true;
      return;
    }
    container.hidden = false;
    container.replaceChildren();
    const mode = leg.mode === 'train' ? 'TRAIN' : leg.mode === 'ferry' ? 'FERRY' : 'BUS';
    const heading = make('div', 'next-heading', `YOUR NEXT ${mode}`);
    heading.append(make('span', 'estimate-mini', state.status && state.status.mode === 'demo' ? 'DEMO' : 'SCHEDULED'));
    const main = make('div', 'next-main');
    const info = make('div', 'next-info');
    info.append(make('strong', '', leg.headsign || (leg.to ? leg.to.name : 'Your selected service')), make('span', '', leg.from ? leg.from.name : 'Your boarding stop'));
    const departure = timeValue(leg.departure);
    const until = Math.ceil((departure - Date.now()) / 60000);
    let countdown = until > 0 ? String(until) : 'Now';
    let suffix = until > 0 ? 'min to board' : 'scheduled en route';
    if (!Number.isFinite(departure)) { countdown = '—'; suffix = 'check timetable'; }
    const time = make('div', 'next-countdown', countdown);
    time.append(make('small', '', suffix));
    const routeBadge = make('div', 'next-route', serviceLabel(leg));
    styleRoute(routeBadge, leg);
    routeBadge.title = leg.routeName || mode;
    main.append(routeBadge, info, time);
    const isVisible = state.vehicles.some(vehicle => String(vehicle.tripId) === String(leg.tripId));
    const footnote = state.status && state.status.mode === 'demo' ? 'Demo only. Vehicle movement and journey times are illustrative.' : (isVisible ? 'Bright marker = your service. Other vehicles on this line stay visible. Positions are estimates.' : 'Other vehicles on your line stay visible. Your service marker appears while the trip is running.');
    container.append(heading, main, timetableButton('View timetable', tripQuery(leg), 'next-timetable'), make('div', 'next-footnote', footnote));
  }

  function clearJourney(showMessage = true) {
    state.planNumber++;
    state.selected = null;
    state.journeys = [];
    state.nextTrip = null;
    state.wholeNetwork = false;
    state.needsJourneyFit = false;
    state.lastFollowFit = null;
    stopLocationWatch();
    $('follow-journey').hidden = true;
    if (journeyLayers) journeyLayers.clearLayers();
    $('journey-results').hidden = true;
    document.querySelector('.journey-panel').classList.remove('has-journey');
    $('plan-button').disabled = false;
    $('plan-button').firstElementChild.textContent = 'Find my next ride';
    if (showMessage) message('Back to the network. Choose a destination to plan another journey.');
    if (map) fetchVehicles();
    drawNetworkLines();
    updateLauncher();
  }

  function vehicleIcon(vehicle, highlighted) {
    const mode = ['bus', 'train', 'ferry'].includes(vehicle.mode) ? vehicle.mode : 'bus';
    const container = styleRoute(make('div', `vehicle-body ${mode}${highlighted ? ' is-next' : ''}`), vehicle);
    container.append(icon(mode), make('span', 'vehicle-code', serviceLabel(vehicle)));
    if (vehicle.bearing !== null && vehicle.bearing !== undefined && Number.isFinite(Number(vehicle.bearing))) {
      const direction = make('span', 'vehicle-direction');
      const bearing = ((Number(vehicle.bearing) % 360) + 360) % 360;
      direction.style.transform = `rotate(${bearing}deg)`;
      direction.dataset.bearing = String(bearing);
      const arrow = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      arrow.setAttribute('viewBox', '0 0 16 12');
      arrow.setAttribute('aria-hidden', 'true');
      const triangle = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      triangle.setAttribute('d', 'M8 1 15 11 8 8 1 11Z');
      arrow.append(triangle);
      direction.append(arrow);
      container.append(direction);
    }
    if (highlighted) container.append(make('span', 'vehicle-next-label', `YOUR NEXT ${vehicle.mode === 'train' ? 'TRAIN' : vehicle.mode === 'ferry' ? 'FERRY' : 'BUS'}`));
    return L.divIcon({ html: container, className: 'vehicle-marker', iconSize: highlighted ? [52, 52] : [42, 42], iconAnchor: highlighted ? [26, 26] : [21, 21] });
  }

  function servicePopup(vehicle, highlighted) {
    const content = make('div', 'service-popup');
    const mode = vehicle.mode === 'train' ? 'Train' : vehicle.mode === 'ferry' ? 'Ferry' : 'Bus';
    content.append(make('strong', '', `${highlighted ? 'Your next ' : ''}${mode.toLowerCase()} ${vehicle.routeName || ''}`), make('span', '', vehicle.headsign || 'Scheduled service'));
    if (vehicle.nextStop) content.append(make('span', '', `Next: ${typeof vehicle.nextStop === 'object' ? vehicle.nextStop.name : vehicle.nextStop}`));
    if (vehicle.upcoming) content.append(make('span', '', 'Scheduled trip not started · marker at first stop'));
    content.append(make('span', '', state.status && state.status.mode === 'demo' ? 'Illustrative demo position' : 'Position estimated from timetable · not live GPS'));
    content.append(timetableButton('View timetable', tripQuery(vehicle)));
    return content;
  }

  function moveMarker(marker, vehicle, generatedAt) {
    if (marker.nextstopAnimation) cancelAnimationFrame(marker.nextstopAnimation);
    const position = [Number(vehicle.lat), Number(vehicle.lon)];
    const direction = marker.getElement() && marker.getElement().querySelector('.vehicle-direction');
    const bearing = Number.isFinite(Number(vehicle.bearing)) && vehicle.bearing !== null ? Number(vehicle.bearing) : null;
    const rotate = angle => {
      if (!direction || !Number.isFinite(angle)) return;
      marker.nextstopBearing = angle;
      direction.style.transform = `rotate(${angle}deg)`;
    };
    const turn = (from, to, fraction) => from + ((((to - from) % 360) + 540) % 360 - 180) * fraction;
    const startBearing = Number.isFinite(marker.nextstopBearing) ? marker.nextstopBearing : bearing;
    let start = marker.getLatLng();
    if (reducedMotion || document.hidden || !marker._map) { marker.setLatLng(position); rotate(bearing); return; }
    const motion = vehicle.motion || {};
    let frames = Array.isArray(motion.frames) ? motion.frames.filter(frame => Number.isFinite(Number(frame.offset)) && Number.isFinite(Number(frame.lat)) && Number.isFinite(Number(frame.lon))).map(frame => ({ offset: Number(frame.offset), lat: Number(frame.lat), lon: Number(frame.lon), bearing: frame.bearing === null || frame.bearing === undefined ? null : Number(frame.bearing) })).sort((a, b) => a.offset - b.offset) : [];
    if (frames.length < 2 && !vehicle.upcoming && Array.isArray(motion.geometry) && motion.geometry.length > 1) {
      let distance = 0;
      const path = motion.geometry.map((point, index) => {
        if (index) distance += map.distance(motion.geometry[index - 1], point);
        return { lat: point[0], lon: point[1], distance };
      });
      const duration = Math.min(30, Math.max(0, Number(motion.duration) || 15));
      frames = path.map((point, index) => ({ ...point, offset: distance ? point.distance / distance * duration : duration * index / (path.length - 1), bearing: motion.endBearing === null || motion.endBearing === undefined ? bearing : Number(motion.endBearing) }));
    }
    if (frames.length < 2) {
      if (vehicle.upcoming) { marker.setLatLng(position); rotate(bearing); return; }
      frames = [{ offset: 0, lat: start.lat, lon: start.lng, bearing: startBearing }, { offset: .8, lat: position[0], lon: position[1], bearing }];
    }
    const duration = Math.min(30, Math.max(0, frames[frames.length - 1].offset));
    const age = vehicle.motion ? Math.max(0, Math.min(duration, (Date.now() - timeValue(generatedAt)) / 1000 || 0)) : 0;
    if (map.distance(start, position) > 120) { start = L.latLng(position); marker.setLatLng(position); }
    const since = performance.now();
    const sample = offset => {
      let index = 0;
      while (index < frames.length - 2 && frames[index + 1].offset < offset) index++;
      const a = frames[index], b = frames[index + 1];
      const fraction = Math.max(0, Math.min(1, (offset - a.offset) / (b.offset - a.offset || 1)));
      const angleA = Number.isFinite(a.bearing) ? a.bearing : startBearing;
      const angleB = Number.isFinite(b.bearing) ? b.bearing : angleA;
      return { lat: a.lat + (b.lat - a.lat) * fraction, lon: a.lon + (b.lon - a.lon) * fraction, bearing: Number.isFinite(angleA) && Number.isFinite(angleB) ? turn(angleA, angleB, fraction) : bearing };
    };
    rotate(startBearing);
    const animate = now => {
      if (!marker._map || document.hidden) return;
      const elapsed = Math.max(0, (now - since) / 1000);
      const point = sample(Math.min(duration, age + elapsed));
      const reconcile = Math.min(1, elapsed / .7);
      marker.setLatLng([start.lat + (point.lat - start.lat) * reconcile, start.lng + (point.lon - start.lng) * reconcile]);
      rotate(Number.isFinite(startBearing) && Number.isFinite(point.bearing) ? turn(startBearing, point.bearing, reconcile) : point.bearing);
      if (age + elapsed < duration) marker.nextstopAnimation = requestAnimationFrame(animate);
    };
    marker.nextstopAnimation = requestAnimationFrame(animate);
  }

  function renderVehicles(data) {
    state.vehicles = Array.isArray(data.vehicles) ? data.vehicles : [];
    updateNextService();
    if (state.selected && state.needsJourneyFit) {
      state.needsJourneyFit = false;
      fitJourneyBounds(state.selected);
    } else if (state.selected) fitJourneyBounds(state.selected, false);
    const retained = new Set();
    state.vehicles.forEach(vehicle => {
      if (!Number.isFinite(Number(vehicle.lat)) || !Number.isFinite(Number(vehicle.lon))) return;
      const id = String(vehicle.id || vehicle.tripId);
      retained.add(id);
      const highlighted = Boolean(state.nextTrip && String(vehicle.tripId) === state.nextTrip);
      let marker = state.markers.get(id);
      if (!marker) {
        marker = L.marker([vehicle.lat, vehicle.lon], { icon: vehicleIcon(vehicle, highlighted), zIndexOffset: highlighted ? 1000 : 300, bubblingMouseEvents: false }).addTo(map);
        marker.bindPopup(servicePopup(vehicle, highlighted));
        state.markers.set(id, marker);
      } else {
        marker.setIcon(vehicleIcon(vehicle, highlighted));
        marker.setZIndexOffset(highlighted ? 1000 : 300);
        marker.setPopupContent(servicePopup(vehicle, highlighted));
      }
      moveMarker(marker, vehicle, data.generatedAt);
      const element = marker.getElement();
      if (element) {
        const direction = vehicle.bearing !== null && vehicle.bearing !== undefined && Number.isFinite(Number(vehicle.bearing)) ? `, heading ${['north', 'northeast', 'east', 'southeast', 'south', 'southwest', 'west', 'northwest'][Math.round(Number(vehicle.bearing) / 45) % 8]}` : '';
        element.setAttribute('aria-label', `${highlighted ? 'Your next ' : ''}${vehicle.mode || 'bus'} ${vehicle.routeName || ''}, ${vehicle.headsign || 'scheduled service'}${direction}, estimated position. Open for timetable.`);
      }
    });
    state.markers.forEach((marker, id) => {
      if (!retained.has(id)) {
        if (marker.nextstopAnimation) cancelAnimationFrame(marker.nextstopAnimation);
        map.removeLayer(marker);
        state.markers.delete(id);
      }
    });
    stopLayers.clearLayers();
    if (map.getZoom() >= 14 || state.selected) {
      (data.stops || []).slice(0, 120).forEach(stop => {
        const marker = L.marker([stop.lat, stop.lon], { icon: L.divIcon({ className: 'stop-marker', iconSize: [12, 12], iconAnchor: [6, 6] }), zIndexOffset: 100, bubblingMouseEvents: false, title: `${stop.name} · view timetable` });
        const tooltip = make('span', '', stop.name);
        marker.bindTooltip(tooltip).addTo(stopLayers);
        marker.on('click', () => openTimetable({ stop: stop.id }));
      });
    }
    if (!state.routeCatalog && Array.isArray(data.lines) && data.lines.length) {
      state.fallbackLines = data.lines;
      state.routesEpoch++;
      drawNetworkLines();
    }
    const count = state.vehicles.length;
    const demo = state.status && state.status.mode === 'demo';
    const walkingOnly = state.selected && transitLegs().length === 0 && !state.wholeNetwork;
    $('network-count').textContent = walkingOnly ? 'Your walking route' : `${count}${data.truncated ? '+' : ''} ${count === 1 ? 'service' : 'services'} ${demo ? 'in demo' : 'scheduled now'}`;
    $('network-detail').textContent = walkingOnly ? 'Transport services hidden for this journey' : data.truncated ? 'Zoom in to see all services in an area' : state.selected && !state.wholeNetwork ? 'On your selected line · in this map area' : 'In this map area · updates every 15 sec';
  }

  async function fetchVehicles() {
    if (!map) return;
    if (state.vehicleController) state.vehicleController.abort();
    state.vehicleController = new AbortController();
    const request = ++state.requestNumber;
    if (state.selected && transitLegs().length === 0 && !state.wholeNetwork) {
      renderVehicles({ vehicles: [], stops: [], lines: [] });
      return;
    }
    const bounds = map.getBounds();
    const params = { bbox: [bounds.getWest(), bounds.getSouth(), bounds.getEast(), bounds.getNorth()].join(','), lines: '0' };
    if (state.selected && !state.wholeNetwork) params.routes = [...new Set(transitLegs().map(leg => leg.routeId).filter(value => value !== undefined))].join(',');
    const leg = nextLeg();
    if (leg && leg.tripId) params.journey = leg.tripId;
    try {
      const data = await api('vehicles', params, state.vehicleController.signal);
      if (request === state.requestNumber) renderVehicles(data);
    } catch (error) {
      if (error.name === 'AbortError') return;
      $('network-count').textContent = 'Network unavailable';
      $('network-detail').textContent = 'Retrying in 15 seconds';
    }
  }

  function init() {
    if (!window.L) {
      message('The map library could not load. Check your connection and reload the page.', true);
      $('data-banner-text').textContent = 'Map unavailable';
      $('network-count').textContent = 'Connection needed';
      return;
    }
    map = L.map('map', { zoomControl: false, center: [-31.952, 115.878], zoom: 13, minZoom: 9, maxZoom: 19, zoomAnimation: !reducedMotion, preferCanvas: true });
    if (isMobile()) {
      map.setView([state.origin.lat, state.origin.lon], 13, { animate: false });
      map.panBy([0, Math.round(window.innerHeight * .25)], { animate: false });
    }
    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors' }).addTo(map);
    let tilesLoaded = 0;
    tiles.on('tileload', () => { tilesLoaded++; $('map-warning').hidden = true; });
    tiles.on('tileerror', () => { if (!tilesLoaded) $('map-warning').hidden = false; });
    networkLayers = L.layerGroup().addTo(map);
    journeyLayers = L.layerGroup().addTo(map);
    stopLayers = L.layerGroup().addTo(map);
    updatePlaceMarker('origin');
    map.on('click', event => {
      if (isMobile() && !state.pickField) { setPanel(false); return; }
      const field = state.pickField || 'destination';
      setPlace(field, { lat: event.latlng.lat, lon: event.latlng.lng, name: field === 'origin' ? 'Starting point on map' : 'Destination on map' });
      message(field === 'destination' ? 'Destination set on the map. Find your next ride below.' : 'Starting point set on the map.');
      if (isMobile()) setPanel(false);
    });
    map.on('moveend', () => { drawNetworkLines(); clearTimeout(moveTimer); moveTimer = setTimeout(fetchVehicles, 350); });
    map.on('dragstart', () => { if (state.selected) setFollow(false); });
    map.on('zoomstart', () => { state.zooming = true; if (state.selected && !state.programmaticView) setFollow(false); });
    map.on('zoomend', () => {
      state.zooming = false;
      const pendingFit = state.fitAfterZoom;
      state.fitAfterZoom = false;
      if (pendingFit && state.selected && state.followJourney) {
        requestAnimationFrame(() => { if (state.selected && state.followJourney) fitJourneyBounds(state.selected); });
      }
    });
    map.getContainer().addEventListener('wheel', () => { if (state.selected) setFollow(false); }, { passive: true });
    map.getContainer().addEventListener('touchstart', event => { if (state.selected && event.touches.length > 1) setFollow(false); }, { passive: true });
    map.getContainer().addEventListener('keydown', event => { if (state.selected && ['+', '-', '='].includes(event.key)) setFollow(false); });
    let resizeTimer;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => { map.invalidateSize({ pan: false }); setPanel(state.panelOpen); if (state.selected) fitJourneyBounds(state.selected); }, 150);
    });

    $('open-panel').addEventListener('click', () => setPanel(true, true));
    $('close-panel').addEventListener('click', () => { setPanel(false); $('open-panel').focus({ preventScroll: true }); });
    $('timetable-close').addEventListener('click', () => $('timetable-dialog').close());
    $('timetable-back').addEventListener('click', () => {
      if (state.timetableHistory.length < 2) return;
      state.timetableHistory.pop();
      openTimetable(state.timetableHistory[state.timetableHistory.length - 1], false);
    });
    $('timetable-dialog').addEventListener('close', () => {
      if (state.timetableController) state.timetableController.abort();
      state.timetableNumber++;
      state.timetableHistory = [];
      if (state.timetableFocus && state.timetableFocus.isConnected) state.timetableFocus.focus({ preventScroll: true });
      else (isMobile() && !state.panelOpen ? $('open-panel') : $('journey-panel')).focus({ preventScroll: true });
    });

    $('journey-form').addEventListener('submit', event => { event.preventDefault(); plan(); });
    ['origin', 'destination'].forEach(field => {
      const input = $(`${field}-input`);
      input.addEventListener('focus', () => { state.field = field; });
      input.addEventListener('input', () => {
        state[field] = null;
        clearTimeout(state.searchTimer);
        if (state.searchController) state.searchController.abort();
        state.searchNumber++;
        $('search-results').hidden = true;
        if (state.placeMarkers[field]) { map.removeLayer(state.placeMarkers[field]); state.placeMarkers[field] = null; }
        if (state.selected) clearJourney(false);
        updateLauncher();
        if (input.value.trim().length >= 3) state.searchTimer = setTimeout(() => search(field, true), 500);
      });
      input.addEventListener('keydown', event => {
        if (event.key === 'Enter') { event.preventDefault(); search(field); }
        if (event.key === 'Escape') $('search-results').hidden = true;
      });
    });
    document.querySelectorAll('[data-search]').forEach(button => button.addEventListener('click', () => search(button.dataset.search)));
    $('use-location').addEventListener('click', locate);
    $('map-locate').addEventListener('click', locate);
    $('choose-on-map').addEventListener('click', () => {
      const field = state.field || 'destination';
      setMapPick(field);
      $('search-results').hidden = true;
      message(field === 'origin' ? 'Tap a starting point on the map. Select the destination field to choose your destination instead.' : 'Tap your destination on the map.');
      if (isMobile()) setPanel(false);
    });
    $('cancel-map-pick').addEventListener('click', () => { setMapPick(null); message('Map selection cancelled.'); });
    $('clear-journey').addEventListener('click', () => clearJourney());
    $('network-toggle').addEventListener('click', () => {
      state.wholeNetwork = !state.wholeNetwork;
      $('network-toggle').setAttribute('aria-pressed', String(state.wholeNetwork));
      drawNetworkLines();
      fetchVehicles();
    });
    $('follow-journey').addEventListener('click', () => setFollow(true));
    $('map-zoom-in').addEventListener('click', () => { if (state.selected) setFollow(false); map.zoomIn(); });
    $('map-zoom-out').addEventListener('click', () => { if (state.selected) setFollow(false); map.zoomOut(); });
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        fetchVehicles(); loadStatus(); startLocationWatch();
        if (!state.routeCatalog) { state.routeRetryCount = 0; refreshRouteCatalog(); }
      } else {
        stopLocationWatch(); clearTimeout(state.routeRetryTimer);
        state.markers.forEach(marker => { if (marker.nextstopAnimation) cancelAnimationFrame(marker.nextstopAnimation); });
      }
    });
    updateClock();
    setPanel(false);
    loadStatus();
    loadRouteCatalog();
    fetchVehicles();
    setInterval(() => { updateClock(); updateNextService(); updateLauncher(); if (!document.hidden) fetchVehicles(); }, 15000);
    setInterval(() => { if (!document.hidden) loadStatus(); }, 60000);
    setInterval(() => { if (!document.hidden) refreshRouteCatalog(); }, 300000);
  }

  init();
})();
