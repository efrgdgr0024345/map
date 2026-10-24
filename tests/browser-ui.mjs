/**
 * Real-API browser checks for Nextstop. Requires an already running app with a
 * current imported GTFS feed and Playwright available to Node.
 * Run: node tests/browser-ui.mjs (optional BASE_URL=http://127.0.0.1:8080/).
 * Street-map TLS failures are reported separately; verification stays enabled.
 */
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const base = process.env.BASE_URL || 'http://127.0.0.1:8080/';
let checks = 0;
const check = (condition, label) => { assert.ok(condition, label); checks++; console.log(`PASS: ${label}`); };
const executablePath = process.env.CHROMIUM_BINARY || (existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined);
const browser = await chromium.launch({ headless: true, executablePath });
const browserErrors = [];
const contexts = [];

async function instrument(page) {
  const telemetry = { routes: [], vehicles: [], plans: [], fleets: [], errors: [], tileFailures: 0, requests: [] };
  // Observe the real Leaflet map, without changing its behavior or mocking data.
  await page.addInitScript(() => {
    let library;
    Object.defineProperty(window, 'L', {
      configurable: true,
      get() { return library; },
      set(value) {
        library = value;
        if (!value || value.__nextstopObserved) return;
        value.__nextstopObserved = true;
        const original = value.map;
        value.map = function (...args) {
          const map = original.apply(this, args);
          window.__nextstopTestMap = map;
          return map;
        };
      }
    });
  });
  page.on('pageerror', error => { telemetry.errors.push(error.message); browserErrors.push(error.message); });
  page.on('requestfailed', request => { if (request.url().includes('tile.openstreetmap.org')) telemetry.tileFailures++; });
  page.on('request', request => {
    telemetry.requests.push(request.url());
    const url = new URL(request.url());
    if (url.pathname.endsWith('/api.php')) {
      if (url.searchParams.get('action') === 'routes') telemetry.routes.push({ headers: request.headers() });
      if (url.searchParams.get('action') === 'vehicles') telemetry.vehicles.push({ url: request.url() });
    }
  });
  page.on('response', async response => {
    const url = new URL(response.url());
    if (url.pathname.endsWith('/api.php') && response.ok()) {
      try {
        if (url.searchParams.get('action') === 'plan') telemetry.plans.push(await response.json());
        if (url.searchParams.get('action') === 'vehicles') telemetry.fleets.push(await response.json());
      } catch {}
    }
  });
  return telemetry;
}

async function ready(page) {
  await page.goto(base, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => window.__nextstopTestMap && document.querySelectorAll('.vehicle-marker').length > 0);
  await page.waitForFunction(() => Number(document.querySelector('#map').dataset.networkLines) > 0 && document.querySelector('#map').dataset.routeRevision);
  await page.waitForFunction(() => document.querySelector('.brand-cat').complete && document.querySelector('.brand-cat').naturalWidth > 0);
}

async function openPanel(page) {
  if (await page.locator('#journey-panel').getAttribute('aria-hidden') === 'true') {
    await page.locator('#open-panel').click();
    await page.waitForFunction(() => document.querySelector('#journey-panel').getAttribute('aria-hidden') === 'false');
  }
}

async function backgroundClick(page, ratioX = .72, ratioY = .42) {
  const box = await page.locator('#map').boundingBox();
  await page.locator('#map').dispatchEvent('click', { clientX: box.x + box.width * ratioX, clientY: box.y + box.height * ratioY, button: 0, bubbles: true });
}

async function planClaremont(page) {
  await openPanel(page);
  await page.locator('#destination-input').fill('Claremont');
  await page.locator('#destination-input').press('Enter');
  await page.locator('.search-result').first().waitFor({ state: 'visible', timeout: 30000 });
  await page.locator('.search-result').first().click();
  await page.locator('#plan-button').click();
  await page.locator('.journey-option[aria-pressed="true"]').waitFor({ state: 'attached', timeout: 30000 });
  await page.locator('.vehicle-body.is-next').waitFor({ state: 'visible', timeout: 30000 });
}

