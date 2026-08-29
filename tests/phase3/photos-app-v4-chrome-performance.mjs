import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { performance } from 'node:perf_hooks';
import { CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS } from './photos-app-v4-chrome-localhost-guard.mjs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

class GateError extends Error {
  constructor(code) {
    super(code);
    this.code = code;
  }
}

const MEASURED_SAMPLES = 7;
const WARMUP_SAMPLES = 2;
const LIMITS = Object.freeze({
  SEARCH_OVERLAY_OPEN_P50_MS: 100,
  SEARCH_SUGGESTIONS_VISIBLE_P50_MS: 150,
  STRUCTURED_SEARCH_P50_MS: 300,
  COLLECTIONS_HOME_WARM_P50_MS: 400,
});
const settings = Object.freeze({
  piwigo: process.env.CLASS_ARCHIVE_V4_PERF_PIWIGO_ORIGIN,
  photos: process.env.CLASS_ARCHIVE_V4_PERF_PHOTO_ORIGIN,
  credentials: process.env.CLASS_ARCHIVE_V4_PERF_CREDENTIAL_FILE,
  profile: process.env.CLASS_ARCHIVE_V4_PERF_USER_DATA_ROOT,
  evidence: process.env.CLASS_ARCHIVE_V4_PERF_EVIDENCE_FILE,
});

let stage = 'initialization';
let chromeVersion = 'unknown';

function fail(code) {
  throw new GateError(code);
}

function check(value, code) {
  if (!value) fail(code);
}

function stageAt(value) {
  stage = value;
  process.stdout.write(`V4_CHROME_PERFORMANCE_STAGE=${value}\n`);
}

function localOrigin(value, port, code) {
  let target;
  try {
    target = new URL(value);
  } catch {
    fail(code);
  }
  check(target.protocol === 'http:' && target.hostname === '127.0.0.1'
    && target.port === String(port) && target.pathname === '/'
    && !target.username && !target.password && !target.search && !target.hash, code);
  return target;
}

function privatePath(value, code) {
  check(typeof value === 'string' && path.isAbsolute(value) && !value.includes('\0'), code);
  return path.resolve(value);
}

function allowed(target) {
  return ['data:', 'blob:', 'about:'].includes(target.protocol)
    || (target.protocol === 'http:' && target.hostname === '127.0.0.1'
      && ['8090', '8091'].includes(target.port));
}

function credentials() {
  let document;
  try {
    document = JSON.parse(fs.readFileSync(privatePath(settings.credentials, 'credential_path'), 'utf8'));
  } catch {
    fail('credential_document');
  }
  check(document?.version === 1 && document.environment === 'synthetic', 'credential_shape');
  check(Object.keys(document.roles ?? {}).sort().join(',') === 'anonymous,classmate,family,teacher', 'credential_roles');
  const role = document.roles.classmate;
  check(typeof role?.username === 'string' && role.username.length > 0 && role.username.length <= 190, 'credential_username');
  check(typeof role?.password === 'string' && role.password.length >= 24 && role.password.length <= 190, 'credential_password');
  return role;
}

function percentile50(values, code) {
  check(Array.isArray(values) && values.length === MEASURED_SAMPLES
    && values.every((value) => Number.isFinite(value) && value >= 0 && value < 120_000), code);
  const sorted = [...values].sort((a, b) => a - b);
  return Math.round(sorted[Math.floor(sorted.length / 2)]);
}

function groupedSearchRequest(value, query) {
  try {
    const target = new URL(value);
    return target.origin === new URL(settings.photos).origin
      && target.pathname === '/api/class-archive/search/grouped'
      && target.searchParams.get('q') === query
      && target.searchParams.get('contextType') === 'ALL'
      && !target.searchParams.has('contextId')
      && !target.searchParams.has('albumId');
  } catch {
    return false;
  }
}

async function recordChromeVersion(context, page) {
  let session = null;
  try {
    session = await context.newCDPSession(page);
    const info = await session.send('Browser.getVersion');
    const match = /^Chrome\/(\d+(?:\.\d+){1,4})$/.exec(typeof info?.product === 'string' ? info.product : '');
    check(match !== null, 'chrome_stable_product');
    chromeVersion = match[1];
  } finally {
    await session?.detach().catch(() => null);
  }
}

