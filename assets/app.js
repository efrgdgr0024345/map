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
    nextTrip: null, planNumber: 0, searchTimer: null, searchController: null, needsJourneyFit: false
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
  }

  function setMapPick(field) {
    state.pickField = field;
    $('map-hint').hidden = !field;
    $('map').classList.toggle('map-picking', Boolean(field));
    if (field) $('map-hint-text').textContent = `Tap the map to choose your ${field === 'origin' ? 'starting point' : 'destination'}`;
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
      setPlace('origin', { lat, lon, name: 'Your location' }, true);
      map.setZoom(15);
      message(state.destination ? 'Starting from your location. Find your next ride below.' : 'Starting from your location. Where would you like to go?');
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
      if (window.innerWidth <= 760) document.querySelector('.journey-panel').scrollTop = 0;
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
    state.wholeNetwork = false;
    $('network-toggle').setAttribute('aria-pressed', 'false');
    document.querySelectorAll('.journey-option').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.journey === String(journey.id))));
    journeyLayers.clearLayers();
    networkLayers.clearLayers();
    (journey.legs || []).forEach(leg => {
      const fallback = leg.from && leg.to ? [[leg.from.lat, leg.from.lon], [leg.to.lat, leg.to.lon]] : [];
      const geometry = Array.isArray(leg.geometry) && leg.geometry.length ? leg.geometry : fallback;
      if (geometry.length >= 2) {
        const transit = leg.mode !== 'walk';
        L.polyline(geometry, { color: '#fff', weight: transit ? 9 : 6, opacity: .95, interactive: false }).addTo(journeyLayers);
        L.polyline(geometry, { color: transit ? '#243b2b' : '#708a5c', weight: transit ? 5 : 3, opacity: .95, dashArray: transit ? null : '3 7', lineCap: 'round', interactive: false }).addTo(journeyLayers);
      }
    });
    renderItinerary();
    updateNextService();
    state.needsJourneyFit = true;
    fitJourneyBounds(journey);
    fetchVehicles();
  }

  function fitJourneyBounds(journey) {
    const bounds = L.latLngBounds([]);
    (journey.legs || []).forEach(leg => {
      (leg.geometry || []).forEach(point => bounds.extend(point));
      if (leg.from) bounds.extend([leg.from.lat, leg.from.lon]);
      if (leg.to) bounds.extend([leg.to.lat, leg.to.lon]);
    });
    const vehicle = state.vehicles.find(item => state.nextTrip && String(item.tripId) === state.nextTrip);
    if (vehicle) bounds.extend([vehicle.lat, vehicle.lon]);
    if (bounds.isValid()) {
      const mobile = window.innerWidth <= 760;
      map.stop();
      map.fitBounds(bounds, { paddingTopLeft: mobile ? [38, 145] : [425, 125], paddingBottomRight: mobile ? [40, Math.round(window.innerHeight * .61) + 15] : [90, 160], maxZoom: 16, animate: false });
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
      modeIcon.setAttribute('aria-hidden', 'true');
      const info = make('div', 'leg-text');
      const duration = (timeValue(leg.arrival) - timeValue(leg.departure)) / 1000;
      info.append(make('strong', '', walk ? `Walk to ${leg.to ? leg.to.name : 'your stop'}` : `${leg.mode === 'train' ? 'Train' : leg.mode === 'ferry' ? 'Ferry' : 'Bus'} ${leg.routeName || ''} · ${leg.headsign || (leg.to ? leg.to.name : '')}`));
      info.append(make('span', '', walk ? `${minutes(duration)} min walk · approximate walking path` : `${leg.from ? leg.from.name : 'Board'} → ${leg.to ? leg.to.name : 'Alight'}`));
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
    routeBadge.title = leg.routeName || mode;
    main.append(routeBadge, info, time);
    const isVisible = state.vehicles.some(vehicle => String(vehicle.tripId) === String(leg.tripId));
    const footnote = state.status && state.status.mode === 'demo' ? 'Demo only. Vehicle movement and journey times are illustrative.' : (isVisible ? 'Bright marker = your service. Other vehicles on this line stay visible. Positions are estimates.' : 'Other vehicles on your line stay visible. Your service marker appears while the trip is running.');
    container.append(heading, main, make('div', 'next-footnote', footnote));
  }

  function clearJourney(showMessage = true) {
    state.planNumber++;
    state.selected = null;
    state.journeys = [];
    state.nextTrip = null;
    state.wholeNetwork = false;
    state.needsJourneyFit = false;
    if (journeyLayers) journeyLayers.clearLayers();
    $('journey-results').hidden = true;
    document.querySelector('.journey-panel').classList.remove('has-journey');
    $('plan-button').disabled = false;
    $('plan-button').firstElementChild.textContent = 'Find my next ride';
    if (showMessage) message('Back to the network. Choose a destination to plan another journey.');
    if (map) fetchVehicles();
  }

  function vehicleIcon(vehicle, highlighted) {
    const container = make('div', `vehicle-body ${vehicle.mode || 'bus'}${highlighted ? ' is-next' : ''}`, serviceLabel(vehicle));
    if (highlighted) container.append(make('span', 'vehicle-next-label', `YOUR NEXT ${vehicle.mode === 'train' ? 'TRAIN' : vehicle.mode === 'ferry' ? 'FERRY' : 'BUS'}`));
    return L.divIcon({ html: container, className: 'vehicle-marker', iconSize: highlighted ? [43, 43] : [32, 32], iconAnchor: highlighted ? [21, 23] : [16, 18] });
  }

  function servicePopup(vehicle, highlighted) {
    const content = make('div', 'service-popup');
    const mode = vehicle.mode === 'train' ? 'Train' : vehicle.mode === 'ferry' ? 'Ferry' : 'Bus';
    content.append(make('strong', '', `${highlighted ? 'Your next ' : ''}${mode.toLowerCase()} ${vehicle.routeName || ''}`), make('span', '', vehicle.headsign || 'Scheduled service'));
    if (vehicle.nextStop) content.append(make('span', '', `Next: ${typeof vehicle.nextStop === 'object' ? vehicle.nextStop.name : vehicle.nextStop}`));
    if (vehicle.upcoming) content.append(make('span', '', 'Scheduled trip not started · marker at first stop'));
    content.append(make('span', '', state.status && state.status.mode === 'demo' ? 'Illustrative demo position' : 'Position estimated from timetable · not live GPS'));
    return content;
  }

  function moveMarker(marker, position) {
    if (reducedMotion || !marker._map) { marker.setLatLng(position); return; }
    if (marker.nextstopAnimation) cancelAnimationFrame(marker.nextstopAnimation);
    const start = marker.getLatLng();
    const end = L.latLng(position);
    const since = performance.now();
    const animate = now => {
      const progress = Math.min((now - since) / 1000, 1);
      marker.setLatLng([start.lat + (end.lat - start.lat) * progress, start.lng + (end.lng - start.lng) * progress]);
      if (progress < 1 && marker._map) marker.nextstopAnimation = requestAnimationFrame(animate);
    };
    marker.nextstopAnimation = requestAnimationFrame(animate);
  }

  function renderVehicles(data) {
    state.vehicles = Array.isArray(data.vehicles) ? data.vehicles : [];
    updateNextService();
    if (state.selected && state.needsJourneyFit) {
      state.needsJourneyFit = false;
      fitJourneyBounds(state.selected);
    }
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
        moveMarker(marker, [vehicle.lat, vehicle.lon]);
        marker.setPopupContent(servicePopup(vehicle, highlighted));
      }
      const element = marker.getElement();
      if (element) element.setAttribute('aria-label', `${highlighted ? 'Your next ' : ''}${vehicle.mode || 'bus'} ${vehicle.routeName || ''}, ${vehicle.headsign || 'scheduled service'}, estimated position`);
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
        const marker = L.marker([stop.lat, stop.lon], { icon: L.divIcon({ className: 'stop-marker', iconSize: [8, 8], iconAnchor: [4, 4] }), zIndexOffset: 100, bubblingMouseEvents: false });
        const tooltip = make('span', '', stop.name);
        marker.bindTooltip(tooltip).addTo(stopLayers);
      });
    }
    networkLayers.clearLayers();
    if (!state.selected || state.wholeNetwork) {
      (data.lines || []).forEach(line => {
        if (Array.isArray(line.geometry) && line.geometry.length > 1) L.polyline(line.geometry, { color: line.mode === 'train' ? '#8798a5' : '#9bad8c', weight: 2, opacity: .48, interactive: false }).addTo(networkLayers);
      });
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
    const params = { bbox: [bounds.getWest(), bounds.getSouth(), bounds.getEast(), bounds.getNorth()].join(',') };
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
    map = L.map('map', { zoomControl: false, center: [-31.952, 115.878], zoom: 13, minZoom: 9, maxZoom: 19, zoomAnimation: !reducedMotion });
    if (window.innerWidth <= 760) {
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
      const field = state.pickField || 'destination';
      setPlace(field, { lat: event.latlng.lat, lon: event.latlng.lng, name: field === 'origin' ? 'Starting point on map' : 'Destination on map' });
      message(field === 'destination' ? 'Destination set on the map. Find your next ride below.' : 'Starting point set on the map.');
    });
    map.on('moveend', () => { clearTimeout(moveTimer); moveTimer = setTimeout(fetchVehicles, 350); });
    let resizeTimer;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => { map.invalidateSize({ pan: false }); if (state.selected) fitJourneyBounds(state.selected); }, 150);
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
    });
    $('cancel-map-pick').addEventListener('click', () => { setMapPick(null); message('Map selection cancelled.'); });
    $('clear-journey').addEventListener('click', () => clearJourney());
    $('network-toggle').addEventListener('click', () => {
      state.wholeNetwork = !state.wholeNetwork;
      $('network-toggle').setAttribute('aria-pressed', String(state.wholeNetwork));
      fetchVehicles();
    });
    $('map-zoom-in').addEventListener('click', () => map.zoomIn());
    $('map-zoom-out').addEventListener('click', () => map.zoomOut());
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { fetchVehicles(); loadStatus(); } });
    updateClock();
    loadStatus();
    fetchVehicles();
    setInterval(() => { updateClock(); updateNextService(); if (!document.hidden) fetchVehicles(); }, 15000);
    setInterval(() => { if (!document.hidden) loadStatus(); }, 60000);
  }

  init();
})();