async function cacheRecord(page) {
  return page.evaluate(() => new Promise((resolve, reject) => {
    const request = indexedDB.open('nextstop-public-network', 1);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const db = request.result;
      const transaction = db.transaction('routes', 'readonly');
      const read = transaction.objectStore('routes').get('perth');
      read.onsuccess = () => { const record = read.result; db.close(); resolve(record && { revision: record.revision, lines: record.lines.length, etag: record.etag }); };
      read.onerror = () => reject(read.error);
    };
  }));
}

async function waitCacheStored(page) {
  await page.waitForFunction(() => document.querySelector('#route-cache-status').textContent.includes('saved on this device'));
  return cacheRecord(page);
}

async function timetableChecks(page) {
  await openPanel(page);
  await page.locator('#next-service .next-timetable').scrollIntoViewIfNeeded();
  await page.locator('#next-service .next-timetable').click();
  await page.locator('.timetable-stop-row').first().waitFor({ state: 'visible' });
  check(await page.locator('#timetable-dialog').evaluate(dialog => dialog.open), 'selected service timetable opens inside the app');
  check(await page.locator('.timetable-stop-row').count() > 1, 'real service timetable lists multiple scheduled stops');
  const originalTitle = await page.locator('#timetable-title').textContent();
  await page.locator('.stop-timetable-link').first().click();
  await page.waitForFunction(() => document.querySelector('#timetable-description').textContent.includes('Upcoming departures'));
  check(await page.locator('.departure-row').count() > 0, 'stop timetable displays real upcoming departures');
  await page.locator('.departure-row').first().click();
  await page.locator('.timetable-stop-row').first().waitFor({ state: 'visible' });
  check(await page.locator('#timetable-back').isVisible(), 'in-app timetable navigation exposes a back action');
  await page.locator('#timetable-back').click();
  await page.waitForFunction(() => document.querySelector('#timetable-description').textContent.includes('Upcoming departures'));
  await page.keyboard.press('Escape');
  check(!(await page.locator('#timetable-dialog').evaluate(dialog => dialog.open)), 'Escape closes the timetable dialog without external navigation');
  check(page.url().startsWith(base), 'timetable browsing stays on the application URL');
  return originalTitle;
}

async function motionChecks(page, telemetry) {
  assert.ok(telemetry.fleets.length, 'fleet response available for motion checks');
  const data = telemetry.fleets.at(-1);
  const candidate = data.vehicles.find(vehicle => vehicle.motion?.frames?.some(frame => frame.offset > 0 && frame.offset <= 3 && Math.hypot(frame.lat - vehicle.lat, frame.lon - vehicle.lon) > .00003));
  assert.ok(candidate, 'real fleet has a scheduled moving service');
  const tracked = await page.evaluate(vehicle => {
    const map = window.__nextstopTestMap;
    let nearest, distance = Infinity;
    map.eachLayer(marker => {
      const element = marker.getElement?.();
      if (!marker.getLatLng || !element?.classList.contains('vehicle-marker') || !element.getAttribute('aria-label')?.includes(`${vehicle.mode} ${vehicle.routeName}, ${vehicle.headsign}`)) return;
      const d = map.distance(marker.getLatLng(), [vehicle.lat, vehicle.lon]);
      if (d < distance) { nearest = marker; distance = d; }
    });
    if (!nearest) return null;
    const point = nearest.getLatLng();
    return { id: nearest._leaflet_id, lat: point.lat, lon: point.lng, scheduled: Boolean(nearest.nextstopAnimation) };
  }, candidate);
  check(tracked && tracked.scheduled, 'real moving services use requestAnimationFrame rather than update-only jumps');
  await page.waitForFunction(previous => {
    const marker = window.__nextstopTestMap._layers[previous.id];
    return marker && window.__nextstopTestMap.distance(marker.getLatLng(), [previous.lat, previous.lon]) > 1;
  }, tracked, { timeout: 8000 });
  const position = await page.evaluate(id => {
    const point = window.__nextstopTestMap._layers[id].getLatLng();
    return { lat: point.lat, lon: point.lng };
  }, tracked.id);
  const latitudes = candidate.motion.frames.map(frame => frame.lat);
  const longitudes = candidate.motion.frames.map(frame => frame.lon);
  check(position.lat >= Math.min(...latitudes) - .0003 && position.lat <= Math.max(...latitudes) + .0003 && position.lon >= Math.min(...longitudes) - .0003 && position.lon <= Math.max(...longitudes) + .0003, 'intermediate marker motion remains within the authoritative short prediction path');
}