async function gotoApp(page, pathname, code) {
  const target = new URL(pathname, settings.photos);
  try {
    await page.goto(target.toString(), { waitUntil: 'domcontentloaded', timeout: 30_000 });
  } catch (error) {
    let current = null;
    try { current = new URL(page.url()); } catch { }
    const aborted = error instanceof Error && error.message.includes('ERR_ABORTED');
    if (!aborted || current === null || current.origin !== target.origin || current.pathname !== target.pathname) throw error;
  }
  await page.locator('[data-photo-app="true"]').waitFor({ state: 'attached', timeout: 15_000 });
  const current = new URL(page.url());
  check(current.origin === target.origin && current.pathname === target.pathname, code);
}

async function login(page, role) {
  await page.goto(new URL('identification.php', settings.piwigo).toString(), {
    waitUntil: 'domcontentloaded',
    timeout: 30_000,
  });
  const form = page.locator('form[name="login_form"]');
  check(await form.count() === 1, 'login_form');
  await form.locator('input[name="username"]').fill(role.username);
  await form.locator('input[name="password"]').fill(role.password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20_000 }).catch(() => null),
    form.locator('button[type="submit"], button:not([type]), input[type="submit"]').last().click(),
  ]);
  await gotoApp(page, '/home', 'login_home_route');
}

async function closeSearch(page) {
  const dialog = page.locator('dialog[data-search-overlay="true"][open]');
  if (await dialog.count() === 0) return;
  await page.keyboard.press('Escape');
  await dialog.waitFor({ state: 'detached', timeout: 10_000 }).catch(() => null);
  check(await dialog.count() === 0, 'search_close');
  const settled = await page.waitForFunction(() => !document.querySelector('dialog[data-search-overlay="true"][open]')
    && new URL(location.href).searchParams.get('search') !== '1', { timeout: 10_000 })
    .then(() => true).catch(() => false);
  check(settled, 'search_history_settle');
}

async function findStructuredQuery(page) {
  const query = await page.evaluate(async (candidates) => {
    for (const candidate of candidates) {
      try {
        const response = await fetch(`/api/class-archive/search/grouped?q=${encodeURIComponent(candidate)}&contextType=ALL`, {
          credentials: 'same-origin',
          cache: 'no-store',
        });
        const payload = await response.json();
        const structuredCount = Array.isArray(payload?.structured)
          ? payload.structured.reduce((count, group) => count + (Array.isArray(group?.items) ? group.items.length : 0), 0)
          : 0;
        const photoCount = Array.isArray(payload?.photos?.items) ? payload.photos.items.length : 0;
        if (response.status === 200 && structuredCount + photoCount > 0) return candidate;
      } catch { }
    }
    return null;
  }, ['班级', '相册', '毕业', '测试']);
  check(typeof query === 'string' && query.length > 0, 'structured_search_fixture');
  return query;
}

