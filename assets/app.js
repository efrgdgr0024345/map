/* Nextstop: open maps, Perth address search, and clearly labelled timetable estimates. */
(() => {
  'use strict';
  const STREET_ZOOM = 16;
  // Temporary last-mile test threshold; increase this value for production tuning.
  const LAST_MILE_UBER_THRESHOLD_SECONDS = 180;
  const LAST_MILE_UBER_FARE = { baseAud: 5, perKmAud: 2.2, minimumAud: 8, roadFactor: 1.25 };

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
    mapOnly: false, mapOnlyDialogs: [], mapOnlyClosedDialogs: new WeakMap(), mapOnlyFocus: null,
    origin: { lat: -31.9505, lon: 115.8586, name: 'Perth Busport' },
    destination: null, field: 'destination', pickField: null,
    journeys: [], selected: null, journeyMode: null, timeMode: 'now', searchModes: {bus:true,train:true,ferry:true,uber:true,walk:true}, wholeNetwork: false, status: null,
    vehicles: [], markers: new Map(), placeMarkers: {},
    requestNumber: 0, searchNumber: 0, vehicleController: null,
    nextTrip: null, planNumber: 0, searchTimer: null, searchController: null, needsJourneyFit: false,
    panelOpen: false, timetableController: null, timetableHistory: [], timetableNumber: 0, timetableFocus: null,
    routeCatalog: null, routeRevision: '', routeEtag: '', routeRefresh: null, routeDb: null,
    renderedNetworkKey: '', fallbackLines: [], routesEpoch: 0, routeStored: false, routeRetryTimer: null, routeRetryCount: 0,
    followJourney: true, programmaticView: false, lastFollowFit: null, viewTimer: null,
    userLocation: null, locationConsent: false, locationWatch: null, gpsMarker: null, accuracyCircle: null,
    zooming: false, fitAfterZoom: false, locationRetryTimer: null, locationRetryCount: 0,
    toiletsEnabled: false, toilets: [], toiletMetadata: null, toiletLayers: null, toiletMarkers: new Map(), toiletController: null, toiletDetailController: null, toiletRequest: 0, toiletMoveTimer: null,
    phonesEnabled: false, phoneLayers: null, phones: [], phoneMetadata: null, phonesController: null, phoneRequest: 0, phonesMoveTimer: null,
    approachLayers: null, approachRenderer: null, approachController: null, approachKey: '', approachData: null,
    approachRequestedAt: 0, approachMarker: null, firstWalkFocus: false, firstWalkLayers: null, toastTimer: null, suppressSheetClickUntil: 0,
    addressEditField: null, autoPlanTimer: null, planController: null, planSignature: '', pickerPreviousFollow: null,
    mascotTucked: false, transfers: [], guidance: null, guidanceController: null, guidanceLayers: null,
    guidanceRenderer: null, guidanceTargetMarker: null, guidancePrevious: null, dismissedTransfers: new Set(),
    stationMapController: null, stationMapLayers: null, stationMapRenderer: null,
    stationBrowseCatalog: null, stationBrowseCatalogRequest: null, stationBrowseCatalogRetryAt: 0,
    stationBrowse: null, stationBrowseCandidate: null, stationBrowseSuppressed: null,
    stationBrowseController: null, stationBrowseRequest: 0, stationBrowseLayers: null,
    stationBrowseCache: new Map(), stationBrowseTimer: null, stationBrowseReady: false,
    onboard: null, nearbyFocused: false, nearbyVerifyWalk: false, nearbyCamera: null, nearbyFitPending: false, nearbyDetailsOpen: false,
    nearbyLabelLayers: null, nearbyAreaLayers: null, nearbyCountdownNodes: [], nearbyCameraTimer: null,
    nearbyBusData: null, nearbyBusLayers: null, nearbyBusMarker: null, nearbyBusController: null, nearbyBusRequest: 0, nearbyBusFetched: 0, nearbyBusPoint: null, nearbyBusStop: '', nearbyServiceKey: '', nearbyGpsRefresh: false,
    originUserChosen: false, awaitingGpsOrigin: false, fullJourneyOverview: false, externalPick: null, pickPlaceName: null
  };
  const perthTime = new Intl.DateTimeFormat('en-AU', { timeZone: 'Australia/Perth', hour: 'numeric', minute: '2-digit', hour12: true });
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let map, journeyLayers, networkLayers, stopLayers, moveTimer;

  function message(text, error = false) {
    $('message').textContent = text || '';
    $('message').classList.toggle('is-error', error);
    $('message').hidden = !text;
    if (text && error && isMobile() && !state.panelOpen) mapToast(text, true);
  }

  function mapToast(text, error = false, action) {
    clearTimeout(state.toastTimer);
    const toast = $('map-toast');
    toast.replaceChildren(make('span', '', text));
    toast.classList.toggle('is-error', error);
    if (action) {
      const button = make('button', 'toast-action', action.label || 'Retry');
      button.type = 'button';
      button.addEventListener('click', action.run);
      toast.append(button);
    }
    const close = make('button', 'toast-close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss message');
    close.addEventListener('click', () => { toast.hidden = true; });
    toast.append(close);
    toast.hidden = false;
    if (!error) state.toastTimer = setTimeout(() => { toast.hidden = true; }, 3500);
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
    return /^[0-9a-f]{6}$/i.test(value) ? `#${value.toUpperCase()}` : service.mode === 'train' ? '#007EA8' : '#009645';
  }

  function routeTextColor(service) {
    const value = String(service.routeTextColor || '').replace(/^#/, '');
    const color = routeColor(service).slice(1);
    const channels = [0, 2, 4].map(offset => {
      const n = parseInt(color.slice(offset, offset + 2), 16) / 255;
      return n <= .04045 ? n / 12.92 : ((n + .055) / 1.055) ** 2.4;
    });
    const light = channels[0] * .2126 + channels[1] * .7152 + channels[2] * .0722;
    const black = (light + .05) / .05, white = 1.05 / (light + .05);
    if (value.toUpperCase() === '000000' && black >= 4.5) return '#000000';
    if (value.toUpperCase() === 'FFFFFF' && white >= 4.5) return '#FFFFFF';
    return black >= white ? '#000000' : '#FFFFFF';
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
      $('launcher-detail').textContent = `${minutes(duration)} min · Route & timetable`;
    } else {
      $('launcher-title').textContent = state.destination ? state.destination.name : 'Where are you heading?';
      $('launcher-detail').textContent = state.destination ? 'Find a ride' : '';
    }
  }

  function setPanel(open, focus = false) {
    state.panelOpen = Boolean(open);
    document.body.classList.toggle('panel-open', state.panelOpen);
    const hidden = state.mapOnly || Boolean(state.pickField || state.guidance || state.firstWalkFocus) || (isMobile() && !state.panelOpen);
    $('journey-panel').inert = hidden;
    $('journey-panel').setAttribute('aria-hidden', String(hidden));
    $('open-panel').setAttribute('aria-expanded', String(!hidden));
    updateLauncher();
    if (focus && !hidden) $('journey-panel').focus({ preventScroll: true });
    if (hidden && $('journey-panel').contains(document.activeElement)) document.activeElement.blur();
    if (map && state.selected && isMobile() && state.followJourney && !state.pickField && !state.guidance) requestAnimationFrame(() => {
      if (state.selected && state.followJourney && !state.pickField && !state.guidance) fitJourneyBounds(state.selected);
    });
  }

  function suspendMapDialog(dialog) {
    if (!dialog.open) return;
    if (!state.mapOnlyDialogs.some(item => item.dialog === dialog)) {
      state.mapOnlyDialogs.push({ dialog, modal: dialog.matches(':modal'), returnValue: dialog.returnValue,
        scrollTop: dialog.scrollTop, scrollLeft: dialog.scrollLeft });
    }
    state.mapOnlyClosedDialogs.set(dialog, (state.mapOnlyClosedDialogs.get(dialog) || 0) + 1);
    dialog.close();
  }

  function setMapOnly(enabled, source = $('map-view-toggle')) {
    if (state.mapOnly === Boolean(enabled)) return;
    state.mapOnly = Boolean(enabled);
    const button = $('map-view-toggle');
    const label = state.mapOnly ? 'Show controls' : 'Map only — hide controls';
    button.setAttribute('aria-label', label); button.title = label;
    button.setAttribute('aria-pressed', String(state.mapOnly));
    button.querySelector('span').textContent = state.mapOnly ? 'Controls' : 'Map only';
    if (state.mapOnly) {
      state.mapOnlyFocus = source;
      const dialogs = [...document.querySelectorAll('dialog[open]')];
      const active = source.closest('dialog');
      dialogs.sort((a, b) => Number(a === active) - Number(b === active));
      dialogs.slice().reverse().forEach(suspendMapDialog);
      // Restore nested dialogs in their original order, with the current one on top.
      state.mapOnlyDialogs.reverse();
      button.focus({ preventScroll: true });
    }
    document.body.classList.toggle('map-only', state.mapOnly);
    // Only update accessibility here: setPanel also reframes a following journey.
    const panelHidden = state.mapOnly || Boolean(state.pickField || state.guidance || state.firstWalkFocus) || (isMobile() && !state.panelOpen);
    $('journey-panel').inert = panelHidden;
    $('journey-panel').setAttribute('aria-hidden', String(panelHidden));
    $('open-panel').setAttribute('aria-expanded', String(!panelHidden));
    if (!state.mapOnly) {
      const dialogs = state.mapOnlyDialogs; state.mapOnlyDialogs = [];
      dialogs.forEach(({ dialog, modal, returnValue, scrollTop, scrollLeft }) => {
        if (!dialog.isConnected || dialog.open) return;
        dialog.returnValue = returnValue;
        if (modal) dialog.showModal(); else dialog.show();
        dialog.scrollTop = scrollTop; dialog.scrollLeft = scrollLeft;
      });
      const focus = state.mapOnlyFocus; state.mapOnlyFocus = null;
      if (focus?.isConnected) focus.focus({ preventScroll: true });
    }
    drawNearbyLabels();
    renderMapOnlyServices();
    saveTripView();
  }

  function mapOnlyTop() {
    const box = $('map-only-services').getBoundingClientRect();
    return box.height ? box.bottom + 12 : 35;
  }

  function renderMapOnlyServices() {
    const strip = $('map-only-services');
    if (!strip) return;
    if (!state.mapOnly) { strip.hidden = true; state.mapOnlyStripKey = ''; renderNearbyOverview(); return; }
    const entries = [], now = Date.now(), context = state.nearbyBusData?.onboard;
    const add = (key, service, stop, arrival, departure, label, run) => entries.push({ key, service, stop, arrival, departure, label, run });
    const leg = state.guidance?.transfer?.next || nextLeg();
    if (state.selected && leg) {
      const riding = timeValue(leg.departure) <= now && !state.guidance;
      const approach = approachVehicle() && String(state.approachData?.tripId) === String(leg.tripId) ? state.approachData : null;
      const stop = riding ? leg.to : leg.from;
      add(`journey:${leg.tripId}`, leg, stop, riding ? leg.arrival : approach?.arrival || leg.departure,
        riding ? leg.arrival : leg.departure, riding ? 'Alight' : 'Board', () => {
          if (!riding && transitLegs()[0] === leg && state.selected.walkRouting === 'street') focusFirstWalk();
          else setFollow(true);
        });
    } else {
      if (state.onboard && context?.nextStop) {
        add(`onboard:${state.onboard.trip}`, context.trip, context.nextStop, context.alightAt, context.alightAt, 'Alight', () => fitNearbyView(false));
      }
      const data = state.nearbyBusData;
      const services = [...(data?.services || [])].sort((a, b) => Number(b.key === data?.departure?.key) - Number(a.key === data?.departure?.key));
      for (const service of services) {
        if (timeValue(service.departure) < now) continue;
        add(`nearby:${service.key}`, service, service.stop, service.arrival, service.departure,
          state.onboard ? 'After alighting' : 'Board', () => selectNearbyService(service));
      }
    }
    strip.hidden = !entries.length;
    const signature = JSON.stringify(entries.map(e => [e.key, e.service.routeName, e.service.headsign, e.service.routeColor, e.service.routeTextColor,
      e.stop?.id, e.stop?.name, e.arrival, e.departure, e.label]));
    if (signature !== state.mapOnlyStripKey) {
      state.mapOnlyStripKey = signature; state.mapOnlyStripCounters = [];
      strip.replaceChildren(); strip.dataset.count = String(entries.length);
      strip.setAttribute('aria-label', `All ${entries.length} found service directions. Scroll for more routes.`);
      strip.style.setProperty('--service-count', String(entries.length || 1));
      for (const entry of entries) {
        const { service, stop } = entry;
        const button = styleRoute(nearbyButton('', entry.run, 'map-only-service'), service);
        const count = make('b', 'map-only-service-time');
        const destination = service.headsign || service.destination || service.lineName || 'Scheduled service';
        const place = `${entry.label} · ${stop?.code ? `Stop ${stop.code}` : stop?.name || 'Scheduled stop'}`;
        button.append(make('b', 'map-only-route', serviceLabel(service)), count,
          make('strong', 'map-only-destination', `→ ${destination}`), make('small', 'map-only-stop', `${place} · scheduled`));
        button.title = `${service.routeName} to ${destination}. ${place}. Scheduled estimate.`;
        strip.append(button); state.mapOnlyStripCounters.push({ count, button, entry, destination, place });
      }
    }
    for (const { count, button, entry, destination, place } of state.mapOnlyStripCounters || []) {
      const remaining = timeValue(entry.arrival) - now;
      const text = !Number.isFinite(remaining) ? '—' : timeValue(entry.departure) < now ? 'At stop' : remaining <= 0 ? 'Due' : `${Math.ceil(remaining / 60000)} min`;
      if (count.textContent !== text) {
        count.textContent = text;
        button.setAttribute('aria-label', `${entry.service.routeName} to ${destination}, ${text}. ${place}. Scheduled estimate.`);
      }
    }
    renderNearbyOverview();
  }

  function renderNearbyOverview() {
    if (!map) return;
    if (!state.nearbyOverviewLayers) state.nearbyOverviewLayers = L.layerGroup().addTo(map);
    const data = state.nearbyBusData;
    const services = state.mapOnly && !state.selected && !state.onboard && data?.radius === 500 ? data.services || [] : [];
    const keys = new Set(services.filter(s => timeValue(s.departure) >= Date.now()).map(s => `${s.routeId}/${s.shapeId}`));
    const signature = JSON.stringify([state.mapOnly, state.routeRevision, [...keys]]);
    if (signature === state.nearbyOverviewKey) return;
    state.nearbyOverviewKey = signature; state.nearbyOverviewLayers.clearLayers();
    for (const line of state.routeCatalog || []) {
      if (!keys.has(`${line.routeId}/${line.shapeId}`) || !line.geometry?.length) continue;
      L.polyline(line.geometry, { color: routeColor(line), weight: 4, opacity: .85, interactive: false, className: 'nearby-overview-route' }).addTo(state.nearbyOverviewLayers);
    }
  }

  function bindMapOnlyControls() {
    $('map-view-toggle').addEventListener('click', event => setMapOnly(!state.mapOnly, event.currentTarget));
    document.querySelectorAll('dialog').forEach(dialog => {
      const control = nearbyButton('Map only', () => setMapOnly(true, control), 'dialog-map-only');
      control.setAttribute('aria-label', 'Map only — hide controls');
      (dialog.querySelector('header, .timetable-header') || dialog.querySelector('form') || dialog).append(control);
    });
    // An async result can open a dialog while controls are hidden; keep it for restoration.
    new MutationObserver(records => {
      if (!state.mapOnly) return;
      records.forEach(({ target }) => { if (target.tagName === 'DIALOG') suspendMapDialog(target); });
    }).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['open'] });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && state.mapOnly) {
        event.preventDefault(); event.stopImmediatePropagation(); setMapOnly(false);
      }
    }, true);
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
    renderNearbyOverview();
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
    const trainsAndFerriesOnly = map.getZoom() < STREET_ZOOM || state.stationBrowse || (state.selected && !state.wholeNetwork);
    const catalog = state.routeCatalog || state.fallbackLines;
    if (!catalog.length) return;
    const bounds = map.getBounds().pad(.15);
    const visible = [];
    catalog.forEach((line, index) => {
      if (trainsAndFerriesOnly && !['train', 'ferry'].includes(line.mode)) return;
      const bbox = line.bbox;
      if (Array.isArray(bbox) && bbox.length === 4 && (bbox[2] < bounds.getWest() || bbox[0] > bounds.getEast() || bbox[3] < bounds.getSouth() || bbox[1] > bounds.getNorth())) return;
      if (Array.isArray(line.geometry) && line.geometry.length > 1) visible.push([line, index]);
    });
    const muted = Boolean(state.nearbyBusData && (state.nearbyFocused || state.onboard));
    const key = `${state.routesEpoch}:${Number(muted)}:${visible.map(([, index]) => index).join(',')}`;
    if (key === state.renderedNetworkKey) return;
    state.renderedNetworkKey = key;
    $('map').dataset.networkLines = String(visible.length);
    $('map').dataset.routeRevision = state.routeRevision;
    networkLayers.clearLayers();
    visible.forEach(([line]) => {
      const weight = line.mode === 'train' ? 4.5 : 3.5;
      L.polyline(line.geometry, { color: '#ffffff', weight: weight + 3, opacity: muted ? .16 : .9, interactive: false, className: 'background-network-line' }).addTo(networkLayers);
      L.polyline(line.geometry, { color: routeColor(line), weight, opacity: muted ? .16 : .9, interactive: false, className: 'background-network-line' }).addTo(networkLayers);
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
      $('compact-schedule-status').textContent = demo ? 'Demo · illustrative' : 'Scheduled';
      $('estimate-label').textContent = demo ? 'DEMO' : 'SCHEDULED';
      $('legend-note').textContent = demo ? 'Illustrative demo positions' : 'Positions estimated from timetable';
      $('data-banner').title = status.message || '';
      if (status.routeRevision && state.routeCatalog && String(status.routeRevision) !== state.routeRevision) refreshRouteCatalog();
      updateNextService();
      renderLastMileUber();
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
    clearWalkingAccess();
    state.restoredTripView = null; state.restoredTripId = null;
    clearTimeout(state.searchTimer);
    state.searchNumber++;
    if (state.searchController) state.searchController.abort();
    state[field] = { lat: Number(place.lat), lon: Number(place.lon), name: String(place.name) };
    if (field === 'origin') { state.originUserChosen = true; state.awaitingGpsOrigin = false; }
    state.addressEditField = null;
    ['origin', 'destination'].forEach(name => { $(`${name}-input`).readOnly = true; });
    $(`${field}-input`).value = state[field].name;
    clearJourney(false);
    updatePlaceMarker(field);
    $('search-results').hidden = true;
    setMapPick(null);
    if (pan) map.panTo([place.lat, place.lon]);
    if (state.origin && state.destination) message('Ready when you are. Find a route to see your next ride.');
    updateLauncher();
    saveTripSearch();
    maybeAutoPlan();
  }

  function cancelPendingPlan() {
    clearTimeout(state.autoPlanTimer);
    state.planNumber++;
    if (state.planController) state.planController.abort();
    state.planController = null;
    $('plan-button').disabled = false;
    $('plan-button').firstElementChild.textContent = 'Find my next ride';
  }

  function maybeAutoPlan() {
    clearTimeout(state.autoPlanTimer);
    const valid = point => point && Number.isFinite(point.lat) && Number.isFinite(point.lon) && Math.abs(point.lat) <= 90 && Math.abs(point.lon) <= 180;
    if (!valid(state.origin) || !valid(state.destination) || state.pickField || state.addressEditField) return;
    state.autoPlanTimer = setTimeout(() => { if (!state.pickField && !state.addressEditField) plan(true); }, 350);
  }

  function setMapPick(field) {
    state.pickField = field;
    $('map-hint').hidden = !field;
    $('map').classList.toggle('map-picking', Boolean(field));
    document.body.classList.toggle('map-pick-active', Boolean(field));
    if (field) $('map-hint-text').textContent = `Tap the map to choose your ${field === 'origin' ? 'starting point' : 'destination'}`;
    setPanel(state.panelOpen);
    if (field) $('station-browse').hidden = true;
    else scheduleStationBrowse();
  }

  function beginMapPick(field) {
    cancelPendingPlan();
    state.field = field;
    state.addressEditField = null;
    ['origin', 'destination'].forEach(name => { $(`${name}-input`).readOnly = true; });
    state.pickerPreviousFollow = state.followJourney;
    if (state.selected) setFollow(false);
    setMapPick(field);
    setPanel(false);
    map.getContainer().focus({ preventScroll: true });
  }

  function finishMapPick(point) {
    if (state.externalPick) { finishExternalPick({ lat: point.lat, lon: point.lng }); return; }
    const field = state.pickField;
    if (!field) return;
    const name = state.pickPlaceName || (field === 'origin' ? 'Starting point on map' : 'Destination on map');
    state.pickPlaceName = null;
    setPlace(field, { lat: point.lat, lon: point.lng, name });
    state.pickerPreviousFollow = null;
    setPanel(true);
    const next = field === 'origin' ? 'destination' : 'origin';
    $(`${next}-input`).focus({ preventScroll: true });
  }

  function cancelMapPick() {
    if (state.externalPick) { finishExternalPick(null); return; }
    state.pickPlaceName = null;
    const previousFollow = state.pickerPreviousFollow;
    setMapPick(null);
    state.pickerPreviousFollow = null;
    setPanel(true, true);
    if (state.selected && previousFollow) setFollow(true);
  }

  function finishExternalPick(point) {
    const previous = state.externalPick; state.externalPick = null; state.pickPlaceName = null;
    setMapPick(null); state.pickerPreviousFollow = null; state.field = previous.field;
    setPanel(previous.panelOpen);
    if (state.selected && previous.follow) setFollow(true);
    previous.callback(point);
  }

  function chooseSearchPlace(field, place) {
    map.closePopup();
    if (place.requiresEntrance) {
      beginMapPick(field); state.pickPlaceName = `${place.name} entrance`;
      map.setView([place.lat, place.lon], 18, { animate: false });
      $('map-hint-text').textContent = `Choose the entrance to ${place.name}`;
      return;
    }
    setPlace(field, place, true);
  }

  function editAddress(field) {
    if (state.pickField) setMapPick(null);
    cancelPendingPlan();
    state.field = field;
    state.addressEditField = field;
    ['origin', 'destination'].forEach(name => { $(`${name}-input`).readOnly = name !== field; });
    setPanel(true);
    const input = $(`${field}-input`);
    input.focus({ preventScroll: true });
    input.select();
    input.setAttribute('enterkeyhint', 'search');
  }

  function nearbyBusEnabled() {
    const p = state.userLocation;
    return !state.selected && state.locationConsent && p && p.accuracy !== null && p.accuracy <= 80
      && Date.now() - p.timestamp < 60000;
  }

  function nearbyButton(label, run, className = 'nearby-action') {
    const button = make('button', className, label); button.type = 'button';
    button.addEventListener('click', event => { event.stopPropagation(); run(); });
    return button;
  }

  function leaveNearbyRide() {
    state.onboard = null; state.nearbyServiceKey = ''; state.nearbyBusStop = ''; state.nearbyVerifyWalk = false;
    state.nearbyCamera = 'local'; state.nearbyFitPending = true; clearNearbyBus(); fetchNearbyBus(true);
  }

  function clearNearbyBus(cancelRequest = true) {
    if (cancelRequest) {
      if (state.nearbyBusController) state.nearbyBusController.abort();
      state.nearbyBusController = null; state.nearbyBusRequest++;
    }
    state.nearbyRenderKey = ''; stopNearbyTransition();
    state.nearbyBusData = null; state.nearbyPendingCamera = null;
    if (state.nearbyBusLayers) state.nearbyBusLayers.clearLayers();
    if (state.nearbyLabelLayers) state.nearbyLabelLayers.clearLayers();
    if (state.nearbyAreaLayers) state.nearbyAreaLayers.clearLayers();
    state.nearbyCountdownNodes = [];
    if (state.nearbyBusMarker) {
      if (state.nearbyBusMarker.nextstopAnimation) cancelAnimationFrame(state.nearbyBusMarker.nextstopAnimation);
      map.removeLayer(state.nearbyBusMarker); state.nearbyBusMarker = null;
    }
    const card = $('nearby-bus'); card.hidden = true;
    document.body.classList.remove('has-nearby-bus', 'nearby-focused');
    drawNetworkLines();
    renderMapOnlyServices();
    if (state.onboard && !state.selected && !document.hidden) {
      card.replaceChildren(make('strong', '', `On ${state.onboard.routeName} · nearby guidance paused`),
        make('small', '', 'Waiting for an accurate location or updated services.'), nearbyButton("I'm off", leaveNearbyRide));
      card.hidden = false; document.body.classList.add('has-nearby-bus');
    }
  }

  function selectNearbyService(service) {
    state.nearbyServiceKey = service.key; state.nearbyFocused = true; state.nearbyVerifyWalk = true;
    state.nearbyPendingCamera = null;
    state.nearbyCamera = 'local'; state.nearbyFitPending = true;
    if (state.onboard) state.onboard.place = null;
    fetchNearbyBus(true);
  }

  function fitNearbyView(full = false) {
    const data = state.nearbyBusData; if (!data) return;
    if (state.zooming) {
      state.nearbyPendingCamera = full ? 'full' : 'local';
      if (!state.nearbyZoomFitQueued) {
        state.nearbyZoomFitQueued = true;
        map.once('zoomend', () => {
          state.nearbyZoomFitQueued = false;
          const pending = state.nearbyPendingCamera; state.nearbyPendingCamera = null;
          if (pending) requestAnimationFrame(() => fitNearbyView(pending === 'full'));
        });
      }
      return;
    }
    const points = full && data.geometry?.length > 1 ? [...data.geometry] : [];
    const extend = p => { if (p && Number.isFinite(Number(p.lat)) && Number.isFinite(Number(p.lon))) points.push([Number(p.lat), Number(p.lon)]); };
    if (!full) {
      if (!state.nearbyFocused && data.centre) {
        const c = data.centre, r = data.radius || 500;
        points.push([c.lat-r/111000, c.lon-r/(111000*Math.cos(c.lat*Math.PI/180))], [c.lat+r/111000, c.lon+r/(111000*Math.cos(c.lat*Math.PI/180))]);
      }
      extend(state.userLocation); extend(data.stop); extend(data.onboard?.nextStop); extend(data.place);
      if (data.walk?.available) points.push(...data.walk.geometry);
      if (data.vehicle?.position && data.centre && map.distance([data.centre.lat, data.centre.lon], [data.vehicle.position.lat, data.vehicle.position.lon]) < 1500) extend(data.vehicle.position);
    }
    if (!points.length) return;
    state.nearbyCamera = full ? 'full' : 'local'; state.programmaticView = true;
    const height = $('nearby-bus').getBoundingClientRect().height;
    map.fitBounds(L.latLngBounds(points), { paddingTopLeft: state.mapOnly ? [30, mapOnlyTop()] : [isMobile() ? 35 : (state.panelOpen ? 425 : 40), 95],
      paddingBottomRight: state.mapOnly ? [35, 80] : [75, Math.min(map.getSize().y * .42, height + (isMobile() ? 115 : 55))], maxZoom: full ? 16 : 17,
      animate: !reducedMotion, duration: .5 });
    clearTimeout(state.nearbyCameraTimer);
    state.nearbyCameraTimer = setTimeout(() => { state.programmaticView = false; }, 600);
  }

  function drawNearbyLabels() {
    if (!state.nearbyLabelLayers) state.nearbyLabelLayers = L.layerGroup().addTo(map);
    state.nearbyLabelLayers.clearLayers(); state.nearbyCountdownNodes = [];
    const data = state.nearbyBusData; if (!data || !nearbyBusEnabled() || (state.pickField && !state.mapOnly)) return;
    const services = [...(data.services || [])].sort((a, b) => Number(b.key === data.departure?.key) - Number(a.key === data.departure?.key));
    const occupied = [], size = map.getSize(), bottom = size.y - (state.mapOnly ? 80 : isMobile() ? 215 : 150);
    const origin = $('map').getBoundingClientRect();
    for (const el of document.querySelectorAll('#nearby-bus,.map-controls,#journey-panel,#leave-widget,#station-guidance,.nearby-boarding-label,.gps-marker,.gps-status,#map-warning,#map-hint,#map-toast,.topbar,.panel-launcher,.follow-control')) {
      const r = el.getBoundingClientRect();
      if (r.width && r.height) occupied.push({x:r.left-origin.left-6,y:r.top-origin.top-6,w:r.width+12,h:r.height+12});
    }
    let placed = 0;
    for (const service of services) {
      if (placed >= 6) break;
      if (!service.stop) continue;
      const p = map.latLngToContainerPoint([service.stop.lat, service.stop.lon]);
      if (p.x < 0 || p.x > size.x || p.y < 60 || p.y > bottom + 60) continue;
      const left = p.x + 218 > size.x - 65, x = Math.max(8, Math.min(p.x + 17, size.x - 62 - 194));
      let offset = null;
      for (const dy of [-76, 0, 76, -152, 152, -228, 228]) {
        const rect = { x, y: p.y - 22 + dy, w: 194, h: 62 };
        if (rect.x < 8 || rect.x + rect.w > size.x - 58 || rect.y < (state.mapOnly ? 15 : 85) || rect.y + rect.h > bottom) continue;
        if (occupied.some(r => rect.x < r.x + r.w + 4 && rect.x + rect.w + 4 > r.x && rect.y < r.y + r.h + 4 && rect.y + rect.h + 4 > r.y)) continue;
        offset = dy; occupied.push(rect); placed++; break;
      }
      if (offset === null) continue;
      const body = styleRoute(make('div', `nearby-map-anchor${left ? ' is-left' : ''}`), service);
      body.append(make('span', 'nearby-stop-dot'));
      const button = nearbyButton('', () => selectNearbyService(service), 'nearby-map-option');
      button.style.top = `${offset}px`; button.style.left = `${x - p.x + 22}px`; button.style.right = 'auto'; button.setAttribute('aria-pressed', String(service.key === data.departure?.key));
      button.setAttribute('aria-label', `${service.mode} ${service.routeName} to ${service.headsign}, ${service.stop.name}. Show boarding stop and route`);
      const line = make('span', 'nearby-map-line');
      line.append(make('b', 'nearby-route-badge', serviceLabel(service)), make('span', 'nearby-map-destination', `→ ${service.headsign || service.destination}`));
      const counter = make('b', 'nearby-map-minutes'); line.append(counter); state.nearbyCountdownNodes.push({ node: counter, service });
      button.append(line, make('small', '', `${service.stop.code ? `Stop ${service.stop.code} · ` : ''}${service.stop.name}`)); body.append(button);
      if (offset) { const leader = make('span', 'nearby-label-leader'); leader.style.height = `${Math.abs(offset)}px`; leader.style.top = `${Math.min(0, offset) + 22}px`; body.append(leader); }
      L.marker([service.stop.lat, service.stop.lon], { icon: L.divIcon({ html: body, className: 'nearby-label-marker', iconSize: [44, 44], iconAnchor: [22, 22] }),
        keyboard: false, zIndexOffset: service.key === data.departure?.key ? 2200 : 1800, bubblingMouseEvents: false }).addTo(state.nearbyLabelLayers);
    }
    state.nearbyCountdownNodes.push(...[...$('nearby-bus').querySelectorAll('.nearby-direction')].map(row => row.nearbyCounter).filter(Boolean));
    updateNearbyBusCountdown();
  }

  function stopNearbyTransition() {
    if (state.nearbyTransition) cancelAnimationFrame(state.nearbyTransition);
    state.nearbyTransition = null;
    if (state.nearbyRetiringLayers) map.removeLayer(state.nearbyRetiringLayers);
    state.nearbyRetiringLayers = null;
  }

  function transitionNearbyRoutes(previous) {
    if (!previous || reducedMotion) { if (previous) map.removeLayer(previous); return; }
    state.nearbyRetiringLayers = previous;
    const paths = (group, incoming) => group.getLayers().filter(l => l.setStyle).map(layer => ({layer,incoming,opacity:layer.options.opacity ?? 1,fillOpacity:layer.options.fillOpacity ?? .2}));
    const items = [...paths(previous,false),...paths(state.nearbyBusLayers,true)];
    const start = performance.now();
    const tick = now => {
      const t = Math.min(1,(now-start)/220);
      for (const p of items) { const f=p.incoming?t:1-t;p.layer.setStyle({opacity:p.opacity*f,fillOpacity:p.fillOpacity*f}); }
      if(t<1) state.nearbyTransition=requestAnimationFrame(tick);
      else { map.removeLayer(previous);state.nearbyRetiringLayers=null;state.nearbyTransition=null; }
    };
    tick(start);
  }

  function updateNearbyVehicle(data) {
    const vehicle=data.vehicle;
    const id=vehicle?.id || vehicle?.tripId;
    if(!vehicle?.position || !Number.isFinite(vehicle.position.lat) || !Number.isFinite(vehicle.position.lon)) {
      if(state.nearbyBusMarker){if(state.nearbyBusMarker.nextstopAnimation)cancelAnimationFrame(state.nearbyBusMarker.nextstopAnimation);map.removeLayer(state.nearbyBusMarker);state.nearbyBusMarker=null;}return;
    }
    if(!state.nearbyBusMarker || state.nearbyBusMarker.nextstopNearbyId!==id){
      if(state.nearbyBusMarker){if(state.nearbyBusMarker.nextstopAnimation)cancelAnimationFrame(state.nearbyBusMarker.nextstopAnimation);map.removeLayer(state.nearbyBusMarker);}
      state.nearbyBusMarker=L.marker([vehicle.position.lat,vehicle.position.lon],{icon:vehicleIcon(vehicle,state.nearbyFocused),zIndexOffset:3500,bubblingMouseEvents:false}).addTo(map).bindPopup(servicePopup(vehicle,true));
      state.nearbyBusMarker.nextstopNearbyId=id;
    }
    const iconKey=JSON.stringify([vehicle.routeName,vehicle.mode,vehicle.routeColor,vehicle.routeTextColor,state.nearbyFocused]);
    if(state.nearbyBusMarker.nextstopNearbyIcon!==iconKey){state.nearbyBusMarker.setIcon(vehicleIcon(vehicle,state.nearbyFocused));state.nearbyBusMarker.nextstopNearbyIcon=iconKey;}
    moveMarker(state.nearbyBusMarker,{...vehicle,...vehicle.position},vehicle.generatedAt);
    const duplicate=state.markers.get(String(id));
    if(duplicate){if(duplicate.nextstopAnimation)cancelAnimationFrame(duplicate.nextstopAnimation);map.removeLayer(duplicate);state.markers.delete(String(id));}
  }

  function renderNearbyBus(data) {
    if (!nearbyBusEnabled()) { clearNearbyBus(); return; }
    const centre = data.centre || state.userLocation, radius = data.radius || 500;
    if (!state.onboard && map.distance([centre.lat, centre.lon], [state.userLocation.lat, state.userLocation.lon]) > 25) return;
    state.nearbyBusData = data;
    const renderKey=JSON.stringify([data.services,data.stop,data.departure,data.geometry,data.walk,data.onboard,data.place,data.centre,data.radius,state.nearbyFocused]);
    if(renderKey===state.nearbyRenderKey){
      updateNearbyVehicle(data);updateNearbyBusCountdown();renderMapOnlyServices();
      if(state.nearbyFitPending){state.nearbyFitPending=false;fitNearbyView(false);}return;
    }
    state.nearbyRenderKey=renderKey;
    if (!map.getPane('nearbyBusRoutes')) { map.createPane('nearbyBusRoutes'); map.getPane('nearbyBusRoutes').style.zIndex = '450'; map.getPane('nearbyBusRoutes').style.pointerEvents = 'none'; }
    stopNearbyTransition();
    const previousLayers=state.nearbyBusLayers;
    state.nearbyBusLayers = L.layerGroup().addTo(map);
    if (!state.nearbyAreaLayers) state.nearbyAreaLayers = L.layerGroup().addTo(map);
    state.nearbyAreaLayers.clearLayers();
    const context = data.onboard, bus = data.departure, card = $('nearby-bus'); card.replaceChildren(); card.hidden = false;
    document.body.classList.add('has-nearby-bus');
    document.body.classList.toggle('nearby-focused', Boolean(state.nearbyFocused || state.onboard));
    drawNetworkLines();
    document.body.classList.remove('mascot-visible'); $('mascot-peek').hidden = true;
    if (!context?.needsStopChoice) {
      L.circle([centre.lat, centre.lon], { radius, color: '#628746', weight: 1.5, dashArray: '5 7', opacity: .65, fillColor: '#9acb71', fillOpacity: .055, interactive: false, className: 'nearby-search-area' }).addTo(state.nearbyAreaLayers);
      L.marker([centre.lat + radius / 111000, centre.lon], { icon: L.divIcon({ html: make('span', 'nearby-radius-label', `${radius} m${context ? ' from alighting stop' : ' search'}`), className: 'nearby-radius-marker', iconSize: [160, 25], iconAnchor: [80, 13] }), interactive: false, keyboard: false }).addTo(state.nearbyAreaLayers);
    }
    const controls = make('div', 'nearby-controls');
    if (context) {
      card.append(make('strong', 'nearby-onboard-title', `On ${context.trip.routeName} → ${context.trip.headsign}`));
      card.append(make('span', 'nearby-stop', context.nextStop ? `Alight: ${context.nextStop.name}` : context.message));
      const label = make('label', 'nearby-alight-choice', 'Alighting stop '), select = make('select'); select.setAttribute('aria-label', 'Choose alighting stop');
      select.append(make('option', '', 'Automatic next stop')); select.firstChild.value = '0';
      for (const stop of context.choices || []) { const option = make('option', '', stop.name); option.value = String(stop.sequence); option.selected = state.onboard?.alight === stop.sequence; select.append(option); }
      select.addEventListener('change', () => { state.onboard.alight = Number(select.value); state.nearbyVerifyWalk = false; state.nearbyFitPending = true; fetchNearbyBus(true); }); label.append(select); label.dataset.alightChoice = ''; card.append(label);
      controls.append(nearbyButton("I'm off", leaveNearbyRide));
      if (context.nextStop) {
        L.marker([context.nextStop.lat, context.nextStop.lon], { icon: L.divIcon({ className: 'nearby-alight-pin', html: make('b', '', 'A'), iconSize: [32, 32], iconAnchor: [16, 16] }), title: `Alight here: ${context.nextStop.name}` })
          .bindTooltip(`Alight here · ${context.nextStop.name}`, { permanent: true, direction: 'top', className: 'nearby-boarding-label' }).addTo(state.nearbyBusLayers);
        if (context.geometry?.length > 1) L.polyline(context.geometry, { pane: 'nearbyBusRoutes', color: routeColor(context.trip), weight: 6, opacity: .9 }).addTo(state.nearbyBusLayers);
      }
    } else if (bus) card.append(make('strong', 'nearby-compact-title', `${bus.routeName} → ${bus.headsign}`));
    if (bus) {
      styleRoute(card, bus);
      card.append(make('span', 'nearby-stop', `${bus.stop?.code ? `Stop ${bus.stop.code} · ` : ''}${data.stop.name} · ${Math.round(data.stop.distance || 0)} m${context ? ' from alighting' : ' away'}`), make('span', 'nearby-countdown'));
      if (data.geometry?.length > 1) {
        L.polyline(data.geometry, { pane: 'nearbyBusRoutes', color: '#fff', weight: 8, opacity: .95, interactive: false }).addTo(state.nearbyBusLayers);
        L.polyline(data.geometry, { pane: 'nearbyBusRoutes', color: routeColor(bus), weight: 4, opacity: state.nearbyFocused ? 1 : .65, interactive: false }).addTo(state.nearbyBusLayers);
        controls.append(nearbyButton('Show full route', () => fitNearbyView(true)), nearbyButton('Back to stop', () => fitNearbyView(false)));
      }
      L.circleMarker([data.stop.lat, data.stop.lon], { radius: 8, color: '#14201d', weight: 2, fillColor: '#c2f65b', fillOpacity: 1, className: 'nearby-boarding-dot', interactive: false })
        .bindTooltip(`Board here · ${data.stop.name}`, { permanent: false, direction: 'bottom', className: 'nearby-boarding-label' }).addTo(state.nearbyBusLayers);
      if (!state.onboard) controls.append(nearbyButton("I'm on this service", () => {
        state.onboard = { trip: bus.tripId, date: bus.serviceDate, routeName: bus.routeName, after: 0, alight: 0 };
        state.nearbyServiceKey = ''; state.nearbyBusStop = ''; state.nearbyVerifyWalk = false; state.nearbyFitPending = true; state.nearbyCamera = 'local'; fetchNearbyBus(true);
      }));
      if (!data.walk && state.nearbyFocused) controls.append(nearbyButton('Show walk', () => { state.nearbyVerifyWalk = true; fetchNearbyBus(true); }));
      if ([2, 3].includes(Number(bus.pickupType))) card.append(make('small', '', 'Booking or driver arrangement may be required'));
    } else if (!context?.needsStopChoice && !data.place) card.append(make('span', '', data.message || 'No nearby departures.'));
    if (data.walk) {
      if (data.walk.available) {
        const walk = data.walk;
        card.append(make('span', 'nearby-walk-summary', `${context ? 'Alight → ' : ''}${walk.duration ? `walk ${minutes(walk.duration)} min · ${Math.round(walk.distance)} m` : 'Same boarding stop'}${data.place ? ` → ${data.place.name}` : bus ? ` → ${bus.routeName} to ${bus.headsign}` : ' · no reachable departure'}`));
        if (walk.geometry?.length > 1) L.polyline(walk.geometry, { pane: 'nearbyBusRoutes', color: '#14201d', weight: 4, dashArray: '3 8', className: 'nearby-walking-path', interactive: false }).addTo(state.nearbyBusLayers);
        for (const connector of walk.accessConnectors || []) L.polyline(connector.geometry, { pane: 'nearbyBusRoutes', color: '#8d7960', weight: 2, dashArray: '2 6', interactive: false }).bindTooltip('Unverified short access connection').addTo(state.nearbyBusLayers);
      } else card.append(make('span', 'nearby-walk-summary', data.walk.message));
    } else if (context?.nextStop) card.append(make('small', '', 'After alighting · tap a stop to check the walk and connection.'));
    if (data.place) {
      L.marker([data.place.lat, data.place.lon], { title: data.place.name }).bindTooltip(data.place.name, { permanent: true }).addTo(state.nearbyBusLayers);
      controls.append(nearbyButton('Nearby services', () => { state.onboard.place = null; fetchNearbyBus(true); }));
    }
    updateNearbyVehicle(data);
    transitionNearbyRoutes(previousLayers);
    card.append(controls);
    const details = make('details', 'nearby-more'); details.open = state.nearbyDetailsOpen || false;
    details.addEventListener('toggle', () => { state.nearbyDetailsOpen = details.open; });
    details.append(make('summary', '', `Nearby options · ${(data.services || []).length} directions`));
    const list = make('div', 'nearby-directions');
    for (const service of data.services || []) {
      const row = styleRoute(nearbyButton('', () => selectNearbyService(service), 'nearby-direction'), service);
      row.setAttribute('aria-pressed', String(service.key === bus?.key));
      const counter = make('b', 'nearby-list-minutes');
      row.append(make('strong', '', `${service.mode} ${service.routeName} → ${service.headsign}`), make('span', '', `${service.stopName || service.stop.name} · ${Math.round(service.stop.distance)} m`), counter);
      list.append(row); // Countdown nodes are populated after map label placement.
      row.nearbyCounter = { node: counter, service };
    }
    details.append(list);
    const alightChoice = card.querySelector('[data-alight-choice]');
    if (alightChoice && !context?.needsStopChoice) details.prepend(alightChoice);
    if (bus) details.append(timetableButton('View all stops', tripQuery(bus), 'nearby-action'));
    if (data.nearbyStops?.length > 1 && !context) {
      const label = make('label', 'nearby-stop-switch', 'Nearby stop '), select = make('select'); select.setAttribute('aria-label', 'Nearby stop');
      for (const stop of data.nearbyStops) { const option = make('option', '', `${stop.name} (${Math.round(stop.distance)} m)`); option.value = stop.id; option.selected = stop.id === data.stop?.id; select.append(option); }
      select.addEventListener('change', () => { state.nearbyBusStop = select.value; state.nearbyServiceKey = ''; state.nearbyVerifyWalk = true; state.nearbyFitPending = true; fetchNearbyBus(true); }); label.append(select); details.append(label);
    }
    details.append(make('small', 'nearby-note', 'Scheduled estimates · delays and cancellations unknown. Access requires a checked walking route.'));
    card.append(details); drawNearbyLabels();
    updateNearbyBusCountdown();
    renderMapOnlyServices();
    if (context && state.onboard) state.onboard.after = Math.max(state.onboard.after || 0, context.passedSequence || 0);
    if (state.nearbyFitPending) { state.nearbyFitPending = false; fitNearbyView(false); }
    else if (state.nearbyCamera === 'local' && !map.getBounds().pad(-.2).contains([centre.lat, centre.lon])) fitNearbyView(false);
  }

  function updateNearbyBusCountdown() {
    const data = state.nearbyBusData; if (!data) return;
    if (!nearbyBusEnabled()) { clearNearbyBus(); return; }
    if (Date.now() - timeValue(data.generatedAt) > 45000) { clearNearbyBus(false); if(!state.nearbyBusController&&!document.hidden)fetchNearbyBus(true);return; }
    const text = service => timeValue(service.departure) < Date.now() ? 'Left' : timeValue(service.arrival) <= Date.now() ? 'Due' : `${Math.ceil((timeValue(service.arrival) - Date.now()) / 60000)} min`;
    for (const { node, service } of state.nearbyCountdownNodes || []) node.textContent = text(service);
    const bus = data.departure, node = $('nearby-bus').querySelector('.nearby-countdown');
    if (bus && node) node.textContent = `${text(bus)} · scheduled ${clock(bus.arrival)}${bus.accessVerified ? ' · walk checked' : ''}`;
    if (bus && timeValue(bus.departure) < Date.now() && !state.nearbyBusController && Date.now()-state.nearbyBusFetched>3000 && !document.hidden) fetchNearbyBus(true);
  }

  async function fetchNearbyBus(force = false) {
    if (!map || document.hidden) return;
    if (!state.selected && state.locationConsent && state.userLocation && Date.now() - state.userLocation.timestamp > 45000 && navigator.geolocation && !state.nearbyGpsRefresh) {
      state.nearbyGpsRefresh = true;
      navigator.geolocation.getCurrentPosition(position => { state.nearbyGpsRefresh = false; updateUserLocation(position); }, error => { state.nearbyGpsRefresh = false; handleLocationError(error); }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    }
    if (!nearbyBusEnabled()) { clearNearbyBus(); return; }
    const p = state.userLocation;
    const moved = !state.nearbyBusPoint || map.distance([p.lat, p.lon], [state.nearbyBusPoint.lat, state.nearbyBusPoint.lon]) > 25;
    if(!force && !moved && state.nearbyBusController)return;
    if (!state.onboard && state.nearbyBusData?.centre && map.distance([p.lat, p.lon], [state.nearbyBusData.centre.lat, state.nearbyBusData.centre.lon]) > 25) clearNearbyBus();
    if (!force && Date.now() - state.nearbyBusFetched < (moved ? 2000 : 14000)) return;
    if (state.nearbyBusController) state.nearbyBusController.abort();
    const controller = new AbortController(); state.nearbyBusController = controller;
    const request = ++state.nearbyBusRequest;
    state.nearbyBusFetched = Date.now(); state.nearbyBusPoint = p;
    const params = { at: `${p.lat},${p.lon}`, stop: state.nearbyBusStop, service: state.nearbyServiceKey, walk: state.nearbyVerifyWalk ? '1' : '0' };
    if (state.onboard) {
      Object.assign(params, { trip: state.onboard.trip, date: state.onboard.date, after: state.onboard.after, alight: state.onboard.alight });
      if (state.onboard.place) Object.assign(params, { to: `${state.onboard.place.lat},${state.onboard.place.lon}`, name: state.onboard.place.name });
    }
    try {
      const data = await api('nearby-bus', params, controller.signal);
      if (request !== state.nearbyBusRequest || !nearbyBusEnabled()) return;
      if (map.distance([p.lat, p.lon], [state.userLocation.lat, state.userLocation.lon]) > 25) return;
      renderNearbyBus(data);
    } catch (error) {
      if (error.name === 'AbortError' || request !== state.nearbyBusRequest) return;
      clearNearbyBus();
      if (state.onboard?.alight && /alighting stop ahead/.test(error.message || '')) {
        state.onboard.alight = 0; state.nearbyVerifyWalk = false; state.nearbyServiceKey = '';
        mapToast('That stop has passed. Showing the next alighting stop.'); fetchNearbyBus(true); return;
      }
      if (state.onboard) mapToast(error.message || 'Onboard options unavailable. Choose another stop or retry.', true);
    } finally { if (state.nearbyBusController === controller) state.nearbyBusController = null; }
  }

  function updateUserLocation(position) {
    const accuracy = Number(position.coords.accuracy);
    const point = { lat: Number(position.coords.latitude), lon: Number(position.coords.longitude), accuracy: Number.isFinite(accuracy) && accuracy > 0 ? accuracy : null, timestamp: Number(position.timestamp) || Date.now() };
    if (!Number.isFinite(point.lat) || !Number.isFinite(point.lon)) return false;
    const previous = state.userLocation;
    if (!previous && !state.selected) { state.nearbyCamera = 'local'; state.nearbyFitPending = true; }
    if (previous && point.timestamp < previous.timestamp) return false;
    if (previous && point.timestamp === previous.timestamp && point.accuracy !== null && previous.accuracy !== null && point.accuracy >= previous.accuracy && map.distance([previous.lat, previous.lon], [point.lat, point.lon]) < point.accuracy) return false;
    state.userLocation = point;
    clearTimeout(state.locationRetryTimer);
    state.locationRetryTimer = null;
    state.locationRetryCount = 0;
    $('gps-status').hidden = false;
    $('gps-status').textContent = point.accuracy === null ? 'GPS' : `±${Math.round(point.accuracy)} m`;
    $('gps-status').classList.toggle('is-coarse', point.accuracy !== null && point.accuracy > 100);
    $('map-locate').title = point.accuracy === null ? 'Phone GPS location' : `Phone GPS · accuracy about ${Math.round(point.accuracy)} metres`;
    const latlng = [point.lat, point.lon];
    if (!state.gpsMarker) {
      state.gpsMarker = L.marker(latlng, { icon: L.divIcon({ className: 'gps-marker', iconSize: [18, 18], iconAnchor: [9, 9] }), zIndexOffset: 5000, bubblingMouseEvents: false, title: 'Your phone location' }).addTo(map);
      state.gpsMarker.bindPopup(make('div', 'gps-popup', 'Your phone location'));
    } else state.gpsMarker.setLatLng(latlng);
    if (state.accuracyCircle) map.removeLayer(state.accuracyCircle);
    if (point.accuracy !== null && point.accuracy < 2000) state.accuracyCircle = L.circle(latlng, { radius: point.accuracy, color: '#4689d0', weight: 1, opacity: .2, fillOpacity: .05, interactive: false }).addTo(map);
    state.gpsMarker.setPopupContent(make('div', 'gps-popup', point.accuracy === null ? 'Your phone location' : `GPS accuracy about ${Math.round(point.accuracy)} m`));
    if (!state.selected && state.origin && (state.origin.name === 'Your location' || (state.awaitingGpsOrigin && !state.originUserChosen))) {
      const oldPoint = state.origin;
      const nameChanged = oldPoint.name !== 'Your location';
      state.origin = { lat: point.lat, lon: point.lon, name: 'Your location' };
      state.awaitingGpsOrigin = false;
      $('origin-input').value = 'Your location';
      if (nameChanged) updatePlaceMarker('origin');
      else if (state.placeMarkers.origin) state.placeMarkers.origin.setLatLng(latlng);
      if (state.planController && map.distance([oldPoint.lat, oldPoint.lon], latlng) > 25) { cancelPendingPlan(); maybeAutoPlan(); }
    }
    if (state.guidance) fitStationGuidance(false);
    else if (state.selected && state.followJourney && !state.pickField) fitJourneyBounds(state.selected, false);
    maybeAutoGuidance();
    fetchNearbyBus(!previous || previous.accuracy === null || previous.accuracy > 80 || Date.now() - previous.timestamp >= 60000);
    return true;
  }

  function stopLocationWatch() {
    if (state.locationWatch !== null && navigator.geolocation) navigator.geolocation.clearWatch(state.locationWatch);
    state.locationWatch = null;
    clearTimeout(state.locationRetryTimer);
    state.locationRetryTimer = null;
  }

  function handleLocationError(error) {
    $('gps-status').hidden = false;
    if (error.code === 1) {
      state.locationConsent = false;
      clearNearbyBus();
      stopLocationWatch();
      $('gps-status').textContent = 'GPS blocked';
      mapToast('Location is blocked. Enable it in your browser.', true, { label: 'Retry', run: locate });
      return;
    }
    $('gps-status').textContent = 'Finding GPS…';
    if (state.locationConsent && !document.hidden && !state.locationRetryTimer) {
      const delay = Math.min(30000, 3000 * (2 ** state.locationRetryCount++));
      state.locationRetryTimer = setTimeout(() => {
        state.locationRetryTimer = null;
        if (state.locationConsent && !document.hidden) navigator.geolocation.getCurrentPosition(updateUserLocation, handleLocationError, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
      }, delay);
    }
  }

  function startLocationWatch() {
    if (!state.locationConsent || document.hidden || state.locationWatch !== null || !navigator.geolocation) return;
    state.locationWatch = navigator.geolocation.watchPosition(updateUserLocation, handleLocationError, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
  }

  function setFollow(enabled) {
    if (enabled) state.fullJourneyOverview = false;
    if (enabled && state.firstWalkFocus) {
      state.firstWalkFocus = false;
      document.body.classList.remove('walk-to-stop-active');
      if (state.firstWalkLayers) state.firstWalkLayers.clearLayers();
      if (state.selected && !state.guidance && !map.hasLayer(journeyLayers)) journeyLayers.addTo(map);
      setPanel(state.panelOpen);
    }
    state.followJourney = Boolean(enabled);
    if (!state.followJourney) state.fitAfterZoom = false;
    $('follow-journey').setAttribute('aria-pressed', String(state.followJourney));
    $('follow-journey').querySelector('span').textContent = state.firstWalkFocus ? 'Show full journey' : state.followJourney ? 'Following journey' : 'Recenter journey';
    if (state.followJourney && state.selected) { state.lastFollowFit = null; fitJourneyBounds(state.selected); }
    renderLastMileUber();
  }

  function pauseMapFollowing() {
    state.nearbyCamera = null; state.nearbyPendingCamera = null;
    if (state.guidance) { state.guidance.following = false; state.guidance.fitPending = false; }
    if (state.selected) setFollow(false);
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
    $('gps-status').hidden = false;
    $('gps-status').textContent = 'Finding GPS…';
    state.nearbyCamera = 'local'; state.nearbyFitPending = true;
    navigator.geolocation.getCurrentPosition(position => {
      $('use-location').disabled = false;
      state.locationConsent = true;
      updateUserLocation(position);
      if (!state.userLocation) { handleLocationError({ code: 2 }); return; }
      const { lat, lon } = state.userLocation;
      if (state.guidance) { state.guidance.following = true; fitStationGuidance(); }
      else if (state.selected) setFollow(true);
      else {
        setPlace('origin', { lat, lon, name: 'Your location' }, true);
        map.setZoom(15);
      }
      startLocationWatch();
    }, error => {
      $('use-location').disabled = false;
      if (error.code !== 1) { state.locationConsent = true; startLocationWatch(); }
      handleLocationError(error);
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
  }

  function phoneSourceLine(data) {
    const attribution = String(data.attribution || data.source || 'Published payphone locations');
    const line = make('small', 'phone-source', `${attribution}${data.stale ? ' · cached locations' : ''}`);
    try {
      const url = new URL(data.sourceUrl);
      if (url.protocol === 'https:' && !url.username && !url.password) {
        const link = make('a', '', 'Source register');
        link.href = url.href;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        line.append(document.createTextNode(' · '), link);
      }
    } catch { /* Source text remains available when no valid HTTPS source link is supplied. */ }
    return line;
  }

  function toiletFreshness(data) {
    const time = Number.isFinite(timeValue(data.updatedAt)) ? new Intl.DateTimeFormat('en-AU', { timeZone: 'Australia/Perth', hour: '2-digit', minute: '2-digit', day: 'numeric', month: 'short' }).format(timeValue(data.updatedAt)) : 'unknown';
    return `${data.stale ? 'Saved data · refresh unavailable' : 'Source fetched'} ${time} · cache up to 2 min`;
  }

  function toiletPopupOptions() {
    return { maxWidth: Math.min(300, window.innerWidth - 80), maxHeight: 300,
      autoPanPaddingTopLeft: L.point(16, isMobile() ? 130 : 90),
      autoPanPaddingBottomRight: L.point(70, isMobile() ? 180 : 45) };
  }

  function toiletPopup(toilet, metadata, details = false) {
    const popup = make('div', 'toilet-popup');
    popup.append(make('strong', '', toilet.name), make('span', '', toilet.address || toilet.type));
    if (toilet.listedClosed) popup.append(make('strong', 'toilet-closed', 'Listed as closed by the source'));
    popup.append(make('span', 'toilet-hours', `Published hours: ${toilet.hours}`));
    if (toilet.amenities?.length) popup.append(make('span', '', toilet.amenities.join(' · ')));
    for (const note of toilet.notes || []) popup.append(make('span', '', `${note.label}: ${note.text}`));
    if (toilet.operator) popup.append(make('span', '', `Managed by ${toilet.operator}`));
    if (toilet.contactPhone) popup.append(make('span', '', `Public contact: ${toilet.contactPhone}`));
    if (!details) popup.append(make('small', 'toilet-detail-state', 'Loading access and facility notes…'));
    const source = make('a', '', 'National Public Toilet Map · official record'); source.href = toilet.sourceUrl; source.target = '_blank'; source.rel = 'noopener noreferrer';
    popup.append(source, make('small', 'toilet-freshness', toiletFreshness(metadata)), make('small', '', 'Published information, not live occupancy or guaranteed access. © Commonwealth of Australia.'));
    return popup;
  }

  async function loadToiletDetails(marker, toilet) {
    if (state.toiletDetailController) state.toiletDetailController.abort();
    const controller = new AbortController(); state.toiletDetailController = controller;
    try {
      const data = await api('toilet', { id: toilet.id }, controller.signal);
      if (state.toiletsEnabled && marker.isPopupOpen() && data.toilet) marker.setPopupContent(toiletPopup(data.toilet, data, true));
    } catch (error) {
      if (error.name === 'AbortError') return;
      const node = marker.getPopup()?.getElement()?.querySelector('.toilet-detail-state');
      if (node) node.textContent = 'Extra details unavailable · use the official record link';
    }
  }

  function renderToilets() {
    if (!state.toiletsEnabled || !state.toiletMetadata) return;
    if (!state.toiletLayers) state.toiletLayers = L.layerGroup().addTo(map);
    const zoom = map.getZoom(), groups = new Map(), retained = new Set();
    for (const toilet of state.toilets) {
      const point = map.project([toilet.lat, toilet.lon], zoom);
      const key = zoom < 16 ? `cluster:${Math.floor(point.x / 55)}:${Math.floor(point.y / 55)}` : `location:${toilet.lat.toFixed(5)}:${toilet.lon.toFixed(5)}`;
      if (!groups.has(key)) groups.set(key, []); groups.get(key).push(toilet);
    }
    for (const [key, items] of groups) {
      retained.add(key);
      const clustered = items.length > 1;
      const lat = items.reduce((sum, t) => sum + t.lat, 0) / items.length, lon = items.reduce((sum, t) => sum + t.lon, 0) / items.length;
      const symbol = make('div', 'toilet-marker-body', clustered ? `WC ${items.length}` : 'WC');
      const markerIcon = L.divIcon({ html: symbol, className: clustered ? 'toilet-cluster' : 'toilet-marker', iconSize: [44, 44], iconAnchor: [22, 22] });
      let marker = state.toiletMarkers.get(key);
      const signature = items.map(t => t.id).sort().join(',');
      if (marker && marker.toiletSignature !== signature) { state.toiletLayers.removeLayer(marker); state.toiletMarkers.delete(key); marker = null; }
      if (!marker) {
        marker = L.marker([lat, lon], { icon: markerIcon, bubblingMouseEvents: false, zIndexOffset: 1200, title: clustered ? `${items.length} toilet locations · ${zoom < 16 ? 'zoom to view' : 'choose facility'}` : items[0].name }).addTo(state.toiletLayers);
        marker.toiletSignature = signature;
        state.toiletMarkers.set(key, marker);
        if (clustered && zoom < 16) marker.on('click', () => { pauseMapFollowing(); map.fitBounds(L.latLngBounds(items.map(t => [t.lat, t.lon])).pad(.15), { padding: [55, 150], maxZoom: 18 }); });
        else if (clustered) {
          const choices = () => {
            const box = make('div', 'toilet-popup'); box.append(make('strong', '', 'Toilet facilities at this location'));
            for (const toilet of items) {
              const button = make('button', 'nearby-action', toilet.name); button.type = 'button';
              button.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); marker.setPopupContent(toiletPopup(toilet, state.toiletMetadata)); loadToiletDetails(marker, toilet); }); box.append(button);
            }
            return box;
          };
          marker.bindPopup(choices(), toiletPopupOptions());
          marker.on('popupclose', () => marker.setPopupContent(choices()));
        }
        else {
          marker.bindPopup(toiletPopup(items[0], state.toiletMetadata), toiletPopupOptions());
          marker.on('popupopen', () => loadToiletDetails(marker, items[0]));
        }
      } else {
        marker.setIcon(markerIcon);
        if (!marker.isPopupOpen()) marker.setLatLng([lat, lon]);
        if (!clustered && !marker.isPopupOpen()) marker.setPopupContent(toiletPopup(items[0], state.toiletMetadata));
        const freshness = marker.getPopup()?.getElement()?.querySelector('.toilet-freshness');
        if (freshness) freshness.textContent = toiletFreshness(state.toiletMetadata);
      }
    }
    state.toiletMarkers.forEach((marker, key) => { if (!retained.has(key)) { state.toiletLayers.removeLayer(marker); state.toiletMarkers.delete(key); } });
  }

  async function fetchToilets(announce = false) {
    if (!map || !state.toiletsEnabled || document.hidden) return;
    if (state.toiletController) state.toiletController.abort();
    const controller = new AbortController(); state.toiletController = controller;
    const request = ++state.toiletRequest;
    const b = map.getBounds();
    try {
      const data = await api('toilets', { bbox: [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].join(',') }, controller.signal);
      if (!state.toiletsEnabled || request !== state.toiletRequest) return;
      state.toiletMetadata = data;
      state.toilets = (data.toilets || []).filter(t => /^\d+$/.test(String(t.id)) && Number.isFinite(t.lat) && Number.isFinite(t.lon));
      renderToilets();
      $('map-toilets').title = `${state.toilets.length} nearby toilet locations · ${toiletFreshness(data)}`;
      $('toilet-count').hidden = false; $('toilet-count').textContent = String(state.toilets.length);
      if (announce || data.stale) mapToast(data.stale ? 'Toilets: showing saved data · live refresh unavailable' : `${state.toilets.length} nearby toilet locations · live source, cached up to 2 min${data.limited ? ' · pan or zoom for more' : ''}`);
    } catch (error) {
      if (error.name === 'AbortError' || !state.toiletsEnabled || request !== state.toiletRequest) return;
      if (state.toiletMetadata) { state.toiletMetadata.stale = true; renderToilets(); }
      $('map-toilets').title = 'Public toilets · source unavailable';
      mapToast('Toilet source unavailable. Saved locations, if shown, may be out of date.', true, { label: 'Retry', run: () => fetchToilets(true) });
    }
  }

  function toggleToilets() {
    state.toiletsEnabled = !state.toiletsEnabled;
    $('map-toilets').setAttribute('aria-pressed', String(state.toiletsEnabled));
    $('map-toilets').setAttribute('aria-label', state.toiletsEnabled ? 'Hide public toilets' : 'Show public toilets');
    if (state.toiletsEnabled) fetchToilets(true);
    else {
      state.toiletRequest++;
      if (state.toiletController) state.toiletController.abort();
      if (state.toiletDetailController) state.toiletDetailController.abort();
      clearTimeout(state.toiletMoveTimer);
      if (state.toiletLayers) state.toiletLayers.clearLayers(); state.toiletMarkers.clear();
      $('toilet-count').hidden = true;
    }
  }

  function renderPhones() {
    if (!state.phoneLayers) return;
    state.phoneLayers.clearLayers();
    if (!state.phonesEnabled || !state.phoneMetadata) return;
    const zoom = map.getZoom();
    const groups = new Map();
    state.phones.forEach(phone => {
      const point = map.project([phone.lat, phone.lon], zoom);
      const key = zoom < 16 ? `${Math.floor(point.x / 60)}:${Math.floor(point.y / 60)}` : String(phone.id);
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push({ phone, point });
    });
    groups.forEach(group => {
      const clustered = zoom < 16 && group.length > 1;
      const items = group.map(item => item.phone);
      const siteCount = items.length;
      const physicalCount = items.reduce((count, phone) => count + (Number.isInteger(Number(phone.phoneCount)) && Number(phone.phoneCount) > 0 ? Number(phone.phoneCount) : 0), 0);
      const center = clustered ? map.unproject(L.point(group.reduce((sum, item) => sum + item.point.x, 0) / siteCount, group.reduce((sum, item) => sum + item.point.y, 0) / siteCount), zoom) : [items[0].lat, items[0].lon];
      const symbol = make('div', `phone-marker-body${clustered ? ' phone-cluster-body' : ' phone-individual-body'}`);
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 24 24');
      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', 'm8 3 3 5-3 3c1 3 2 4 5 5l3-3 5 3-2 5C10 22 2 14 3 5l5-2Z');
      svg.append(path); symbol.append(svg);
      if (clustered) symbol.append(make('span', 'phone-cluster-count', String(siteCount)));
      const marker = L.marker(center, {
        icon: L.divIcon({ html: symbol, className: clustered ? 'phone-cluster' : 'phone-marker', iconSize: [44, 44], iconAnchor: [22, 22] }),
        zIndexOffset: 100, bubblingMouseEvents: false,
        title: clustered ? `${siteCount} phone locations · zoom to view` : items[0].name || 'Public phone'
      }).addTo(state.phoneLayers);
      const element = marker.getElement();
      if (element) {
        element.dataset.locationCount = String(siteCount);
        if (physicalCount) element.dataset.phoneCount = String(physicalCount);
        if (clustered) element.setAttribute('aria-label', `${siteCount} phone locations${physicalCount ? `, ${physicalCount} phones` : ''}. Zoom to view.`);
      }
      if (clustered) {
        marker.bindTooltip(make('span', '', `${siteCount} phone locations${physicalCount ? ` · ${physicalCount} phones` : ''}`));
        marker.on('click', () => {
          if (state.selected) setFollow(false);
          if (isMobile()) setPanel(false);
          const bounds = L.latLngBounds(items.map(phone => [phone.lat, phone.lon]));
          map.fitBounds(bounds.pad(.15), { padding: [55, 155], maxZoom: 18, animate: !reducedMotion });
        });
      } else {
        const phone = items[0];
        const popup = make('div', 'phone-popup');
        popup.append(make('strong', '', phone.name || 'Public phone'));
        if (phone.operator) popup.append(make('span', '', phone.operator));
        if (phone.address) popup.append(make('span', '', phone.address));
        const quantity = Number(phone.phoneCount);
        if (Number.isInteger(quantity) && quantity > 0) popup.append(make('span', 'phone-quantity', `${quantity} ${quantity === 1 ? 'phone' : 'phones'} at this location`));
        popup.append(phoneSourceLine(state.phoneMetadata), make('small', '', 'Current availability not confirmed'));
        marker.bindPopup(popup);
      }
    });
  }

  async function fetchPhones(announce = false) {
    if (!state.phonesEnabled || !map) return;
    if (state.phonesController) state.phonesController.abort();
    state.phonesController = new AbortController();
    const request = ++state.phoneRequest;
    const bounds = map.getBounds();
    try {
      const data = await api('phones', { bbox: [bounds.getWest(), bounds.getSouth(), bounds.getEast(), bounds.getNorth()].join(',') }, state.phonesController.signal);
      if (!state.phonesEnabled || request !== state.phoneRequest) return;
      if (data.updatedAt === null) {
        state.phones = [];
        state.phoneMetadata = null;
        state.phoneLayers.clearLayers();
        $('phone-count').hidden = true;
        throw new Error('No confirmed public phone data is available.');
      }
      const phones = Array.isArray(data.phones) ? data.phones.filter(phone => Number.isFinite(Number(phone.lat)) && Number.isFinite(Number(phone.lon))) : [];
      const source = String(data.source || 'Published payphone locations');
      $('phone-source-note').replaceChildren(phoneSourceLine(data), make('span', '', ' Coverage and current availability can vary.'));
      state.phones = phones;
      state.phoneMetadata = data;
      renderPhones();
      $('phone-count').hidden = false;
      $('phone-count').textContent = phones.length > 99 ? '99+' : String(phones.length);
      $('map-phones').setAttribute('aria-label', `Hide public phones · ${phones.length} phone locations in this area`);
      $('map-phones').title = `${phones.length} phone locations · ${data.stale ? 'cached ' : ''}${source}`;
      const physicalPhones = Number(data.totalPhones);
      const total = Number.isInteger(physicalPhones) && physicalPhones > 0 ? ` · ${physicalPhones} ${physicalPhones === 1 ? 'phone' : 'phones'}` : '';
      if (announce) mapToast(phones.length ? `${phones.length} phone ${phones.length === 1 ? 'location' : 'locations'}${total}${data.stale ? ' · cached' : ''}` : data.stale ? 'No locations in the saved phone data for this area.' : 'No mapped phone locations in this area.');
    } catch (error) {
      if (error.name !== 'AbortError' && state.phonesEnabled) mapToast('Public phones couldn’t load.', true, { label: 'Retry', run: () => fetchPhones(true) });
    }
  }

  function togglePhones() {
    state.phonesEnabled = !state.phonesEnabled;
    $('map-phones').setAttribute('aria-pressed', String(state.phonesEnabled));
    if (state.phonesEnabled) fetchPhones(true);
    else {
      state.phoneRequest++;
      if (state.phonesController) state.phonesController.abort();
      state.phones = [];
      state.phoneMetadata = null;
      state.phoneLayers.clearLayers();
      $('phone-count').hidden = true;
      $('map-phones').setAttribute('aria-label', 'Show public phones');
      $('map-toast').hidden = true;
    }
  }

  function clearApproach() {
    if (state.approachController) state.approachController.abort();
    state.approachController = null;
    state.approachKey = '';
    state.approachData = null;
    if (state.approachMarker) {
      if (state.approachMarker.nextstopAnimation) cancelAnimationFrame(state.approachMarker.nextstopAnimation);
      map.removeLayer(state.approachMarker);
      state.approachMarker = null;
    }
    if (state.approachLayers) state.approachLayers.clearLayers();
  }

  function approachVehicle() {
    const data = state.approachData, leg = nextLeg();
    if (!data || !leg || String(data.tripId) !== String(leg.tripId)
      || (leg.serviceDate && data.serviceDate !== leg.serviceDate)
      || timeValue(leg.departure) < Date.now() || data.phase === 'departed'
      || !Number.isFinite(timeValue(data.generatedAt)) || Date.now() - timeValue(data.generatedAt) > 45000
      || !data.position || !Number.isFinite(Number(data.position.lat)) || !Number.isFinite(Number(data.position.lon))) return null;
    return { ...leg, ...data, ...data.position, id: `${data.serviceDate || leg.serviceDate}-${data.tripId}`,
      nextStop: data.stop && data.stop.name, estimated: true };
  }

  function renderApproachVehicle() {
    const vehicle = approachVehicle();
    if (map.getZoom() < STREET_ZOOM && !['train', 'ferry'].includes(vehicle?.mode)) return;
    if (!vehicle) return;
    // One highlighted marker per selected service, regardless of which request
    // finishes first or whether the larger fleet request succeeds.
    state.markers.forEach((marker, id) => {
      if (String(id) === String(vehicle.id)) {
        if (marker.nextstopAnimation) cancelAnimationFrame(marker.nextstopAnimation);
        map.removeLayer(marker); state.markers.delete(id);
      }
    });
    if (!state.approachMarker) {
      state.approachMarker = L.marker([vehicle.lat, vehicle.lon], { icon: vehicleIcon(vehicle, true), zIndexOffset: 4000, bubblingMouseEvents: false }).addTo(map);
      state.approachMarker.bindPopup(servicePopup(vehicle, true));
    } else {
      state.approachMarker.setIcon(vehicleIcon(vehicle, true));
      state.approachMarker.setPopupContent(servicePopup(vehicle, true));
    }
    const element = state.approachMarker.getElement();
    if (element) element.setAttribute('aria-label', `Your next ${vehicle.mode} ${vehicle.routeName}, ${vehicle.headsign || 'scheduled service'}, estimated position. Open for timetable.`);
    moveMarker(state.approachMarker, vehicle, vehicle.generatedAt);
  }

  function drawApproach(data, leg) {
    state.approachLayers.clearLayers();
    if (!Array.isArray(data.geometry) || data.geometry.length < 2 || timeValue(data.arrival) <= Date.now() || Number(data.secondsToStop) <= 0 || ['at-stop', 'departed'].includes(data.phase)) return;
    const options = { renderer: state.approachRenderer, interactive: false, lineCap: 'round', lineJoin: 'round' };
    L.polyline(data.geometry, { ...options, color: '#ffffff', weight: 9, opacity: .95 }).addTo(state.approachLayers);
    L.polyline(data.geometry, { ...options, color: routeColor(data.routeColor ? data : leg), weight: 5, opacity: .28 }).addTo(state.approachLayers);
    const waiting = data.phase ? data.phase === 'not-started' : Boolean(data.upcoming);
    L.polyline(data.geometry, { ...options, color: routeColor(data.routeColor ? data : leg), weight: 5, opacity: waiting ? .6 : 1, dashArray: '5 13', className: waiting ? 'incoming-route-waiting' : 'incoming-route-pulse' }).addTo(state.approachLayers);
  }

  async function fetchApproach() {
    if (!map || map.getZoom() < STREET_ZOOM) { clearApproach(); return; }
    const leg = nextLeg();
    if (!leg || !leg.from || !leg.from.id || timeValue(leg.departure) <= Date.now()) { clearApproach(); return; }
    const key = `${leg.tripId}:${leg.serviceDate || ''}:${leg.from.id}:${leg.departure || ''}`;
    if (state.approachKey && state.approachKey !== key) clearApproach();
    if (state.approachController || (state.approachKey === key && Date.now() - state.approachRequestedAt < 12000)) return;
    state.approachKey = key;
    state.approachRequestedAt = Date.now();
    const controller = new AbortController();
    state.approachController = controller;
    try {
      const data = await api('approach', { ...tripQuery(leg), stop: leg.from.id, boarding: leg.departure }, controller.signal);
      if (controller.signal.aborted || state.approachKey !== key || !nextLeg() || String(nextLeg().tripId) !== String(leg.tripId)) return;
      state.approachData = data;
      drawApproach(data, leg);
      renderApproachVehicle();
      updateBoardingCountdown();
      if (state.followJourney && state.selected) fitJourneyBounds(state.selected, false);
    } catch (error) {
      if (error.name !== 'AbortError' && state.approachKey === key) {
        // Keep a recent estimate through a transient error; stale estimates are
        // removed by the clock tick and never replace a fresh fleet position.
        state.approachLayers.clearLayers();
      }
    } finally { if (state.approachController === controller) state.approachController = null; }
  }

  function boardingCountdown(leg) {
    const arrival = state.approachData && String(state.approachData.tripId) === String(leg.tripId) ? timeValue(state.approachData.arrival) : timeValue(leg.departure);
    const until = Math.ceil((arrival - Date.now()) / 60000);
    if (!Number.isFinite(arrival)) return { value: '—', label: 'Scheduled' };
    if (until > 0) return { value: String(until), label: 'min to board' };
    return Date.now() < timeValue(leg.departure) ? { value: 'At stop', label: 'Scheduled' } : { value: 'On route', label: 'Scheduled' };
  }

  function updateBoardingCountdown() {
    const leg = nextLeg();
    const eta = $('boarding-eta');
    eta.hidden = !leg;
    if (leg) {
      const counter = boardingCountdown(leg);
      eta.textContent = counter.label === 'min to board' ? `${leg.mode === 'train' ? 'Train' : leg.mode === 'ferry' ? 'Ferry' : 'Bus'} arrives in ${counter.value} min` : counter.value;
    }
    const node = $('next-service').querySelector('.next-countdown');
    if (!leg || !node) return;
    const value = boardingCountdown(leg);
    if (node.dataset.value === `${value.value}:${value.label}`) return;
    node.replaceChildren(document.createTextNode(value.value), make('small', '', value.label));
    node.dataset.value = `${value.value}:${value.label}`;
  }

  function updateLeaveWidget() {
    const widget = $('leave-widget');
    const journey = state.selected;
    const firstRide = transitLegs()[0];
    const board = journey ? timeValue(journey.boardingAt || (firstRide && firstRide.departure)) : NaN;
    const departure = journey ? timeValue(journey.leaveAt) : NaN;
    const verified = Boolean(journey && firstRide && journey.walkRouting === 'street' && Number.isFinite(departure) && Number.isFinite(board));
    const visible = verified && Date.now() < board;
    widget.hidden = !visible;
    document.body.classList.toggle('leave-clock-visible', visible);
    if (!visible) { widget.classList.remove('is-urgent', 'is-due'); return; }
    const title = $('leave-title'), countdown = $('leave-countdown');
    const seconds = Math.ceil((departure - Date.now()) / 1000);
    const walking = Number(journey.walkToBoardSeconds) > 0;
    $('leave-label').textContent = walking ? 'LEAVE HOME' : 'BOARD BY';
    title.textContent = seconds > 0 ? clock(journey.leaveAt) : walking ? 'Leave now' : 'Board now';
    countdown.textContent = seconds > 0 ? `in ${Math.floor(seconds / 60)}m ${String(seconds % 60).padStart(2, '0')}s` : `Boards ${clock(board)}`;
    widget.classList.toggle('is-urgent', seconds > 0 && seconds <= 180);
    widget.classList.toggle('is-due', seconds <= 0);
    const walkMinutes = Math.ceil(Math.max(0, Number(journey.walkToBoardSeconds) || 0) / 60);
    const bufferMinutes = Math.ceil(Math.max(0, Number(journey.boardingBufferSeconds) || 0) / 60);
    widget.setAttribute('aria-label', walking
      ? `Leave home by ${clock(journey.leaveAt)}. ${walkMinutes} minute walk plus ${bufferMinutes} minutes before boarding. Show walking route to the first stop.`
      : `Board by ${clock(board)}. Show the first boarding stop.`);
  }

  function bindSheetGesture(element, opening) {
    let gesture = null;
    const reset = () => {
      $('journey-panel').style.transform = '';
      $('open-panel').style.opacity = '';
      document.body.classList.remove('sheet-preview', 'sheet-dragging');
    };
    element.addEventListener('pointerdown', event => {
      if (!isMobile() || (event.button !== undefined && event.button !== 0) || event.isPrimary === false) return;
      // A fresh press is intentional; only the compatibility click from the drag is suppressed.
      state.suppressSheetClickUntil = 0;
      gesture = { id: event.pointerId, y: event.clientY, x: event.clientX, at: performance.now(), active: false };
      if (element.setPointerCapture) element.setPointerCapture(event.pointerId);
    });
    element.addEventListener('pointermove', event => {
      if (!gesture || event.pointerId !== gesture.id) return;
      const dy = event.clientY - gesture.y, dx = event.clientX - gesture.x;
      if (!gesture.active && Math.abs(dy) > 7 && Math.abs(dy) > Math.abs(dx) * 1.2 && (opening ? dy < 0 : dy > 0)) {
        gesture.active = true;
        document.body.classList.add('sheet-dragging');
        if (opening) document.body.classList.add('sheet-preview');
      }
      if (!gesture.active) return;
      if (event.cancelable) event.preventDefault();
      if (opening) {
        const height = $('journey-panel').getBoundingClientRect().height;
        $('journey-panel').style.transform = `translateY(${Math.max(0, height + Math.min(0, dy) - (window.innerHeight - gesture.y))}px)`;
        $('open-panel').style.opacity = String(Math.max(.1, 1 + dy / 160));
      } else $('journey-panel').style.transform = `translateY(${Math.max(0, dy)}px)`;
    });
    const finish = (event, cancelled = false) => {
      if (!gesture || event.pointerId !== gesture.id) return;
      const dy = event.clientY - gesture.y;
      const distance = opening ? -dy : dy;
      const speed = distance / Math.max(1, performance.now() - gesture.at);
      const commit = !cancelled && gesture.active && (distance >= 48 || (distance >= 22 && speed >= .5));
      if (gesture.active) state.suppressSheetClickUntil = Date.now() + 350;
      gesture = null;
      reset();
      if (commit) setPanel(opening, opening);
      if (element.hasPointerCapture && element.hasPointerCapture(event.pointerId)) element.releasePointerCapture(event.pointerId);
    };
    element.addEventListener('pointerup', event => finish(event));
    element.addEventListener('pointercancel', event => finish(event, true));
  }

  function collectTransfers(journey) {
    const legs = journey.legs || [];
    const rides = legs.map((leg, index) => ({ leg, index })).filter(item => item.leg.mode !== 'walk');
    return rides.slice(1).map((ride, index) => {
      const previous = rides[index];
      const from = previous.leg.to, to = ride.leg.from;
      if (!from || !to || !from.id || !to.id) return null;
      return { key: `${previous.leg.tripId}:${ride.leg.tripId}:${ride.leg.serviceDate || ''}:${from.id}:${to.id}`, from, to,
        previous: previous.leg, next: ride.leg, previousIndex: previous.index, nextIndex: ride.index,
        walkingIndexes: legs.map((leg, position) => position > previous.index && position < ride.index && leg.mode === 'walk' ? position : -1).filter(position => position >= 0) };
    }).filter(Boolean);
  }

  function transferTimeEligible(transfer) {
    const now = Date.now();
    return now >= timeValue(transfer.previous.arrival) - 180000 && now <= timeValue(transfer.next.departure) + 90000;
  }

  function maybeAutoGuidance() {
    if (!state.selected || state.guidance || state.pickField || state.addressEditField || !state.userLocation || !state.locationConsent || document.hidden) return;
    const point = state.userLocation;
    const age = Date.now() - point.timestamp;
    if (!Number.isFinite(point.accuracy) || point.accuracy > 80 || age < 0 || age > 60000) return;
    const transfer = state.transfers.find(item => !state.dismissedTransfers.has(item.key) && transferTimeEligible(item) && map.distance([point.lat, point.lon], [item.from.lat, item.from.lon]) <= 100);
    if (transfer) openStationGuidance(transfer, 'auto');
  }

  function guidanceDots(geometry) {
    const lengths = [0];
    for (let index = 1; index < geometry.length; index++) lengths.push(lengths[index - 1] + map.distance(geometry[index - 1], geometry[index]));
    const total = lengths[lengths.length - 1];
    if (!total) return geometry.slice(0, 1);
    const result = [], spacing = Math.max(8, total / 90);
    let segment = 1;
    for (let distance = 0; distance < total; distance += spacing) {
      while (segment < geometry.length - 1 && lengths[segment] < distance) segment++;
      const fraction = (distance - lengths[segment - 1]) / (lengths[segment] - lengths[segment - 1] || 1);
      const a = geometry[segment - 1], b = geometry[segment];
      result.push([a[0] + (b[0] - a[0]) * fraction, a[1] + (b[1] - a[1]) * fraction]);
    }
    result.push(geometry[geometry.length - 1]);
    return result;
  }

  function fitStationGuidance(force = true) {
    const guidance = state.guidance;
    if (!guidance || !guidance.data || !guidance.following || state.pickField) return;
    if (state.zooming) { guidance.fitPending = true; return; }
    const data = guidance.data;
    const bounds = L.latLngBounds([[data.from.lat, data.from.lon], [data.to.lat, data.to.lon]]);
    (data.geometry || []).forEach(point => bounds.extend(point));
    const user = state.userLocation;
    if (user && map.distance([user.lat, user.lon], [data.from.lat, data.from.lon]) <= 500) bounds.extend([user.lat, user.lon]);
    if (!force && guidance.lastFit && Date.now() - guidance.lastFit < 5000) return;
    guidance.lastFit = Date.now();
    state.programmaticView = true;
    clearTimeout(state.viewTimer);
    const card = $('station-guidance').getBoundingClientRect();
    map.fitBounds(bounds.pad(.12), { paddingTopLeft: state.mapOnly ? [35, mapOnlyTop()] : isMobile() ? [35, card.bottom + 25] : [card.right + 25, 105], paddingBottomRight: state.mapOnly ? [35, 80] : isMobile() ? [65, 80] : [80, 110], maxZoom: 19, animate: false });
    if (state.guidanceTargetMarker && state.guidanceTargetMarker.getElement()) state.guidanceTargetMarker.getElement().classList.toggle('label-left', map.latLngToContainerPoint(state.guidanceTargetMarker.getLatLng()).x > map.getSize().x / 2);
    state.viewTimer = setTimeout(() => { state.programmaticView = false; }, 500);
  }

  function stationFeatureRestricted(properties) {
    return properties.restricted === true || ['no', 'private'].includes(properties.access) || ['no', 'private'].includes(properties.foot);
  }

  function stationFeatureStyle(feature) {
    const kind = feature.properties.kind;
    const target = state.guidance && state.guidance.data && state.guidance.data.to;
    const mappedIds = [feature.properties.gtfsStopId, ...(Array.isArray(feature.properties.gtfsStopIds) ? feature.properties.gtfsStopIds : [])];
    const exactPlatform = kind === 'platform' && target && mappedIds.some(id => id !== undefined && String(id) === String(target.id));
    const styles = {
      building: { color: '#cbd2c3', weight: 1.5, fillColor: '#eeefe8', fillOpacity: .96 },
      platform: { color: '#9aa98d', weight: 2, fillColor: '#dce7cf', fillOpacity: .95 },
      rail: { color: '#a8ada8', weight: 2, opacity: .65, dashArray: '5 4' },
      footway: { color: '#b9c5ae', weight: 5, opacity: .75 },
      steps: { color: '#8b7a5d', weight: 6, opacity: .8, dashArray: '2 3', lineCap: 'butt' }
    };
    const restricted = stationFeatureRestricted(feature.properties);
    return { ...styles[kind], ...(exactPlatform ? { color: '#698c3d', weight: 2.5, fillColor: '#c2f65b', fillOpacity: .38 } : {}), ...(restricted ? { opacity: .4, fillOpacity: .35, dashArray: '3 5' } : {}), renderer: state.stationMapRenderer, pane: 'stationDetailPane', className: `station-feature station-feature-${kind}${exactPlatform ? ' is-boarding-platform' : ''}`, interactive: ['building', 'platform', 'steps'].includes(kind) };
  }

  function stationFeatureLabel(properties) {
    const names = { platform: 'Platform', building: 'Building', footway: 'Walkway', steps: 'Steps', rail: 'Railway', entrance: 'Entrance', lift: 'Lift', crossing: 'Crossing', bus_stop: 'Bus stop' };
    const reference = properties.localRef || properties.ref;
    const label = String(properties.name || (reference ? `${names[properties.kind] || 'Station feature'} ${reference}` : names[properties.kind] || 'Station feature'));
    const level = properties.level === undefined || properties.level === null || properties.level === '' ? '' : ` · level ${properties.level}`;
    return `${label}${level}${stationFeatureRestricted(properties) ? ' · restricted access' : ''}`;
  }

  function stationPointIcon(kind, restricted = false) {
    const body = make('span', `station-place-body station-place-${kind}${restricted ? ' is-restricted' : ''}`);
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    const paths = { entrance: 'M3 12h13m-4-4 4 4-4 4M18 4h3v16h-3', lift: 'm8 7 4-4 4 4M12 3v18m-4-4 4 4 4-4', crossing: 'M6 6h12M6 12h12M6 18h12', steps: 'M3 20h5v-5h5v-5h5V5h4' };
    path.setAttribute('d', paths[kind] || paths.entrance);
    svg.append(path); body.append(svg);
    return L.divIcon({ html: body, className: `station-place-marker station-place-marker-${kind}`, iconSize: [22, 22], iconAnchor: [11, 11] });
  }

  function stationMapSource(data, targetId = 'station-map-source') {
    const source = $(targetId);
    source.replaceChildren();
    const attribution = make('span', '', data.attribution || '© OpenStreetMap contributors');
    try {
      const url = new URL(data.sourceUrl);
      if (url.protocol === 'https:' && !url.username && !url.password && ['openstreetmap.org', 'www.openstreetmap.org', 'download.geofabrik.de'].includes(url.hostname)) {
        const link = make('a', '', data.attribution || '© OpenStreetMap contributors');
        link.href = url.href; link.target = '_blank'; link.rel = 'noopener';
        attribution.replaceChildren(link);
      }
    } catch { /* A source is still shown as text if its link is unavailable. */ }
    source.append(attribution);
    const updated = timeValue(data.updatedAt);
    if (Number.isFinite(updated)) source.append(make('span', '', `${data.stale ? 'Saved map' : 'Map updated'} ${new Intl.DateTimeFormat('en-AU', { dateStyle: 'medium', timeZone: 'Australia/Perth' }).format(updated)}`));
    const transfer = state.guidance && state.guidance.data;
    if (transfer && Number(transfer.transferMinimumSeconds) > 0) source.append(make('span', '', `Official timetable connection allowance: ${Math.ceil(Number(transfer.transferMinimumSeconds) / 60)} min.`));
    (Array.isArray(data.stations) ? data.stations : []).forEach(station => {
      try {
        const url = new URL(station.officialPlanUrl);
        if (url.protocol !== 'https:' || url.hostname !== 'www.transperth.wa.gov.au' || url.username || url.password || !/^\/timetablePDFs\/BusStationMaps\/[^/]+\.pdf$/i.test(url.pathname)) return;
        const link = make('a', 'station-official-plan', `Official ${station.name || 'station'} plan (PDF)`);
        link.href = url.href; link.target = '_blank'; link.rel = 'noopener'; source.append(link);
      } catch { /* Official plans are optional, fixed-source references. */ }
    });
    source.append(make('span', '', 'Mapped station features. Follow the signs for platform access and levels.'));
  }

  function stationMapFeatures(data) {
    const kinds = ['building', 'platform', 'rail', 'footway', 'steps', 'entrance', 'lift', 'crossing', 'bus_stop'];
    const features = data.available && data.featureCollection && Array.isArray(data.featureCollection.features)
      ? data.featureCollection.features.filter(feature => feature && feature.type === 'Feature' && feature.properties && kinds.includes(feature.properties.kind) && feature.geometry && ['Point', 'LineString', 'MultiLineString', 'Polygon', 'MultiPolygon'].includes(feature.geometry.type)).slice(0, 2500) : [];
    features.sort((a, b) => kinds.indexOf(a.properties.kind) - kinds.indexOf(b.properties.kind));
    return features;
  }

  function addStationMapFeatures(features, layers, stopIds = null) {
    L.geoJSON({ type: 'FeatureCollection', features }, {
      style: stationFeatureStyle,
      bubblingMouseEvents: false,
      pointToLayer: (feature, point) => {
        const kind = feature.properties.kind;
        if (['entrance', 'lift', 'crossing', 'steps'].includes(kind)) {
          const restricted = stationFeatureRestricted(feature.properties);
          return L.marker(point, { icon: stationPointIcon(kind, restricted), opacity: restricted ? .35 : 1, zIndexOffset: 300, bubblingMouseEvents: false, title: stationFeatureLabel(feature.properties) });
        }
        return L.circleMarker(point, { ...stationFeatureStyle(feature), bubblingMouseEvents: false, radius: kind === 'platform' ? 5 : 3, ...(stopIds && stopIds.has(String(feature.properties.gtfsStopId)) ? { interactive: true } : {}) });
      },
      onEachFeature: (feature, layer) => {
        const properties = feature.properties;
        const name = stationFeatureLabel(properties);
        const boardingStop = stopIds && stopIds.has(String(properties.gtfsStopId));
        if (properties.kind === 'bus_stop' && boardingStop && properties.localRef) layer.bindTooltip(make('span', '', String(properties.localRef)), { permanent: true, direction: 'top', offset: [0, -4], pane: 'stationLabelPane', className: 'station-stand-label' });
        else if (properties.kind === 'platform' && (properties.localRef || properties.ref)) layer.bindTooltip(make('span', '', String(properties.localRef || properties.ref)), { permanent: true, direction: 'center', pane: 'stationLabelPane', className: 'station-platform-label' });
        else if (properties.name || properties.ref || ['entrance', 'lift', 'crossing', 'steps'].includes(properties.kind)) layer.bindTooltip(make('span', '', name));
        if (boardingStop || ['platform', 'building', 'entrance', 'lift', 'crossing', 'steps'].includes(properties.kind)) {
          const popup = make('div', 'service-popup');
          popup.append(make('strong', '', name));
          if (properties.access) popup.append(make('span', '', `Mapped access: ${properties.access}`));
          else if (stationFeatureRestricted(properties)) popup.append(make('span', '', 'Restricted pedestrian access'));
          if (properties.wheelchair) popup.append(make('span', '', `Mapped wheelchair access: ${properties.wheelchair}`));
          popup.append(make('span', '', '© OpenStreetMap contributors'));
          if (boardingStop) popup.append(timetableButton('View departures', { stop: properties.gtfsStopId }));
          layer.bindPopup(popup);
        }
      }
    }).addTo(layers);
  }

  function renderStationMap(data) {
    state.stationMapLayers.clearLayers();
    const features = stationMapFeatures(data);
    if (!features.length) {
      $('station-map-state').textContent = 'Unavailable';
      $('station-map-source').replaceChildren(make('span', '', 'Station detail is unavailable. Your walking route and boarding stop remain on the map.'));
      const retry = make('button', 'guidance-retry', 'Retry station map');
      retry.type = 'button'; retry.addEventListener('click', loadStationMap);
      $('station-map-source').append(retry);
      return;
    }
    addStationMapFeatures(features, state.stationMapLayers);
    $('station-map-state').textContent = data.stale ? 'Saved map' : '';
    stationMapSource(data);
  }

  async function loadStationMap() {
    if (!state.guidance) return;
    const guidance = state.guidance;
    if (state.stationMapController) state.stationMapController.abort();
    const controller = new AbortController();
    state.stationMapController = controller;
    $('station-map-state').textContent = 'Loading…';
    try {
      const data = await api('station-map', { from: guidance.transfer.from.id, to: guidance.transfer.to.id }, controller.signal);
      if (!controller.signal.aborted && state.guidance === guidance) renderStationMap(data);
    } catch (error) {
      if (error.name !== 'AbortError' && state.guidance === guidance) renderStationMap({ available: false });
    } finally { if (state.stationMapController === controller) state.stationMapController = null; }
  }

  async function loadStationBrowseCatalog() {
    if (state.stationBrowseCatalog || state.stationBrowseCatalogRequest || Date.now() < state.stationBrowseCatalogRetryAt) return;
    const script = document.querySelector('script[data-station-catalog]');
    if (!script) return;
    state.stationBrowseCatalogRequest = (async () => {
      try {
        const response = await fetch(new URL(script.dataset.stationCatalog, location.href), { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('Station catalog unavailable');
        const data = await response.json();
        if (data.version !== 1 || !data.stations || typeof data.stations !== 'object') throw new Error('Invalid station catalog');
        state.stationBrowseCatalog = Object.entries(data.stations).map(([id, station]) => ({ ...station, id: String(id) })).filter(station =>
          Number.isFinite(Number(station.lat)) && Number.isFinite(Number(station.lon)) && Array.isArray(station.bounds) && station.bounds.length === 4 && station.bounds.every(Number.isFinite));
        scheduleStationBrowse();
      } catch { state.stationBrowseCatalogRetryAt = Date.now() + 60000; }
      finally { state.stationBrowseCatalogRequest = null; }
    })();
    await state.stationBrowseCatalogRequest;
  }

  function clearStationBrowse(redraw = true) {
    state.stationBrowseRequest++;
    if (state.stationBrowseController) state.stationBrowseController.abort();
    state.stationBrowseController = null;
    state.stationBrowse = null;
    state.stationBrowseReady = false;
    if (state.stationBrowseLayers) state.stationBrowseLayers.clearLayers();
    document.body.classList.remove('station-browse-active');
    $('map').dataset.stationBrowse = '';
    $('station-browse').hidden = true;
    if (redraw) drawNetworkLines();
  }

  function stationBrowseCard(station, active, loading = false) {
    $('station-browse').hidden = Boolean(state.pickField || state.guidance || state.selected);
    $('station-browse-name').textContent = station.name;
    $('station-browse-state').textContent = loading ? 'Opening station map…' : 'STATION MAP';
    $('station-browse-toggle').textContent = active ? 'Show routes' : 'Station map';
    $('station-browse-toggle').setAttribute('aria-label', active ? 'Show network routes instead of station detail' : `Show ${station.name} station map`);
    $('station-browse-toggle').setAttribute('aria-pressed', String(active));
  }

  async function openStationBrowse(station) {
    clearStationBrowse(false);
    state.stationBrowse = station;
    const request = state.stationBrowseRequest;
    const controller = new AbortController();
    state.stationBrowseController = controller;
    document.body.classList.add('station-browse-active');
    $('map').dataset.stationBrowse = station.id;
    $('station-browse-details').open = false;
    $('station-browse-source').replaceChildren();
    stationBrowseCard(station, true, true);
    drawNetworkLines();
    try {
      let data = state.stationBrowseCache.get(station.id);
      if (!data) {
        data = await api('station-map', { from: station.id, to: station.id }, controller.signal);
        if (data.available) {
          state.stationBrowseCache.set(station.id, data);
          if (state.stationBrowseCache.size > 6) state.stationBrowseCache.delete(state.stationBrowseCache.keys().next().value);
        }
      }
      if (controller.signal.aborted || request !== state.stationBrowseRequest || state.stationBrowse !== station || state.selected || state.guidance || state.pickField) return;
      const features = stationMapFeatures(data);
      if (!features.length) throw new Error('Station detail unavailable');
      addStationMapFeatures(features, state.stationBrowseLayers, new Set((station.stopIds || []).map(String)));
      state.stationBrowseReady = true;
      stationBrowseCard(station, true);
      stationMapSource(data, 'station-browse-source');
    } catch (error) {
      if (error.name === 'AbortError' || request !== state.stationBrowseRequest) return;
      state.stationBrowseSuppressed = station.id;
      clearStationBrowse();
      stationBrowseCard(station, false);
      $('station-browse-state').textContent = 'Station detail unavailable';
    } finally { if (state.stationBrowseController === controller) state.stationBrowseController = null; }
  }

  function refreshStationBrowse() {
    if (!map) return;
    if (state.pickField) { $('station-browse').hidden = true; return; }
    if (state.selected || state.guidance || map.getZoom() < 17) {
      state.stationBrowseCandidate = null;
      if (map.getZoom() < 17) state.stationBrowseSuppressed = null;
      if (state.stationBrowse) clearStationBrowse(); else $('station-browse').hidden = true;
      return;
    }
    if (!state.stationBrowseCatalog) { loadStationBrowseCatalog(); return; }
    const centre = map.getCenter();
    const nearby = state.stationBrowseCatalog.map(station => ({ station, distance: map.distance(centre, [station.lat, station.lon]) })).filter(({ station, distance }) =>
      distance <= 320 && centre.lng >= station.bounds[0] && centre.lng <= station.bounds[2] && centre.lat >= station.bounds[1] && centre.lat <= station.bounds[3]);
    nearby.sort((a, b) => a.distance - b.distance);
    const station = nearby[0] && nearby[0].station;
    if (!station) {
      state.stationBrowseCandidate = null;
      state.stationBrowseSuppressed = null;
      if (state.stationBrowse) clearStationBrowse(); else $('station-browse').hidden = true;
      return;
    }
    state.stationBrowseCandidate = station;
    if (state.stationBrowseSuppressed === station.id) {
      if (state.stationBrowse) clearStationBrowse();
      stationBrowseCard(station, false);
    } else if (state.stationBrowse && state.stationBrowse.id === station.id && (state.stationBrowseReady || state.stationBrowseController)) stationBrowseCard(station, true, Boolean(state.stationBrowseController));
    else openStationBrowse(station);
  }

  function scheduleStationBrowse() {
    clearTimeout(state.stationBrowseTimer);
    state.stationBrowseTimer = setTimeout(refreshStationBrowse, 180);
  }

  function renderStationGuidance(data, loading = false) {
    if (!state.guidance) return;
    state.guidance.data = data;
    state.guidanceLayers.clearLayers();
    $('station-guidance-title').textContent = data.station && data.station.name || 'Your transfer';
    $('guidance-target-name').textContent = `Board at ${data.to.name}`;
    const minimum = Number(data.transferMinimumSeconds);
    const allowance = Number.isFinite(minimum) && minimum > (Number(data.duration) || 0) + 120
      ? ` · allow ${Math.ceil(minimum / 60)} min to change` : '';
    $('guidance-detail').textContent = loading ? 'Loading station…' : data.routing === 'street' ? `${Math.round(data.distance)} m · ${Math.max(1, Math.ceil(Number(data.duration) / 60))} min walk${allowance || ' · follow platform signs'}` : data.routing === 'stationary' ? `Use the signed boarding stop or platform${allowance}.` : 'Walking path unavailable. Follow the station signs.';
    const stops = new Map((data.stops || []).map(stop => [String(stop.id), stop]));
    stops.set(String(data.to.id), { ...data.to, target: true });
    stops.forEach(stop => {
      const target = String(stop.id) === String(data.to.id);
      let marker;
      if (target) {
        const body = make('div', 'guidance-target-pin');
        const shortName = String(data.to.name).match(/\b(?:platform|stand|bay)\s+[^,]+$/i);
        body.append(make('span', 'guidance-target-ring', 'B'), make('span', 'guidance-target-label', shortName ? shortName[0] : data.to.name));
        marker = L.marker([stop.lat, stop.lon], { icon: L.divIcon({ html: body, className: 'guidance-stop-target', iconSize: [22, 22], iconAnchor: [11, 11] }), zIndexOffset: 6000, bubblingMouseEvents: false, title: `Board at ${data.to.name}` });
        const popup = make('div', 'service-popup');
        popup.append(make('strong', '', `Board at ${data.to.name}`), timetableButton('View next service', tripQuery(state.guidance.transfer.next)));
        marker.bindPopup(popup);
        state.guidanceTargetMarker = marker;
      } else marker = L.marker([stop.lat, stop.lon], { icon: L.divIcon({ className: 'guidance-stop-context', iconSize: [8, 8], iconAnchor: [4, 4] }), zIndexOffset: 200, bubblingMouseEvents: false, keyboard: false, title: stop.name }).bindTooltip(make('span', '', stop.name));
      marker.addTo(state.guidanceLayers);
    });
    if (String(data.from.id) !== String(data.to.id) && map.distance([data.from.lat, data.from.lon], [data.to.lat, data.to.lon]) > .5) {
      const arrival = make('span', 'guidance-origin-pin', 'A');
      const marker = L.marker([data.from.lat, data.from.lon], { icon: L.divIcon({ html: arrival, className: 'guidance-stop-origin', iconSize: [22, 22], iconAnchor: [11, 11] }), zIndexOffset: 5500, bubblingMouseEvents: false, title: `Get off at ${data.from.name}` });
      marker.bindTooltip(make('span', '', `Get off at ${data.from.name}`)).addTo(state.guidanceLayers);
    }
    if (data.routing === 'street' && Array.isArray(data.geometry) && data.geometry.length > 1) {
      L.polyline(data.geometry, { renderer: state.guidanceRenderer, color: '#647c55', weight: 3, opacity: .45, interactive: false }).addTo(state.guidanceLayers);
      const dots = guidanceDots(data.geometry);
      dots.forEach((point, index) => {
        const dot = L.circleMarker(point, { renderer: state.guidanceRenderer, radius: 3.5, color: '#476438', weight: 1, fillColor: '#c2f65b', fillOpacity: 1, interactive: false, className: 'guidance-trail-dot' }).addTo(state.guidanceLayers);
        const element = dot.getElement();
        if (element) element.style.animationDelay = `${index * Math.min(.08, 3 / dots.length)}s`;
      });
    }
    updateGuidanceTarget();
    fitStationGuidance();
  }

  async function loadStationGuidance() {
    if (!state.guidance) return;
    const guidance = state.guidance;
    if (state.guidanceController) state.guidanceController.abort();
    const controller = new AbortController();
    state.guidanceController = controller;
    try {
      const transfer = guidance.transfer;
      const data = await api('transfer', { from: transfer.from.id, to: transfer.to.id, ...tripQuery(transfer.next) }, controller.signal);
      if (!controller.signal.aborted && state.guidance === guidance) { renderStationGuidance(data); loadStationMap(); }
    } catch (error) {
      if (error.name === 'AbortError' || state.guidance !== guidance) return;
      const retry = make('button', 'guidance-retry', 'Retry');
      retry.type = 'button'; retry.addEventListener('click', loadStationGuidance);
      $('guidance-detail').replaceChildren(document.createTextNode('Station guidance couldn’t load. '), retry);
    } finally { if (state.guidanceController === controller) state.guidanceController = null; }
  }

  function openStationGuidance(transfer, activation = 'manual') {
    if (!state.selected || !transfer) return;
    clearStationBrowse(false);
    if (state.guidance) exitStationGuidance(false);
    state.guidancePrevious = { follow: state.followJourney, center: map.getCenter(), zoom: map.getZoom() };
    state.guidance = { transfer, activation, following: true, confirmed: false, data: null };
    setFollow(false);
    document.body.classList.add('station-guidance-active');
    $('station-guidance').hidden = false;
    $('confirm-guidance').disabled = false;
    $('confirm-guidance').textContent = 'I can see this stop';
    state.stationMapLayers.clearLayers();
    $('station-map-details').open = false;
    $('station-map-state').textContent = 'Loading…';
    $('station-map-source').replaceChildren();
    [journeyLayers, networkLayers, state.approachLayers].forEach(layer => { if (map.hasLayer(layer)) map.removeLayer(layer); });
    setPanel(false);
    renderStationGuidance({ from: transfer.from, to: transfer.to, stops: [transfer.from, transfer.to], geometry: [], station: { name: transfer.to.name }, routing: 'unavailable' }, true);
    $('station-guidance').focus({ preventScroll: true });
    loadStationGuidance();
  }

  function updateGuidanceTarget() {
    if (!state.guidance || !state.guidanceTargetMarker) return;
    const element = state.guidanceTargetMarker.getElement();
    if (element) element.classList.toggle('is-confirmed', state.guidance.confirmed);
  }

  function exitStationGuidance(restore = true) {
    if (!state.guidance) return;
    if (state.guidance.activation === 'auto' || transferTimeEligible(state.guidance.transfer)) state.dismissedTransfers.add(state.guidance.transfer.key);
    if (state.guidanceController) state.guidanceController.abort();
    if (state.stationMapController) state.stationMapController.abort();
    state.stationMapController = null;
    state.guidanceController = null;
    state.guidance = null;
    state.guidanceTargetMarker = null;
    state.guidanceLayers.clearLayers();
    state.stationMapLayers.clearLayers();
    document.body.classList.remove('station-guidance-active');
    $('station-guidance').hidden = true;
    [networkLayers, journeyLayers, state.approachLayers].forEach(layer => { if (!map.hasLayer(layer)) layer.addTo(map); });
    setPanel(false);
    const previous = state.guidancePrevious;
    state.guidancePrevious = null;
    if (restore && state.selected) {
      if (previous && previous.follow) setFollow(true);
      else if (previous) map.setView(previous.center, previous.zoom, { animate: false });
    }
    if (restore && isMobile()) $('open-panel').focus({ preventScroll: true });
  }

  async function search(field, automatic = false, addressesOnly = false) {
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
      const data = await api('geocode', { q: query, ...(addressesOnly ? { addresses: '1' } : {}) }, state.searchController.signal);
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
          chooseSearchPlace(field, place);
          message(field === 'destination' ? 'Destination set.' : 'Starting point updated.');
        });
        results.append(button);
      });
      if (data.moreAddresses) {
        const more = make('button', 'search-result', 'Search other addresses'); more.type = 'button';
        more.addEventListener('click', () => search(field, false, true)); results.append(more);
      }
      results.append(make('div', 'search-note search-attribution', data.attribution || 'Address search by Photon · © OpenStreetMap contributors.'));
    } catch (error) {
      if (error.name === 'AbortError') return;
      if (request !== state.searchNumber) return;
      results.replaceChildren(make('div', 'search-note', error.message));
    }
  }

  const savedTripKey = 'nextstop-active-trip-v1';
  function searchPreferences() {
    return { modes: ['bus','train','ferry'].filter(mode=>state.searchModes[mode]).join(','), longWalks: state.searchModes.walk ? '1' : '0' };
  }

  function clearWalkingAccess() {
    if(state.accessLookupController)state.accessLookupController.abort();state.accessLookupController=null;
    if(state.accessSuggestionLayers)state.accessSuggestionLayers.clearLayers();
    document.querySelectorAll('.access-suggestion-note,.access-suggestion-button,.access-suggestion-source').forEach(el=>el.remove());
  }

  function showWalkingAccess(access,field,point) {
    if(!state.accessSuggestionLayers)state.accessSuggestionLayers=L.layerGroup().addTo(map);
    state.accessSuggestionLayers.clearLayers();
    if(access.polygon){L.geoJSON(access.polygon,{style:{color:'#6b4c9a',weight:2,fillOpacity:.12}}).bindTooltip(`${access.polygon.name||'Mapped boundary'} · OpenStreetMap`).addTo(state.accessSuggestionLayers);}
    const gps=access.nearestWalkingPoint;
    message(access.message||'No mapped access point was found. Choose an entrance on the map.',true);
    if(!access.available||!gps)return;
    L.marker([gps.lat,gps.lon],{title:'Suggested walking point'}).bindTooltip('Suggested walking point',{permanent:true,direction:'top'}).addTo(state.accessSuggestionLayers);
    const note=make('span','access-suggestion-note',`${Math.round(gps.distanceFromSelection)} m from your selection · ${gps.name}. GPS: ${Number(gps.lat).toFixed(6)}, ${Number(gps.lon).toFixed(6)}. Approximate access; not a verified entrance. ${field==='origin'?'Allow extra time to reach this point; the trip starts here.':'The arrival time is for this walking point, not inside the site.'}`);
    const use=make('button','access-suggestion-button','Use this walking point');use.type='button';
    use.addEventListener('click',()=>setPlace(field,{lat:gps.lat,lon:gps.lon,name:`${String(point.name).slice(0,140)} · walking access`},true));
    const choose=make('button','access-suggestion-button','Choose an entrance on the map');choose.type='button';choose.addEventListener('click',()=>{clearWalkingAccess();beginMapPick(field)});
    $('message').append(note,use,choose,make('small','access-suggestion-source',access.attribution||'© OpenStreetMap contributors'));
    const bounds=L.geoJSON(access.polygon).getBounds();bounds.extend([gps.lat,gps.lon]);
    map.fitBounds(bounds,{maxZoom:17,animate:!reducedMotion,paddingTopLeft:[30,90],paddingBottomRight:[60,isMobile()?Math.round(map.getSize().y*.65):80]});
  }

  function syncSearchModes() {
    document.querySelectorAll('[data-search-mode]').forEach(b=>b.setAttribute('aria-pressed',String(state.searchModes[b.dataset.searchMode])));
  }
  function saveTripSearch() {
    try {
      if (!state.origin || !state.destination) { localStorage.removeItem(savedTripKey); $('forget-saved-trip').hidden = true; return; }
      localStorage.setItem(savedTripKey, JSON.stringify({ version: 1, origin: state.origin, destination: state.destination,
        timeMode: state.timeMode, date: $('arrival-date').value, time: $('arrival-time').value,
        selectedId: state.selected?.id,
        view: { center: [map.getCenter().lat, map.getCenter().lng], zoom: map.getZoom() }, mapOnly: state.mapOnly }));
      $('forget-saved-trip').hidden = false;
    } catch { /* Private browsing/storage limits must not block planning. */ }
  }

  function saveTripView() {
    try {
      const saved = JSON.parse(localStorage.getItem(savedTripKey) || 'null');
      if (!saved || saved.version !== 1) return;
      saved.view = { center: [map.getCenter().lat, map.getCenter().lng], zoom: map.getZoom() }; saved.mapOnly = state.mapOnly;
      localStorage.setItem(savedTripKey, JSON.stringify(saved));
    } catch {}
  }

  function restoreTripSearch() {
    try {
      const raw = localStorage.getItem(savedTripKey); if (!raw || raw.length > 10000) return;
      const saved = JSON.parse(raw);
      const valid = p => p && typeof p.lat === 'number' && typeof p.lon === 'number' && Number.isFinite(p.lat) && Number.isFinite(p.lon)
        && p.lat >= -33.5 && p.lat <= -30.5 && p.lon >= 114.5 && p.lon <= 117.5 && typeof p.name === 'string' && p.name.length <= 160;
      if (saved.version !== 1 || !valid(saved.origin) || !valid(saved.destination)) { localStorage.removeItem(savedTripKey); return; }
      state.origin = { ...saved.origin, name: saved.origin.name === 'Your location' ? 'Saved starting point' : saved.origin.name };
      state.destination = saved.destination; state.originUserChosen = true; state.awaitingGpsOrigin = false;
      $('forget-saved-trip').hidden = false;
      state.restoredTripId = typeof saved.selectedId === 'string' ? saved.selectedId : null;
      $('origin-input').value = state.origin.name; $('destination-input').value = state.destination.name;
      updatePlaceMarker('origin'); updatePlaceMarker('destination');
      state.timeMode = saved.timeMode === 'arrive' ? 'arrive' : 'now';
      $('arrival-date').value = typeof saved.date === 'string' ? saved.date : '';
      $('arrival-time').value = typeof saved.time === 'string' ? saved.time : '';
      syncJourneyTiming();
      const v = saved.view;
      if (v && Array.isArray(v.center) && v.center.length === 2 && v.center.every(Number.isFinite)
        && Math.abs(v.center[0]) <= 85 && Math.abs(v.center[1]) <= 180 && Number.isFinite(v.zoom) && v.zoom >= 3 && v.zoom <= 20) {
        state.restoredTripView = v; map.setView(v.center, v.zoom, { animate: false });
      }
      if (saved.mapOnly === true) setMapOnly(true);
      updateLauncher(); message('Restored your saved trip. Checking current departures…'); maybeAutoPlan();
    } catch { try { localStorage.removeItem(savedTripKey); } catch {} }
  }

  const perthInputClock = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Australia/Perth', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
  });

  function arrivalInputParts(value) {
    const p = Object.fromEntries(perthInputClock.formatToParts(new Date(value)).map(part => [part.type, part.value]));
    return { date: `${p.year}-${p.month}-${p.day}`, time: `${p.hour}:${p.minute}` };
  }

  function setArrivalInputs(value) {
    const p = arrivalInputParts(value);
    $('arrival-date').value = p.date;
    $('arrival-time').value = p.time;
  }

  function refreshArrivalLimits() {
    $('arrival-date').min = arrivalInputParts(Date.now()).date;
    $('arrival-date').max = arrivalInputParts(Date.now() + 14 * 86400000).date;
  }

  function arrivalTiming() {
    if (state.timeMode !== 'arrive') return { deadline: null };
    const date = $('arrival-date').value, time = $('arrival-time').value;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !/^\d{2}:\d{2}$/.test(time)) return { error: 'Choose your arrival date and time.' };
    // Native date/time inputs are Perth wall time, regardless of the phone's timezone.
    const at = Date.parse(`${date}T${time}:00+08:00`);
    if (!Number.isFinite(at)) return { error: 'Choose a valid arrival date and time.' };
    const normalized = arrivalInputParts(at);
    if (normalized.date !== date || normalized.time !== time) return { error: 'Choose a valid arrival date and time.' };
    if (at <= Date.now()) return { error: 'Choose an arrival time in the future (Perth time).' };
    if (at > Date.now() + 14 * 86400000) return { error: 'Choose an arrival within the next fourteen days.' };
    return { deadline: new Date(at).toISOString().replace(/\.\d{3}Z$/, 'Z') };
  }

  function syncJourneyTiming() {
    const arrive = state.timeMode === 'arrive';
    document.querySelectorAll('[data-journey-time]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.journeyTime === state.timeMode)));
    $('arrival-fields').hidden = !arrive;
    ['arrival-date', 'arrival-time'].forEach(id => { $(id).disabled = !arrive; });
    refreshArrivalLimits();
  }

  function changeJourneyTiming() {
    state.restoredTripView = null; state.restoredTripId = null;
    clearJourney(false);
    saveTripSearch();
    const timing = arrivalTiming();
    if (timing.error) { message(timing.error, true); return; }
    message(state.timeMode === 'arrive' ? 'Choose your destination. We’ll work back from your arrival time.' : 'Choose your destination to find the next departure.');
    maybeAutoPlan();
  }

  function journeyClock(value) {
    if (arrivalInputParts(value).date === arrivalInputParts(Date.now()).date) return clock(value);
    return new Intl.DateTimeFormat('en-AU', { timeZone: 'Australia/Perth', weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }).format(new Date(value));
  }

  function renderArrivalSummary() {
    const card = $('journey-arrival-summary'), journey = state.selected;
    card.replaceChildren(); card.hidden = !journey?.arriveBy;
    if (card.hidden) return;
    card.append(make('strong', '', `Leave by ${journeyClock(journey.leaveAt || journey.departure)}`),
      make('span', '', `Arrive ${journeyClock(journey.arrival)} · by ${journeyClock(journey.arriveBy)}`),
      make('small', '', 'Perth time · walking and transfers included · timetable estimates'));
  }

  async function plan(automatic = false) {
    clearWalkingAccess();
    clearTimeout(state.autoPlanTimer);
    const timing = arrivalTiming();
    if (timing.error) { cancelPendingPlan(); message(timing.error, true); return; }
    const { deadline } = timing;
    saveTripSearch();
    if (state.origin && state.origin.name === 'Your location' && state.userLocation) {
      state.origin = { ...state.origin, lat: state.userLocation.lat, lon: state.userLocation.lon };
      if (state.placeMarkers.origin) state.placeMarkers.origin.setLatLng([state.origin.lat, state.origin.lon]);
    }
    if (!state.origin) { await search('origin'); return; }
    if (!state.destination) {
      if ($('destination-input').value.trim()) await search('destination');
      else beginMapPick('destination');
      return;
    }
    const signature = `${state.origin.lat.toFixed(6)},${state.origin.lon.toFixed(6)}:${state.destination.lat.toFixed(6)},${state.destination.lon.toFixed(6)}:${deadline || 'now'}:${JSON.stringify(searchPreferences())}`;
    if (signature === state.planSignature && (state.planController || (automatic && state.selected))) return;
    if (state.planController) state.planController.abort();
    const controller = new AbortController();
    state.planController = controller;
    state.planSignature = signature;
    const request = ++state.planNumber;
    $('plan-button').disabled = true;
    $('plan-button').firstElementChild.textContent = 'Finding your route…';
    message(deadline ? 'Working back from your arrival time, including walking and transfers…' : 'Comparing departures, walking time, and connections…');
    $('search-results').hidden = true;
    try {
      const data = await api('plan', { from: `${state.origin.lat},${state.origin.lon}`, to: `${state.destination.lat},${state.destination.lon}`, ...searchPreferences(), ...(deadline ? { arriveBy: deadline } : {}) }, controller.signal);
      if (request !== state.planNumber) return;
      if (deadline) {
        data.journeys = data.mode === 'timetable' ? (data.journeys || []).filter(journey => Number.isFinite(Date.parse(journey.arrival)) && timeValue(journey.arrival) <= timeValue(deadline))
          .map(journey => ({ ...journey, arriveBy: deadline })) : [];
      }
      if (!data.journeys || data.journeys.length === 0) {
        clearJourney(false);
        message(deadline ? (data.mode === 'demo' ? 'Arrival planning needs the real timetable. Please try again when timetable data is available.' : 'No journey can reach your destination by that time. Choose a later arrival or another destination.') : (data.message || 'No upcoming journey was found. Try a closer stop or another destination.'), true);
        if (data.walkingIssue && ['no_segment','no_route'].includes(data.walkingIssue.reason) && ['origin','destination'].includes(data.walkingIssue.endpoint)) {
          const field=data.walkingIssue.endpoint==='origin'?'origin':'destination';
          const point={...state[field]};
          const accessController=new AbortController();state.accessLookupController=accessController;const accessNumber=state.planNumber;
          message((data.message || 'Walking access unavailable.')+' Looking for a mapped boundary and walking point…',true);
          try {
            const access=await api('walking-access',{at:`${point.lat},${point.lon}`},accessController.signal);
            if (accessNumber!==state.planNumber) return;
            showWalkingAccess(access,field,point);
          } catch(error) { if(error.name!=='AbortError'&&accessNumber===state.planNumber)message((data.message||'Walking access unavailable.')+' Nearby access lookup is unavailable; choose an entrance on the map.',true); }
        }
        return;
      }
      state.journeys = data.journeys;
      state.journeyMode = data.mode === 'timetable' ? 'timetable' : 'demo';
      renderJourneyOptions();
      $('journey-results').hidden = false;
      document.querySelector('.journey-panel').classList.add('has-journey');
      selectJourney(data.journeys.find(j => j.id === state.restoredTripId) || data.journeys[0]);
      state.restoredTripId = null;
      if (state.restoredTripView) {
        const view = state.restoredTripView; state.restoredTripView = null;
        setFollow(false); map.setView(view.center, view.zoom, { animate: false });
      }
      message(data.mode === 'demo' ? 'Illustrative demo route. Import Perth timetables before using this for travel.' : (data.message || 'Your route is ready. All times and positions are timetable estimates.'));
      if (isMobile()) { document.querySelector('.journey-panel').scrollTop = 0; setPanel(false); }
    } catch (error) {
      if (error.name !== 'AbortError' && request === state.planNumber) message(error.message, true);
    } finally {
      if (request === state.planNumber) {
        if (state.planController === controller) state.planController = null;
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
      const label = index === 0 ? (journey.arriveBy ? 'Latest departure' : 'Quickest arrival') : `${Number(journey.transfers) || 0} ${Number(journey.transfers) === 1 ? 'transfer' : 'transfers'}`;
      button.append(time, make('span', 'option-label', label));
      button.title = `${clock(journey.arrival)} arrival · ${legs.map(leg => leg.routeName).join(' → ') || 'Walking'}`;
      button.addEventListener('click', () => selectJourney(journey));
      container.append(button);
    });
  }

  function selectJourney(journey) {
    state.onboard = null; state.nearbyCamera = null;
    clearStationBrowse(false);
    if (state.guidance) exitStationGuidance(false);
    clearApproach();
    clearNearbyBus();
    state.selected = journey;
    saveTripSearch();
    state.fullJourneyOverview = false;
    state.firstWalkFocus = false;
    document.body.classList.remove('walk-to-stop-active');
    state.firstWalkLayers.clearLayers();
    if (!map.hasLayer(journeyLayers)) journeyLayers.addTo(map);
    state.transfers = collectTransfers(journey);
    state.dismissedTransfers = new Set();
    document.body.classList.add('has-active-journey');
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
      if (leg.mode === 'walk' && journey.walkRouting === 'unavailable') return;
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
    updateLeaveWidget();
    state.needsJourneyFit = true;
    fitJourneyBounds(journey);
    startLocationWatch();
    fetchVehicles();
    fetchApproach();
    maybeAutoGuidance();
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

  function focusFirstWalk() {
    const journey = state.selected, ride = transitLegs()[0];
    if (!journey || !ride || !ride.from || Date.now() >= timeValue(ride.departure)) return;
    if (state.guidance) exitStationGuidance(false);
    clearStationBrowse(false);
    state.firstWalkFocus = true;
    setPanel(false);
    state.followJourney = true;
    state.lastFollowFit = null;
    document.body.classList.add('walk-to-stop-active');
    $('follow-journey').setAttribute('aria-pressed', 'false');
    $('follow-journey').querySelector('span').textContent = 'Show full journey';
    state.firstWalkLayers.clearLayers();
    const firstRideIndex = journey.legs.indexOf(ride);
    journey.legs.slice(0, firstRideIndex).filter(leg => leg.mode === 'walk' && leg.routing === 'street' && Array.isArray(leg.geometry) && leg.geometry.length >= 2).forEach(leg => {
      L.polyline(leg.geometry, { color: '#fff', weight: 9, interactive: false }).addTo(state.firstWalkLayers);
      L.polyline(leg.geometry, { color: '#456b2c', weight: 5, dashArray: '4 8', lineCap: 'round', interactive: false }).addTo(state.firstWalkLayers);
    });
    const stop = L.circleMarker([ride.from.lat, ride.from.lon], { radius: 9, color: '#14201d', weight: 3, fillColor: '#c2f65b', fillOpacity: 1 }).addTo(state.firstWalkLayers);
    stop.bindTooltip(make('span', '', `Board here · ${ride.from.name}`), { permanent: true, direction: 'top', className: 'first-walk-stop-label' });
    if (map.hasLayer(journeyLayers)) map.removeLayer(journeyLayers);
    startLocationWatch();
    fitJourneyBounds(journey);
  }

  function fitFirstWalkBounds(journey, force) {
    const ride = transitLegs(journey)[0];
    if (!ride || Date.now() >= timeValue(ride.departure)) { setFollow(true); return; }
    const bounds = L.latLngBounds([[ride.from.lat, ride.from.lon]]);
    journey.legs.slice(0, journey.legs.indexOf(ride)).filter(leg => leg.mode === 'walk' && leg.routing === 'street').forEach(leg => {
      (leg.geometry || []).forEach(point => bounds.extend(point));
    });
    const origin = state.userLocation || state.origin;
    if (origin) bounds.extend([origin.lat, origin.lon]);
    const previous = state.lastFollowFit;
    const center = bounds.getCenter();
    if (!force && previous && previous.leg === 'first-walk' && Date.now() - previous.at < 8000) return;
    state.lastFollowFit = { leg: 'first-walk', at: Date.now(), center };
    state.programmaticView = true;
    clearTimeout(state.viewTimer);
    map.stop();
    const mobile = isMobile(), size = map.getSize();
    const labelPadding = mobile ? Math.min(100, Math.max(60, (size.x - 160) / 2)) : 135;
    const launcher = $('open-panel').getBoundingClientRect();
    map.fitBounds(bounds, { maxZoom: 18, paddingTopLeft: state.mapOnly ? [35, mapOnlyTop()] : [labelPadding + 5, 165],
      paddingBottomRight: state.mapOnly ? [35, 80] : [labelPadding + 15, mobile ? Math.max(120, size.y - launcher.top + 20) : 100], animate: !force && !reducedMotion });
    state.viewTimer = setTimeout(() => { state.programmaticView = false; }, 850);
  }

  function fitCurrentLeg(journey, force) {
    const now = Date.now(), ride = nextLeg(), legs = journey.legs || [];
    const moving = ride && timeValue(ride.departure) <= now;
    const vehicle = approachVehicle() || state.vehicles.find(item => ride && String(item.tripId) === String(ride.tripId));
    let leg = legs.find(item => timeValue(item.arrival) >= now) || legs[legs.length - 1];
    if (ride && !moving) {
      const index = legs.indexOf(ride);
      leg = [...legs.slice(0, index)].reverse().find(item => item.mode === 'walk') || ride;
    }
    if (!leg) return;
    let point = moving && vehicle ? vehicle : leg.from;
    if ((!moving || leg.mode === 'walk') && state.userLocation && point && map.distance([point.lat, point.lon], [state.userLocation.lat, state.userLocation.lon]) < 3000) point = state.userLocation;
    if (!point) return;
    const previous = state.lastFollowFit, key = `${leg.mode}:${leg.tripId || legs.indexOf(leg)}`;
    if (!force && previous && previous.leg === key && now - previous.at < 8000) return;
    const mobile = isMobile(), size = map.getSize(), panel = $('journey-panel').getBoundingClientRect();
    const left = !mobile && getComputedStyle($('journey-panel')).display !== 'none' ? panel.right + 20 : 25;
    const top = state.mapOnly ? mapOnlyTop() : mobile ? 155 : 110, bottom = state.mapOnly ? 80 : mobile ? 200 : 100;
    const zoom = leg.mode === 'walk' ? 17 : STREET_ZOOM;
    const center = map.unproject(map.project([point.lat, point.lon], zoom).add(L.point((size.x - left - 70) / 2 + left - size.x / 2, (size.y - top - bottom) / 2 + top - size.y / 2).multiplyBy(-1)), zoom);
    state.lastFollowFit = { center, at: now, leg: key, zoom };
    state.programmaticView = true; clearTimeout(state.viewTimer); map.stop();
    map.setView(center, zoom, { animate: !force && !reducedMotion, duration: .7 });
    state.viewTimer = setTimeout(() => { state.programmaticView = false; }, 850);
  }

  function fitJourneyBounds(journey, force = true) {
    if (!state.followJourney || !journey || !map || state.pickField || state.guidance) return;
    if (state.zooming) { state.fitAfterZoom = true; return; }
    if (state.firstWalkFocus) { fitFirstWalkBounds(journey, force); return; }
    if (!state.fullJourneyOverview) { fitCurrentLeg(journey, force); return; }
    const bounds = L.latLngBounds([]);
    const vehicle = approachVehicle() || state.vehicles.find(item => state.nextTrip && String(item.tripId) === state.nextTrip);
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
    if (state.approachData && timeValue(state.approachData.arrival) > now && Array.isArray(state.approachData.geometry)) state.approachData.geometry.forEach(point => bounds.extend(point));
    if (vehicle) bounds.extend([vehicle.lat, vehicle.lon]);
    if (state.userLocation) bounds.extend([state.userLocation.lat, state.userLocation.lon]);
    if (state.destination) bounds.extend([state.destination.lat, state.destination.lon]);
    if (bounds.isValid()) {
      const mobile = isMobile();
      const panel = $('journey-panel').getBoundingClientRect();
      const launcher = $('open-panel').getBoundingClientRect();
      const activity = document.querySelector('.network-card').getBoundingClientRect();
      const leave = $('leave-widget').getBoundingClientRect();
      const size = map.getSize();
      const topLeft = state.mapOnly ? L.point(35, mapOnlyTop()) : L.point(mobile ? 35 : panel.right + 24, mobile ? state.panelOpen ? 150 : Math.max(150, leave.bottom + 70) : 105);
      const bottomRight = state.mapOnly ? L.point(35, 80) : L.point(mobile ? 70 : 95, mobile ? state.panelOpen ? size.y - panel.top + 16 : size.y - Math.min(launcher.top, activity.top || launcher.top) + 20 : 145);
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
    renderArrivalSummary();
    const container = $('itinerary');
    container.replaceChildren();
    const stationLabel = (stop, transfer) => {
      if (!stop) return document.createTextNode('Stop');
      if (!transfer) return make('span', '', stop.name);
      const button = make('button', 'transfer-station-button', stop.name);
      button.type = 'button'; button.addEventListener('click', () => openStationGuidance(transfer));
      return button;
    };
    (state.selected.legs || []).forEach((leg, index) => {
      const virtualTransfer = state.transfers.find(item => item.nextIndex === index && !item.walkingIndexes.length);
      if (virtualTransfer) {
        const button = make('button', 'transfer-guidance-button transfer-only-row', `Station guidance · ${virtualTransfer.to.name}`);
        button.type = 'button'; button.addEventListener('click', () => openStationGuidance(virtualTransfer));
        container.append(button);
      }
      const row = make('div', 'itinerary-leg');
      const walk = leg.mode === 'walk';
      const transfer = state.transfers.find(item => item.walkingIndexes.includes(index));
      const modeIcon = make('span', `leg-icon${walk ? '' : ' transit'}`);
      modeIcon.append(icon(leg.mode));
      if (!walk) styleRoute(modeIcon, leg);
      modeIcon.setAttribute('aria-hidden', 'true');
      const info = make(transfer ? 'button' : 'div', `leg-text${transfer ? ' transfer-guidance-button' : ''}`);
      if (transfer) { info.type = 'button'; info.addEventListener('click', () => openStationGuidance(transfer)); }
      const duration = (timeValue(leg.arrival) - timeValue(leg.departure)) / 1000;
      const unavailable = walk && state.selected.walkRouting === 'unavailable';
      info.append(make('strong', '', unavailable ? 'Walking route unavailable' : walk ? `Walk to ${leg.to ? leg.to.name : 'your stop'}` : `${leg.mode === 'train' ? 'Train' : leg.mode === 'ferry' ? 'Ferry' : 'Bus'} ${leg.routeName || ''} · ${leg.headsign || (leg.to ? leg.to.name : '')}`));
      if (walk) info.append(make('span', '', unavailable ? 'Choose another starting point' : `${minutes(duration)} min ${leg.accessOnly ? 'approximate access to mapped path' : 'walk'}${state.selected.walkRouting === 'street' ? '' : ' · approximate'}`));
      else {
        const stops = make('span', 'leg-stops');
        stops.append(stationLabel(leg.from, state.transfers.find(item => item.nextIndex === index)), document.createTextNode(' → '), stationLabel(leg.to, state.transfers.find(item => item.previousIndex === index)));
        info.append(stops);
      }
      if (transfer) info.append(make('span', 'transfer-action-copy', 'Station guidance →'));
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
    renderLastMileUber();
  }

  function lastMileUberOffer() {
    if (!state.searchModes.uber) return null;
    const journey = state.selected, legs = journey?.legs || [];
    if (!journey || !state.followJourney || state.journeyMode !== 'timetable' || state.status?.mode !== 'timetable'
      || state.status.expired || journey.mode === 'demo' || journey.walkRouting === 'unavailable' || legs.length < 2) return null;
    const walk = legs[legs.length - 1], ride = legs[legs.length - 2];
    if (walk.mode !== 'walk' || !['bus', 'train', 'ferry'].includes(ride.mode) || !ride.tripId) return null;
    const seconds = walk.duration == null ? (timeValue(walk.arrival) - timeValue(walk.departure)) / 1000 : Number(walk.duration);
    if (!Number.isFinite(seconds) || seconds <= LAST_MILE_UBER_THRESHOLD_SECONDS || timeValue(journey.arrival) <= Date.now()) return null;
    const point = p => p && Number.isFinite(Number(p.lat)) && Number.isFinite(Number(p.lon))
      && Number(p.lat) >= -33.5 && Number(p.lat) <= -30.5 && Number(p.lon) >= 114.5 && Number(p.lon) <= 117.5;
    if (!point(walk.from) || !point(walk.to)) return null;
    const distanceKm = map.distance([walk.from.lat, walk.from.lon], [walk.to.lat, walk.to.lon]) / 1000;
    if (distanceKm <= 0) return null;
    const fare = Math.max(LAST_MILE_UBER_FARE.minimumAud, LAST_MILE_UBER_FARE.baseAud + distanceKm * LAST_MILE_UBER_FARE.roadFactor * LAST_MILE_UBER_FARE.perKmAud);
    const low = Math.max(LAST_MILE_UBER_FARE.minimumAud, Math.floor(fare * .85)), high = Math.ceil(fare * 1.4);
    const url = new URL('https://m.uber.com/ul/'); url.searchParams.set('action', 'setPickup');
    for (const [key, p, fallback] of [['pickup', walk.from, 'Alighting stop'], ['dropoff', walk.to, 'Journey destination']]) {
      url.searchParams.set(`${key}[latitude]`, String(Number(p.lat)));
      url.searchParams.set(`${key}[longitude]`, String(Number(p.lon)));
      url.searchParams.set(`${key}[nickname]`, String(p.name || fallback).slice(0, 160));
    }
    return { href: url.href, low, high, walkingMinutes: Math.ceil(seconds / 60), from: walk.from.name || 'Alighting stop', to: walk.to.name || 'Your destination' };
  }

  function renderLastMileUber() {
    const card = $('last-mile-uber'); if (!card) return;
    const offer = lastMileUberOffer(); card.hidden = !offer;
    if (!offer) { card.replaceChildren(); card.dataset.offer = ''; return; }
    const signature = JSON.stringify(offer); if (card.dataset.offer === signature) return;
    card.dataset.offer = signature;
    const price = make('strong', 'last-mile-price', `Approx. A$${offer.low}–${offer.high}`);
    const link = make('a', 'last-mile-link', 'Open in Uber');
    link.href = offer.href; link.target = '_blank'; link.rel = 'noopener noreferrer';
    link.setAttribute('aria-label', 'Open in Uber for the final walking leg');
    card.replaceChildren(make('strong', 'last-mile-title', 'Uber for the final leg'), price,
      make('span', 'last-mile-saving', `Saves ${offer.walkingMinutes} min walking`),
      make('span', 'last-mile-endpoints', `${offer.from} → ${offer.to}`), link,
      make('small', 'last-mile-note', 'Rough fare estimate. Confirm pickup and fare in Uber.'));
  }

  function updateNextService() {
    renderMapOnlyServices();
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
    const countdown = boardingCountdown(leg);
    const time = make('div', 'next-countdown', countdown.value);
    time.append(make('small', '', countdown.label));
    const routeBadge = make('div', 'next-route', serviceLabel(leg));
    styleRoute(routeBadge, leg);
    routeBadge.title = leg.routeName || mode;
    main.append(routeBadge, info, time);
    const isVisible = Boolean(approachVehicle()) || state.vehicles.some(vehicle => String(vehicle.tripId) === String(leg.tripId));
    const footnote = state.status && state.status.mode === 'demo' ? 'Demo only. Vehicle movement and journey times are illustrative.' : (isVisible ? 'Bright marker = your service. Other vehicles on this line stay visible. Positions are estimates.' : 'Other vehicles on your line stay visible. Your service marker appears while the trip is running.');
    container.append(heading, main, timetableButton('View timetable', tripQuery(leg), 'next-timetable'), make('div', 'next-footnote', footnote));
  }

  function clearJourney(showMessage = true) {
    clearWalkingAccess();
    if (showMessage) { try { localStorage.removeItem('nextstop-active-trip-v1'); } catch {} state.restoredTripView = null; $('forget-saved-trip').hidden = true; }
    cancelPendingPlan();
    if (state.guidance) exitStationGuidance(false);
    state.selected = null;
    renderArrivalSummary();
    state.journeyMode = null;
    renderLastMileUber();
    state.firstWalkFocus = false;
    document.body.classList.remove('walk-to-stop-active');
    if (state.firstWalkLayers) state.firstWalkLayers.clearLayers();
    state.transfers = [];
    state.dismissedTransfers = new Set();
    document.body.classList.remove('has-active-journey');
    clearApproach();
    updateLeaveWidget();
    state.journeys = [];
    state.nextTrip = null;
    state.wholeNetwork = false;
    state.needsJourneyFit = false;
    state.lastFollowFit = null;
    $('follow-journey').hidden = true;
    if (journeyLayers) journeyLayers.clearLayers();
    $('journey-results').hidden = true;
    document.querySelector('.journey-panel').classList.remove('has-journey');
    $('plan-button').disabled = false;
    $('plan-button').firstElementChild.textContent = 'Find my next ride';
    if (showMessage) message('Back to the network. Choose a destination to plan another journey.');
    if (map) { fetchVehicles(); fetchNearbyBus(true); }
    drawNetworkLines();
    updateLauncher();
    scheduleStationBrowse();
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
    content.append(styleRoute(make('b', 'route-chip', vehicle.routeName || 'Service'), vehicle));
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
      if (state.nearbyBusMarker && state.nearbyBusData?.vehicle && String(state.nearbyBusData.vehicle.id) === id) return;
      const incoming = approachVehicle();
      if (incoming && state.approachMarker && String(incoming.id) === id) return;
      retained.add(id);
      const highlighted = Boolean(state.nextTrip && String(vehicle.tripId) === state.nextTrip);
      let marker = state.markers.get(id);
      if (!marker) {
        marker = L.marker([vehicle.lat, vehicle.lon], { icon: vehicleIcon(vehicle, highlighted), zIndexOffset: highlighted ? 4000 : 300, bubblingMouseEvents: false }).addTo(map);
        marker.bindPopup(servicePopup(vehicle, highlighted));
        state.markers.set(id, marker);
      } else {
        marker.setIcon(vehicleIcon(vehicle, highlighted));
        marker.setZIndexOffset(highlighted ? 4000 : 300);
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
    $('network-count').textContent = `${count}${data.truncated ? '+' : ''} ${count === 1 ? 'service' : 'services'} ${demo ? 'in demo' : 'scheduled now'}`;
    $('network-detail').textContent = data.truncated ? 'Zoom in to see all services in an area' : state.selected && !state.wholeNetwork ? 'Your selected lines, trains and ferries · updates every 15 sec' : 'In this map area · updates every 15 sec';
  }

  async function fetchVehicles() {
    if (!map) return;
    if (state.vehicleController) state.vehicleController.abort();
    state.vehicleController = new AbortController();
    const request = ++state.requestNumber;
    const bounds = map.getBounds();
    const params = { bbox: [bounds.getWest(), bounds.getSouth(), bounds.getEast(), bounds.getNorth()].join(','), lines: '0', rail: map.getZoom() < STREET_ZOOM || (state.selected && transitLegs().length === 0 && !state.wholeNetwork) ? 'only' : 'always' };
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
    map = L.map('map', { zoomControl: false, center: [state.origin.lat, state.origin.lon], zoom: STREET_ZOOM, minZoom: 9, maxZoom: 19, zoomAnimation: !reducedMotion, preferCanvas: true });
    if (isMobile()) {
      map.setView([state.origin.lat, state.origin.lon], STREET_ZOOM, { animate: false });
      map.panBy([0, Math.round(window.innerHeight * .25)], { animate: false });
    }
    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors' }).addTo(map);
    let tilesLoaded = 0;
    tiles.on('tileload', () => { tilesLoaded++; if (!$('map-warning').hidden) { $('map-warning').hidden = true; drawNearbyLabels(); } });
    tiles.on('tileerror', () => { if (!tilesLoaded && $('map-warning').hidden) { $('map-warning').hidden = false; drawNearbyLabels(); } });
    networkLayers = L.layerGroup().addTo(map);
    journeyLayers = L.layerGroup().addTo(map);
    stopLayers = L.layerGroup().addTo(map);
    state.phoneLayers = L.layerGroup().addTo(map);
    map.createPane('approachPane');
    map.getPane('approachPane').style.zIndex = '450';
    map.getPane('approachPane').style.pointerEvents = 'none';
    state.approachRenderer = L.svg({ pane: 'approachPane', padding: .5 });
    state.approachLayers = L.layerGroup().addTo(map);
    state.firstWalkLayers = L.layerGroup().addTo(map);
    map.createPane('guidancePane');
    map.getPane('guidancePane').style.zIndex = '470';
    map.getPane('guidancePane').style.pointerEvents = 'none';
    state.guidanceRenderer = L.svg({ pane: 'guidancePane', padding: .5 });
    state.guidanceLayers = L.layerGroup().addTo(map);
    map.createPane('stationDetailPane');
    map.getPane('stationDetailPane').style.zIndex = '430';
    state.stationMapRenderer = L.svg({ pane: 'stationDetailPane', padding: .5 });
    state.stationMapLayers = L.layerGroup().addTo(map);
    state.stationBrowseLayers = L.layerGroup().addTo(map);
    map.createPane('stationLabelPane');
    map.getPane('stationLabelPane').style.zIndex = '445';
    map.getPane('stationLabelPane').style.pointerEvents = 'none';
    updatePlaceMarker('origin');
    map.on('click', event => {
      if (state.mapOnly) return;
      if (state.pickField) { finishMapPick(event.latlng); return; }
      if (state.guidance || state.firstWalkFocus) return;
      if (isMobile() && !state.pickField) { setPanel(false); return; }
      const field = state.pickField || 'destination';
      setPlace(field, { lat: event.latlng.lat, lon: event.latlng.lng, name: field === 'origin' ? 'Starting point on map' : 'Destination on map' });
      message(field === 'destination' ? 'Destination set on the map. Find your next ride below.' : 'Starting point set on the map.');
      if (isMobile()) setPanel(false);
    });
    map.on('moveend', () => {
      if (state.nearbyBusData) drawNearbyLabels();
      drawNetworkLines(); clearTimeout(moveTimer); moveTimer = setTimeout(fetchVehicles, 350);
      if (map.getZoom() >= STREET_ZOOM) {
        if (state.approachData && nextLeg()) { renderApproachVehicle(); drawApproach(state.approachData, nextLeg()); }
        fetchApproach();
      }
      scheduleStationBrowse();
      if (state.toiletsEnabled) { clearTimeout(state.toiletMoveTimer); state.toiletMoveTimer = setTimeout(() => fetchToilets(false), 450); }
      if (state.phonesEnabled) { clearTimeout(state.phonesMoveTimer); state.phonesMoveTimer = setTimeout(() => fetchPhones(false), 450); }
    });
    map.on('dragstart', pauseMapFollowing);
    map.on('zoomstart', () => { state.zooming = true; if (!state.programmaticView) pauseMapFollowing(); });
    map.on('zoomend', () => {
      state.zooming = false;
      if (state.phonesEnabled) renderPhones();
      if (state.toiletsEnabled) renderToilets();
      const pendingFit = state.fitAfterZoom;
      state.fitAfterZoom = false;
      if (pendingFit && state.selected && state.followJourney) {
        requestAnimationFrame(() => { if (state.selected && state.followJourney) fitJourneyBounds(state.selected); });
      }
      if (state.guidance && state.guidance.fitPending) { state.guidance.fitPending = false; requestAnimationFrame(() => fitStationGuidance()); }
    });
    map.getContainer().addEventListener('wheel', pauseMapFollowing, { passive: true });
    map.getContainer().addEventListener('touchstart', event => { if (event.touches.length > 1) pauseMapFollowing(); }, { passive: true });
    map.getContainer().addEventListener('keydown', event => {
      if (state.mapOnly && event.key === 'Enter') return;
      if (state.pickField && event.key === 'Enter') { event.preventDefault(); finishMapPick(map.getCenter()); }
      else if (state.pickField && event.key === 'Escape') { event.preventDefault(); cancelMapPick(); }
      else if (['+', '-', '='].includes(event.key)) pauseMapFollowing();
    });
    let resizeTimer;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => { map.invalidateSize({ pan: false }); setPanel(state.panelOpen); if (state.guidance) fitStationGuidance(); else if (state.selected) fitJourneyBounds(state.selected); else if (state.nearbyCamera) fitNearbyView(state.nearbyCamera === 'full'); else drawNearbyLabels(); }, 150);
    });

    $('open-panel').addEventListener('click', event => { if (event.detail === 0 || Date.now() >= state.suppressSheetClickUntil) { if (state.firstWalkFocus) setFollow(true); setPanel(true, true); } });
    $('close-panel').addEventListener('click', () => { setPanel(false); $('open-panel').focus({ preventScroll: true }); });
    $('sheet-handle').addEventListener('click', event => { if (event.detail === 0 || Date.now() >= state.suppressSheetClickUntil) { setPanel(false); $('open-panel').focus({ preventScroll: true }); } });
    bindSheetGesture($('sheet-handle'), false);
    bindSheetGesture($('open-panel'), true);
    document.body.classList.add('mascot-visible');
    $('mascot-peek').classList.add('is-arriving');
    setTimeout(() => $('mascot-peek').classList.remove('is-arriving'), 1200);
    ['pointerdown', 'pointerup', 'dblclick'].forEach(name => $('mascot-peek').addEventListener(name, event => event.stopPropagation()));
    $('mascot-peek').addEventListener('click', event => {
      event.preventDefault(); event.stopPropagation();
      state.mascotTucked = true;
      $('mascot-peek').classList.add('is-tucked');
      document.body.classList.remove('mascot-visible');
      setTimeout(() => { $('mascot-peek').hidden = true; if (state.selected && state.followJourney) fitJourneyBounds(state.selected); }, 250);
    });
    $('exit-guidance').addEventListener('click', () => exitStationGuidance());
    $('guidance-target-name').addEventListener('click', () => { if (state.guidance) { state.guidance.following = true; fitStationGuidance(); } });
    $('confirm-guidance').addEventListener('click', () => {
      if (!state.guidance) return;
      state.guidance.confirmed = true;
      $('confirm-guidance').textContent = 'Stop confirmed'; $('confirm-guidance').disabled = true;
      updateGuidanceTarget();
    });
    $('guidance-timetable').addEventListener('click', () => { if (state.guidance) openTimetable(tripQuery(state.guidance.transfer.next)); });
    $('station-map-details').addEventListener('toggle', () => { if (state.guidance && state.guidance.following) fitStationGuidance(); });
    $('timetable-close').addEventListener('click', () => $('timetable-dialog').close());
    $('timetable-back').addEventListener('click', () => {
      if (state.timetableHistory.length < 2) return;
      state.timetableHistory.pop();
      openTimetable(state.timetableHistory[state.timetableHistory.length - 1], false);
    });
    $('timetable-dialog').addEventListener('close', () => {
      const suspended = state.mapOnlyClosedDialogs.get($('timetable-dialog')) || 0;
      if (suspended) { state.mapOnlyClosedDialogs.set($('timetable-dialog'), suspended - 1); return; }
      if (state.timetableController) state.timetableController.abort();
      state.timetableNumber++;
      state.timetableHistory = [];
      if (state.timetableFocus && state.timetableFocus.isConnected) state.timetableFocus.focus({ preventScroll: true });
      else (isMobile() && !state.panelOpen ? $('open-panel') : $('journey-panel')).focus({ preventScroll: true });
    });

    $('journey-form').addEventListener('submit', event => { event.preventDefault(); plan(); });
    try { const saved=JSON.parse(localStorage.getItem('nextstop-search-modes-v1')||'null');if(saved)for(const mode of Object.keys(state.searchModes))if(typeof saved[mode]==='boolean')state.searchModes[mode]=saved[mode]; } catch {}
    syncSearchModes();
    document.querySelectorAll('[data-search-mode]').forEach(b=>b.addEventListener('click',()=>{
      const mode=b.dataset.searchMode;state.searchModes[mode]=!state.searchModes[mode];syncSearchModes();
      try{localStorage.setItem('nextstop-search-modes-v1',JSON.stringify(state.searchModes));}catch{}
      if(mode==='uber'){renderLastMileUber();return;}
      clearJourney(false);saveTripSearch();message('Updating routes for your travel options…');maybeAutoPlan();
    }));
    $('forget-saved-trip').addEventListener('click', () => {
      clearJourney(); state.destination = null; $('destination-input').value = '';
      updatePlaceMarker('destination'); updateLauncher();
    });
    document.querySelectorAll('[data-journey-time]').forEach(button => button.addEventListener('click', () => {
      if (state.timeMode === button.dataset.journeyTime) return;
      state.timeMode = button.dataset.journeyTime;
      if (state.timeMode === 'arrive' && (!$('arrival-date').value || arrivalTiming().error)) {
        setArrivalInputs(Math.ceil((Date.now() + 3600000) / 300000) * 300000);
      }
      syncJourneyTiming(); changeJourneyTiming();
    }));
    ['arrival-date', 'arrival-time'].forEach(id => {
      $(id).addEventListener('focus', refreshArrivalLimits);
      $(id).addEventListener('change', changeJourneyTiming);
    });
    document.querySelectorAll('[data-arrival-minutes]').forEach(button => button.addEventListener('click', () => {
      setArrivalInputs(Math.ceil((Date.now() + Number(button.dataset.arrivalMinutes) * 60000) / 60000) * 60000);
      changeJourneyTiming();
    }));
    syncJourneyTiming();
    ['origin', 'destination'].forEach(field => {
      const input = $(`${field}-input`);
      input.addEventListener('focus', () => { state.field = field; });
      input.addEventListener('click', () => { if (input.readOnly) beginMapPick(field); });
      input.addEventListener('input', () => {
        clearWalkingAccess();
        cancelPendingPlan();
        state[field] = null;
        state.restoredTripView = null; state.restoredTripId = null;
        saveTripSearch();
        if (field === 'origin') { state.originUserChosen = true; state.awaitingGpsOrigin = false; }
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
        if (input.readOnly && ['Enter', ' '].includes(event.key)) { event.preventDefault(); beginMapPick(field); }
        else if (event.key === 'Enter') { event.preventDefault(); search(field); }
        if (event.key === 'Escape') $('search-results').hidden = true;
      });
    });
    document.querySelectorAll('[data-search]').forEach(button => button.addEventListener('click', () => {
      const field = button.dataset.search;
      if (state.addressEditField === field) search(field); else editAddress(field);
    }));
    $('use-location').addEventListener('click', locate);
    $('map-locate').addEventListener('click', locate);
    $('map-phones').addEventListener('click', togglePhones);
    $('map-toilets').addEventListener('click', toggleToilets);
    $('station-browse-toggle').addEventListener('click', () => {
      const station = state.stationBrowseCandidate;
      if (!station || state.selected || state.guidance || state.pickField) return;
      if (state.stationBrowse) { state.stationBrowseSuppressed = station.id; clearStationBrowse(); stationBrowseCard(station, false); }
      else { state.stationBrowseSuppressed = null; openStationBrowse(station); }
    });
    $('choose-on-map').addEventListener('click', () => {
      const field = state.field || 'destination';
      beginMapPick(field);
      $('search-results').hidden = true;
    });
    $('cancel-map-pick').addEventListener('click', cancelMapPick);
    $('use-map-center').addEventListener('click', () => finishMapPick(map.getCenter()));
    $('clear-journey').addEventListener('click', () => clearJourney());
    $('overview-journey').addEventListener('click', () => { setFollow(true); state.fullJourneyOverview = true; fitJourneyBounds(state.selected); });
    $('network-toggle').addEventListener('click', () => {
      state.wholeNetwork = !state.wholeNetwork;
      $('network-toggle').setAttribute('aria-pressed', String(state.wholeNetwork));
      drawNetworkLines();
      fetchVehicles();
    });
    $('follow-journey').addEventListener('click', () => {
      if (state.firstWalkFocus) { setFollow(true); state.fullJourneyOverview = true; fitJourneyBounds(state.selected); $('follow-journey').querySelector('span').textContent = 'Follow current leg'; }
      else setFollow(true);
    });
    $('leave-widget').addEventListener('click', focusFirstWalk);
    $('map-zoom-in').addEventListener('click', () => { pauseMapFollowing(); map.zoomIn(); });
    $('map-zoom-out').addEventListener('click', () => { pauseMapFollowing(); map.zoomOut(); });
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        fetchVehicles(); loadStatus(); startLocationWatch(); fetchApproach(); fetchNearbyBus(true); fetchToilets();
        if (!state.routeCatalog) { state.routeRetryCount = 0; refreshRouteCatalog(); }
      } else {
        stopLocationWatch(); clearNearbyBus(); clearTimeout(state.routeRetryTimer);
        state.markers.forEach(marker => { if (marker.nextstopAnimation) cancelAnimationFrame(marker.nextstopAnimation); });
      }
    });
    bindMapOnlyControls();
    updateClock();
    setPanel(false);
    loadStatus();
    loadRouteCatalog();
    loadStationBrowseCatalog();
    fetchVehicles();
    if (navigator.permissions && navigator.permissions.query) {
      navigator.permissions.query({ name: 'geolocation' }).then(permission => {
        const apply = () => {
          if (permission.state === 'granted') { state.locationConsent = true; state.awaitingGpsOrigin = !state.originUserChosen; startLocationWatch(); }
          else if (permission.state === 'denied') { state.locationConsent = false; clearNearbyBus(); stopLocationWatch(); }
        };
        apply();
        if (permission.addEventListener) permission.addEventListener('change', apply);
      }).catch(() => {});
    }
    setInterval(() => { updateClock(); updateNextService(); updateLauncher(); if (!document.hidden) { fetchVehicles(); fetchApproach(); fetchNearbyBus(); } }, 15000);
    setInterval(() => {
      updateLeaveWidget(); updateBoardingCountdown(); updateNearbyBusCountdown(); renderMapOnlyServices();
      if ($('last-mile-uber') && !$('last-mile-uber').hidden && timeValue(state.selected?.arrival) <= Date.now()) renderLastMileUber();
      updateGuidanceTarget();
      if (!document.hidden) maybeAutoGuidance();
      if (state.approachData) {
        const leg = nextLeg();
        if (!leg || String(leg.tripId) !== String(state.approachData.tripId) || !approachVehicle()) { clearApproach(); if (!document.hidden) { fetchApproach(); fetchVehicles(); } }
        else if (timeValue(state.approachData.arrival) <= Date.now()) state.approachLayers.clearLayers();
      }
      if (state.firstWalkFocus && (!transitLegs()[0] || timeValue(transitLegs()[0].departure) <= Date.now())) setFollow(true);
    }, 1000);
    setInterval(() => { if (!document.hidden) loadStatus(); }, 60000);
    setInterval(() => { if (!document.hidden) refreshRouteCatalog(); }, 300000);
    setInterval(() => { if (!document.hidden) fetchToilets(); }, 120000);
  }

  window.Nextstop = {
    getMap: () => map,
    styleRoute,
    searchPreferences,
    choosePlace: place => {
      if (state.onboard) {
        const explore = point => {
          if (!point || !state.onboard) return;
          state.onboard.place = { ...point, name: place.name }; state.nearbyFitPending = true; fetchNearbyBus(true);
        };
        if (place.requiresEntrance) window.Nextstop.pickLocation(place, explore); else explore(place);
      } else chooseSearchPlace('destination', place);
    },
    pickLocation: (point, callback) => {
      const previous = { callback, field: state.field, panelOpen: state.panelOpen, follow: state.followJourney };
      beginMapPick('destination'); state.externalPick = previous;
      map.setView([point.lat, point.lon], 18, { animate: false });
      $('map-hint-text').textContent = `Choose the entrance to ${point.name || 'this place'}`;
    },
    getOrigin: () => ({ ...(state.origin || {}) }),
    getMapCenter: () => { const point = map.getCenter(); return { lat: point.lat, lon: point.lng }; },
    showJourney: (journey, from, to) => {
      cancelPendingPlan(); state.origin = { ...from }; state.destination = { ...to }; state.originUserChosen = true;
      state.timeMode = journey.arriveBy ? 'arrive' : 'now';
      if (journey.arriveBy) setArrivalInputs(journey.arriveBy);
      syncJourneyTiming();
      $('origin-input').value = from.name; $('destination-input').value = to.name;
      updatePlaceMarker('origin'); updatePlaceMarker('destination'); state.journeys = [journey]; renderJourneyOptions();
      state.journeyMode = journey.mode === 'demo' ? 'demo' : journey.mode === 'timetable' || journey.walkRouting === 'street' ? 'timetable' : null;
      $('journey-results').hidden = false; $('journey-panel').classList.add('has-journey'); selectJourney(journey); setPanel(false);
    }
  };
  init();
  restoreTripSearch();
  map.on('moveend', saveTripView);
  window.addEventListener('pagehide', saveTripView);
})();