async function fitChecks(page, mobile) {
  await page.waitForFunction(() => !window.__nextstopTestMap._animatingZoom && !window.__nextstopTestMap._panAnim?._inProgress);
  const positions = await page.evaluate(() => {
    const map = window.__nextstopTestMap;
    const points = [];
    map.eachLayer(layer => {
      if (!layer.getLatLng || !layer.getElement) return;
      const element = layer.getElement();
      if (!element) return;
      if (element.querySelector('.vehicle-body.is-next') || (layer.options.title || '').startsWith('Destination:')) {
        const point = map.latLngToContainerPoint(layer.getLatLng());
        points.push({ x: point.x, y: point.y, next: !!element.querySelector('.vehicle-body.is-next') });
      }
    });
    const panel = document.querySelector('#journey-panel').getBoundingClientRect();
    const launcher = document.querySelector('#open-panel').getBoundingClientRect();
    return { points, width: innerWidth, height: innerHeight, panel: { right: panel.right, top: panel.top }, launcher: { top: launcher.top }, hidden: document.querySelector('#journey-panel').getAttribute('aria-hidden') === 'true' };
  });
  check(positions.points.length >= 2 && positions.points.some(point => point.next), 'best-fit view includes the selected vehicle and final destination');
  if (!positions.points.every(point => point.x > 8 && point.x < positions.width - 8 && point.y > 70 && point.y < positions.height - 20)) console.log('Fit diagnostic:', JSON.stringify(positions));
  check(positions.points.every(point => point.x > 8 && point.x < positions.width - 8 && point.y > 70 && point.y < positions.height - 20), 'journey anchors remain inside the visible viewport');
  if (!mobile) check(positions.points.every(point => point.x > positions.panel.right), 'desktop fit keeps journey anchors clear of the sidebar');
  if (mobile && positions.hidden) check(positions.points.every(point => point.y < positions.launcher.top - 8), 'collapsed phone fit keeps anchors above the search launcher');
}