async function measureHome(page) {
  const values = [];
  const diagnostics = [];
  let fullTimelineRequests = 0;
  for (let index = 0; index < WARMUP_SAMPLES + MEASURED_SAMPLES; index += 1) {
    await gotoApp(page, '/photos', 'home_sample_library_route');
    // The library route legitimately requests its timeline. Let that request
    // settle before installing the Home-only observer so a late library
    // response cannot be misattributed to the Collections navigation.
    await page.waitForLoadState('networkidle', { timeout: 15_000 });
    const listener = (request) => {
      try {
        if (new URL(request.url()).pathname === '/api/class-archive/timeline') fullTimelineRequests += 1;
      } catch { }
    };
    page.on('request', listener);
    const started = performance.now();
    try {
      await gotoApp(page, '/home', 'home_sample_route');
      await page.locator('.home-featured, .home-section').first().waitFor({ state: 'visible', timeout: 15_000 });
    } finally {
      page.off('request', listener);
    }
    const elapsed = performance.now() - started;
    if (index >= WARMUP_SAMPLES) {
      values.push(elapsed);
      diagnostics.push(await page.evaluate(() => {
        const rounded = (value) => Math.round(Number.isFinite(value) && value >= 0 ? value : 0);
        const navigation = performance.getEntriesByType('navigation')[0] ?? {};
        const allowedResources = new Map([
          ['/api/class-archive/product-state', 'productState'],
          ['/api/class-archive/collections/home', 'collectionsHome'],
          ['/api/class-archive/collections/pins', 'collectionPins'],
          ['/photo-ui/app.js', 'applicationScript'],
        ]);
        const resource = {};
        for (const entry of performance.getEntriesByType('resource')) {
          let key = null;
          try { key = allowedResources.get(new URL(entry.name).pathname) ?? null; } catch { }
          if (!key) continue;
          resource[key] = {
            start: rounded(entry.startTime),
            responseEnd: rounded(entry.responseEnd),
            duration: rounded(entry.duration),
          };
        }
        return {
          firstCollectionVisible: rounded(performance.now()),
          navigation: {
            responseStart: rounded(navigation.responseStart),
            responseEnd: rounded(navigation.responseEnd),
            domContentLoaded: rounded(navigation.domContentLoadedEventEnd),
            load: rounded(navigation.loadEventEnd),
          },
          resource,
        };
      }));
    }
  }
  check(fullTimelineRequests === 0, 'home_full_timeline_preload');
  const diagnosticMedian = (reader) => percentile50(diagnostics.map(reader), 'home_diagnostic_samples');
  const resourceMedian = (key, field) => diagnosticMedian((sample) => sample.resource[key]?.[field] ?? 0);
  return {
    p50: percentile50(values, 'home_samples'),
    diagnostics: {
      firstCollectionVisibleP50Ms: diagnosticMedian((sample) => sample.firstCollectionVisible),
      navigationResponseStartP50Ms: diagnosticMedian((sample) => sample.navigation.responseStart),
      navigationResponseEndP50Ms: diagnosticMedian((sample) => sample.navigation.responseEnd),
      domContentLoadedP50Ms: diagnosticMedian((sample) => sample.navigation.domContentLoaded),
      loadP50Ms: diagnosticMedian((sample) => sample.navigation.load),
      productStateResponseEndP50Ms: resourceMedian('productState', 'responseEnd'),
      collectionsHomeResponseEndP50Ms: resourceMedian('collectionsHome', 'responseEnd'),
      collectionPinsResponseEndP50Ms: resourceMedian('collectionPins', 'responseEnd'),
      applicationScriptResponseEndP50Ms: resourceMedian('applicationScript', 'responseEnd'),
    },
  };
}

async function measureOverlayAndSuggestions(page) {
  const overlay = [];
  const suggestions = [];
  await gotoApp(page, '/home', 'search_measure_home_route');
  for (let index = 0; index < WARMUP_SAMPLES + MEASURED_SAMPLES; index += 1) {
    const trigger = page.locator('[data-global-search-trigger="true"]').first();
    await trigger.waitFor({ state: 'visible', timeout: 15_000 });
    const started = performance.now();
    await trigger.click();
    const dialog = page.locator('dialog[data-search-overlay="true"][open]');
    await dialog.waitFor({ state: 'visible', timeout: 15_000 });
    const overlayElapsed = performance.now() - started;
    await dialog.locator('[role="listbox"]').waitFor({ state: 'visible', timeout: 15_000 });
    const suggestionElapsed = performance.now() - started;
    check(await dialog.locator('.global-search-input').evaluate((node) => document.activeElement === node), 'search_input_focus');
    if (index >= WARMUP_SAMPLES) {
      overlay.push(overlayElapsed);
      suggestions.push(suggestionElapsed);
    }
    await closeSearch(page);
  }
  return {
    overlay: percentile50(overlay, 'overlay_samples'),
    suggestions: percentile50(suggestions, 'suggestion_samples'),
  };
}