try {
  const desktopContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'en-AU', timezoneId: 'Australia/Perth' });
  contexts.push(desktopContext);
  const desktop = await desktopContext.newPage();
  const desktopTelemetry = await instrument(desktop);
  await ready(desktop);
  const status = await desktop.request.get(new URL('api.php?action=status', base).href);
  check((await status.json()).mode === 'timetable', 'browser tests use an imported real timetable rather than demo data');
  const mascot = await desktop.locator('.brand-cat').evaluate(image => ({ src: image.currentSrc, width: image.naturalWidth }));
  check(mascot.src.includes('mascot-head.webp') && mascot.width === 256, 'actual black-cat mascot loads as the small WebP delivery asset');
  check(!desktopTelemetry.requests.some(url => url.includes('/mascot-head.png')), 'header avoids downloading the large mascot source PNG');
  const cache = await waitCacheStored(desktop);
  check(cache && cache.lines > 100 && cache.etag, 'public route geometry and its revision persist in IndexedDB');
  const routeRequestsBeforePan = desktopTelemetry.routes.length;
  await desktop.evaluate(() => { window.__nextstopTestMap.panBy([210, 0], { animate: false }); });
  await desktop.waitForFunction(() => Number(document.querySelector('#map').dataset.networkLines) > 0);
  await desktop.evaluate(() => { window.__nextstopTestMap.panBy([-210, 80], { animate: false }); });
  check(desktopTelemetry.routes.length === routeRequestsBeforePan, 'panning redraws cached route lines without downloading the catalogue again');
  check(desktopTelemetry.vehicles.every(item => new URL(item.url).searchParams.get('lines') === '0'), 'vehicle requests avoid repeatedly transferring static route geometry');
  await motionChecks(desktop, desktopTelemetry);
  await planClaremont(desktop);
  const planned = desktopTelemetry.plans.at(-1)?.journeys?.[0];
  check(planned && planned.legs.some(leg => leg.mode !== 'walk'), 'real journey planning selects a bus or train to Claremont');
  const expectedColor = planned.legs.find(leg => leg.mode !== 'walk').routeColor.toUpperCase();
  const markerDesign = await desktop.locator('.vehicle-body.is-next').evaluate(body => ({ color: body.style.getPropertyValue('--route-color').trim().toUpperCase(), glyph: !!body.querySelector(':scope > svg'), code: !!body.querySelector('.vehicle-code'), bodyTransform: getComputedStyle(body).transform, bearing: Number(body.querySelector('.vehicle-direction')?.dataset.bearing) }));
  check(markerDesign.color === expectedColor, 'selected marker uses the actual network colour from the timetable');
  check(markerDesign.glyph && markerDesign.code && markerDesign.bodyTransform === 'none' && markerDesign.bearing >= 0 && markerDesign.bearing < 360, 'vehicle symbol and route code stay upright while a separate arrow shows direction');
  await fitChecks(desktop, false);
  await timetableChecks(desktop);
  await desktop.locator('#map-zoom-in').click();
  await desktop.waitForFunction(() => document.querySelector('#follow-journey').getAttribute('aria-pressed') === 'false');
  check(true, 'manual map zoom pauses automatic journey following');
  await desktop.locator('#follow-journey').click();
  await desktop.waitForFunction(() => document.querySelector('#follow-journey').getAttribute('aria-pressed') === 'true');
  await fitChecks(desktop, false);
  check(true, 'follow control restores focused journey fitting');
  await desktop.screenshot({ path: '/tmp/nextstop-v3-desktop.png', fullPage: true });

  const mobileContext = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, locale: 'en-AU', timezoneId: 'Australia/Perth', geolocation: { latitude: -31.9505, longitude: 115.8586, accuracy: 10 }, permissions: ['geolocation'] });
  contexts.push(mobileContext);
  const mobile = await mobileContext.newPage();
  const mobileTelemetry = await instrument(mobile);
  await ready(mobile);
  check(await mobile.locator('#journey-panel').getAttribute('aria-hidden') === 'true' && await mobile.locator('#journey-panel').evaluate(panel => panel.inert), 'phone search panel starts collapsed and inaccessible until opened');
  await openPanel(mobile);
  check(await mobile.locator('#open-panel').getAttribute('aria-expanded') === 'true', 'phone search launcher opens the panel with an accessible expanded state');
  await mobile.locator('#use-location').click();
  await mobile.waitForFunction(() => document.querySelector('#origin-input').value === 'Your location');
  check(await mobile.locator('.gps-marker').count() === 1, 'consented phone GPS creates an independent user-location marker (controlled test location)');
  await planClaremont(mobile);
  if (await mobile.locator('#journey-panel').getAttribute('aria-hidden') !== 'true') await mobile.locator('#close-panel').click();
  await fitChecks(mobile, true);
  const destination = await mobile.locator('#destination-input').inputValue();
  const journeyId = await mobile.locator('.journey-option[aria-pressed="true"]').getAttribute('data-journey');
  await openPanel(mobile);
  await backgroundClick(mobile);
  check(await mobile.locator('#journey-panel').getAttribute('aria-hidden') === 'true' && await mobile.locator('#destination-input').inputValue() === destination && await mobile.locator('.journey-option[aria-pressed="true"]').getAttribute('data-journey') === journeyId, 'ordinary phone map taps rehide search while preserving the chosen destination and journey');
  await mobileContext.setGeolocation({ latitude: -31.9513, longitude: 115.8600, accuracy: 12 });
  await mobile.waitForFunction(() => {
    let point; window.__nextstopTestMap.eachLayer(layer => { if (layer.options?.title === 'Your phone location') point = layer.getLatLng(); });
    return point && Math.abs(point.lat + 31.9513) < .000001 && Math.abs(point.lng - 115.86) < .000001;
  });
  check(await mobile.locator('.journey-option[aria-pressed="true"]').getAttribute('data-journey') === journeyId, 'GPS watch updates position without clearing the selected journey');
  await timetableChecks(mobile);
  await mobile.locator('#close-panel').click();
  await mobile.screenshot({ path: '/tmp/nextstop-v3-mobile.png', fullPage: true });
  await mobile.setViewportSize({ width: 844, height: 390 });
  await mobile.waitForFunction(() => innerWidth === 844 && document.querySelector('#journey-panel').getAttribute('aria-hidden') === 'false');
  check(await mobile.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'orientation changes preserve a layout without horizontal overflow');
  await mobile.setViewportSize({ width: 390, height: 844 });
  await mobile.waitForFunction(() => document.querySelector('#journey-panel').getAttribute('aria-hidden') === 'true');
  await openPanel(mobile);
  await mobile.locator('#destination-input').focus();
  await mobile.locator('#choose-on-map').click();
  await backgroundClick(mobile, .55, .36);
  check(await mobile.locator('#destination-input').inputValue() === 'Destination on map' && await mobile.locator('#journey-panel').getAttribute('aria-hidden') === 'true', 'deliberate phone map selection changes the destination and rehides search');

  await desktop.reload({ waitUntil: 'domcontentloaded' });
  await desktop.waitForFunction(revision => document.querySelector('#map').dataset.routeRevision === revision && Number(document.querySelector('#map').dataset.networkLines) > 0, cache.revision);
  const storedAgain = await cacheRecord(desktop);
  check(storedAgain.revision === cache.revision && storedAgain.lines === cache.lines, 'the saved public network remains available across a page reload');
  await desktop.waitForFunction(() => document.querySelector('#route-cache-status').textContent.includes('ready'));
  check(desktopTelemetry.routes.some(request => request.headers['if-none-match'] === cache.etag), 'saved routes are revalidated with their ETag instead of unconditionally redownloaded');

  const reducedContext = await browser.newContext({ viewport: { width: 1024, height: 768 }, reducedMotion: 'reduce', locale: 'en-AU', timezoneId: 'Australia/Perth' });
  contexts.push(reducedContext);
  const reduced = await reducedContext.newPage();
  const reducedTelemetry = await instrument(reduced);
  await ready(reduced);
  const reducedState = await reduced.evaluate(() => {
    let animated = 0, vehicles = 0;
    window.__nextstopTestMap.eachLayer(marker => {
      if (marker.getElement?.()?.classList.contains('vehicle-marker')) { vehicles++; if (marker.nextstopAnimation) animated++; }
    });
    return { matches: matchMedia('(prefers-reduced-motion: reduce)').matches, animated, vehicles };
  });
  check(reducedState.matches && reducedState.vehicles > 0 && reducedState.animated === 0, 'reduced-motion preference disables continuous vehicle animation while keeping services visible');

  check(browserErrors.length === 0, 'desktop and phone flows complete without JavaScript exceptions');
  console.log(`\n${checks} real-API browser checks passed.`);
  console.log(`Street-map requests with browser TLS failures: ${desktopTelemetry.tileFailures + mobileTelemetry.tileFailures}. Basemap imagery is not claimed verified; TLS verification remained enabled.`);
  console.log('Synthetic browser geolocation was used only for the phone GPS check; routes, timetables and bus/train data were real.');
} finally {
  for (const context of contexts) await context.close();
  await browser.close();
}