async function measureStructuredSearch(page, query) {
  const values = [];
  for (let index = 0; index < WARMUP_SAMPLES + MEASURED_SAMPLES; index += 1) {
    const trigger = page.locator('[data-global-search-trigger="true"]').first();
    await trigger.click();
    const dialog = page.locator('dialog[data-search-overlay="true"][open]');
    await dialog.waitFor({ state: 'visible', timeout: 15_000 });
    const input = dialog.locator('.global-search-input');
    const response = page.waitForResponse((candidate) => candidate.request().method() === 'GET'
      && groupedSearchRequest(candidate.url(), query), { timeout: 15_000 }).catch(() => null);
    const started = performance.now();
    await input.fill(query);
    const resolved = await response;
    check(resolved !== null && resolved.status() === 200, 'structured_search_response');
    await dialog.locator('.search-structured-group, .search-photo-grid').first().waitFor({ state: 'visible', timeout: 15_000 });
    const elapsed = performance.now() - started;
    if (index >= WARMUP_SAMPLES) values.push(elapsed);
    await closeSearch(page);
  }
  return percentile50(values, 'structured_search_samples');
}

function writeEvidence(metrics, diagnostics, result) {
  const target = privatePath(settings.evidence, 'evidence_path');
  check(!fs.existsSync(target), 'evidence_exists');
  const record = {
    version: 1,
    environment: 'synthetic',
    channel: 'chrome',
    chromeVersion,
    measuredSamples: MEASURED_SAMPLES,
    warmupSamples: WARMUP_SAMPLES,
    homeFullTimelineRequests: 0,
    metrics,
    diagnostics,
    result,
    createdAt: new Date().toISOString(),
  };
  fs.writeFileSync(target, `${JSON.stringify(record)}\n`, { encoding: 'utf8', flag: 'wx' });
}

async function main() {
  localOrigin(settings.piwigo, 8090, 'piwigo_origin');
  localOrigin(settings.photos, 8091, 'photo_origin');
  const profile = privatePath(settings.profile, 'profile_path');
  check(!fs.existsSync(profile), 'profile_not_fresh');
  privatePath(settings.evidence, 'evidence_path');
  const role = credentials();

  stageAt('chrome_launch');
  let context = null;
  try {
    context = await chromium.launchPersistentContext(profile, {
      channel: 'chrome',
      headless: false,
      viewport: { width: 1440, height: 900 },
      screen: { width: 1440, height: 900 },
      locale: 'zh-CN',
      serviceWorkers: 'block',
      acceptDownloads: false,
      args: [
        '--no-first-run', '--no-default-browser-check', '--disable-background-networking',
        '--disable-component-update', '--disable-sync', '--no-pings',
        ...CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS,
      ],
    });
    await context.route('**/*', (route) => {
      try { return allowed(new URL(route.request().url())) ? route.continue() : route.abort(); } catch { return route.abort(); }
    });
    const page = context.pages()[0] ?? await context.newPage();
    await recordChromeVersion(context, page);

    stageAt('login');
    await login(page, role);
    const query = await findStructuredQuery(page);

    stageAt('collections_home');
    const home = await measureHome(page);

    stageAt('search_overlay');
    const searchChrome = await measureOverlayAndSuggestions(page);

    stageAt('structured_search');
    const structured = await measureStructuredSearch(page, query);

    const metrics = {
      SEARCH_OVERLAY_OPEN_P50_MS: searchChrome.overlay,
      SEARCH_SUGGESTIONS_VISIBLE_P50_MS: searchChrome.suggestions,
      STRUCTURED_SEARCH_P50_MS: structured,
      COLLECTIONS_HOME_WARM_P50_MS: home.p50,
    };
    const violations = Object.entries(LIMITS).filter(([name, limit]) => !Number.isInteger(metrics[name]) || metrics[name] >= limit);
    writeEvidence(metrics, { collectionsHome: home.diagnostics }, violations.length === 0 ? 'PASS' : 'FAIL');
    for (const name of Object.keys(LIMITS)) process.stdout.write(`${name}=${metrics[name]}\n`);
    if (violations.length !== 0) fail(`${violations[0][0].toLowerCase()}_limit`);
    process.stdout.write(`V4_CHROME_PERFORMANCE=PASS samples=${MEASURED_SAMPLES} warmups=${WARMUP_SAMPLES} channel=chrome chrome_version=${chromeVersion}\n`);
  } finally {
    await context?.close().catch(() => null);
  }
}

main().catch((error) => {
  const code = error instanceof GateError && /^[a-z0-9_]{1,100}$/.test(error.code)
    ? error.code
    : 'unexpected_error';
  process.stdout.write(`V4_CHROME_PERFORMANCE=FAIL stage=${stage} code=${code}\n`);
  process.exitCode = 1;
});
