// Anonymous GET/HEAD-only lab observations. No physical-device or user-percentile claim.
const { chromium, webkit, devices } = require('../integration/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const expected = process.argv[2];
const output = path.resolve(process.argv[3] || 'bounded-pass/live-timings');
if (!/^[0-9a-f]{40}$/.test(expected || '')) throw new Error('Exact expected release required');
fs.mkdirSync(output, { recursive: true });
const base = 'https://app.theeverbranch.com';
const allowed = new Set(['app.theeverbranch.com', 'fonts.bunny.net', 'fonts.gstatic.com', 'fonts.googleapis.com']);
const safeUrl = value => { const u = new URL(value); return { url: ['http:', 'https:', 'ws:', 'wss:'].includes(u.protocol) ? u.origin + u.pathname : u.protocol + '[inline-resource]', hadQuery: Boolean(u.search) }; };
function ready() {
  const value = JSON.parse(execFileSync('curl', ['--fail', '--silent', '--show-error', base + '/ready'], { encoding: 'utf8' }));
  if (value.status !== 'ok' || value.release !== expected) throw new Error('Readiness release changed');
  return { ...value, observedAt: new Date().toISOString() };
}
const result = { expectedRelease: expected, readyBefore: ready(), startedAt: new Date().toISOString(),
  runner: { collectorSha256: createHash('sha256').update(fs.readFileSync(__filename)).digest('hex'), playwrightVersion: require('../integration/node_modules/playwright/package.json').version, platform: process.platform, architecture: process.arch, headless: true },
  method: { version: '2-local-image-reads', routes: ['/platform/promo', '/platform/plans', '/platform/start', '/explore/modules'], samplesPerRouteProfile: 3,
    sequential: true, physicalDevice: false, phoneNetwork: false, artificialThrottling: false,
    connection: 'Mac host connection; no artificial bandwidth/CPU throttling; not a real-user population',
    cache: 'New browser context for every sample; Playwright request routing disables HTTP cache. OS/DNS/TLS and server/CDN caches are not reset or claimed cold.',
    capture: 'load event plus 3000ms observation window; LCP is last observed candidate within this bounded window',
    methods: ['GET', 'HEAD'], localImages: 'GET-only data images and same-origin document blob images permitted; no outgoing network', serviceWorkers: 'blocked', webSockets: 'intercepted without server connection and closed', allowedAssetHosts: [...allowed],
    limits: ['Emulated mobile viewport/UA/DPR on a Mac; no physical phone or assistive technology acceptance',
      'Unsupported metrics remain null; no inferred zeros, speed score, user percentile or performance-budget acceptance',
      'Blocked and failed resources remain in each sample; filtered resources can affect complete-page measurements'] }, samples: [] };
const profiles = [
  { name: 'chromium-desktop', type: chromium, options: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } }, emulatedMobile: false },
  { name: 'chromium-emulated-mobile', type: chromium, options: { ...devices['Pixel 7'], viewport: { width: 390, height: 844 } }, emulatedMobile: true },
  { name: 'webkit-desktop', type: webkit, options: { ...devices['Desktop Safari'], viewport: { width: 1440, height: 1000 } }, emulatedMobile: false },
  { name: 'webkit-emulated-mobile', type: webkit, options: { ...devices['iPhone 13'], viewport: { width: 390, height: 844 } }, emulatedMobile: true },
];
function save() { fs.writeFileSync(path.join(output, 'samples.json'), JSON.stringify(result, null, 2) + '\n'); }
(async () => {
  for (const profile of profiles) {
    const browser = await profile.type.launch({ headless: true });
    try {
      for (const routePath of result.method.routes) for (let n = 1; n <= 3; n++) {
        const context = await browser.newContext({ ...profile.options, locale: 'en-US', colorScheme: 'light', serviceWorkers: 'block' });
        const page = await context.newPage();
        const sample = { profile: profile.name, engineVersion: browser.version(), emulatedMobile: profile.emulatedMobile,
          route: routePath, sample: n, startedAt: new Date().toISOString(), blocked: [], allowedLocalImages: [], failedResources: [], errorResponses: [], pageErrorTypes: [] };
        await page.route('**/*', async route => {
          const request = route.request(), url = new URL(request.url());
          if (request.method() === 'GET' && request.resourceType() === 'image'
            && (url.protocol === 'data:' || (url.protocol === 'blob:' && url.origin === base))) {
            sample.allowedLocalImages.push({ url: url.protocol + '[document-local-image]', resourceType: request.resourceType() });
            return route.continue();
          }
          if (!['GET', 'HEAD'].includes(request.method()) || !allowed.has(url.hostname)) {
            sample.blocked.push({ ...safeUrl(request.url()), method: request.method(), resourceType: request.resourceType() });
            return route.abort();
          }
          return route.continue();
        });
        await page.routeWebSocket('**/*', async socket => {
          sample.blocked.push({ ...safeUrl(socket.url()), method: 'WEBSOCKET', resourceType: 'websocket' });
          await socket.close({ code: 1000, reason: 'Read-only observation' });
        });
        page.on('requestfailed', request => sample.failedResources.push({ ...safeUrl(request.url()), method: request.method(), error: request.failure()?.errorText || 'unknown' }));
        page.on('response', response => { if (response.status() >= 400) sample.errorResponses.push({ ...safeUrl(response.url()), status: response.status() }); });
        page.on('pageerror', error => sample.pageErrorTypes.push(error.name));
        await page.addInitScript(() => {
          window.__lab = { supported: PerformanceObserver.supportedEntryTypes || [], lcp: null, cls: null, longTasks: null };
          for (const type of ['largest-contentful-paint', 'layout-shift', 'longtask']) {
            if (!window.__lab.supported.includes(type)) continue;
            if (type === 'layout-shift') window.__lab.cls = 0;
            if (type === 'longtask') window.__lab.longTasks = [];
            new PerformanceObserver(list => { for (const entry of list.getEntries()) {
              if (type === 'largest-contentful-paint') window.__lab.lcp = entry.startTime;
              if (type === 'layout-shift' && !entry.hadRecentInput) window.__lab.cls += entry.value;
              if (type === 'longtask') window.__lab.longTasks.push({ start: entry.startTime, duration: entry.duration });
            } }).observe({ type, buffered: true });
          }
        });
        const start = Date.now();
        try {
          const response = await page.goto(base + routePath, { waitUntil: 'load', timeout: 45000 });
          sample.status = response?.status() ?? null;
          sample.finalUrl = safeUrl(page.url());
          const headers = response ? await response.allHeaders() : {};
          sample.mainResponse = Object.fromEntries(['content-encoding', 'cache-control', 'content-type', 'content-length'].map(k => [k, headers[k] ?? null]));
          await page.waitForTimeout(3000);
          sample.metrics = await page.evaluate(() => {
            const nav = performance.getEntriesByType('navigation')[0]?.toJSON() || null;
            if (nav) nav.name = location.origin + location.pathname;
            const paints = performance.getEntriesByType('paint').map(x => ({ name: x.name, startTime: x.startTime }));
            return { navigation: nav, paints: paints.length ? paints : null,
              paintUnavailableReason: paints.length ? null : 'Engine did not expose paint entries within the observation window',
              lcpMs: window.__lab.lcp, lcpUnavailableReason: window.__lab.supported.includes('largest-contentful-paint') ? (window.__lab.lcp === null ? 'No observed candidate within window' : null) : 'Engine does not support largest-contentful-paint entries',
              cls: window.__lab.cls, longTasks: window.__lab.longTasks, supportedEntryTypes: window.__lab.supported,
              resources: performance.getEntriesByType('resource').map(x => { const u = new URL(x.name); return { url: ['http:', 'https:'].includes(u.protocol) ? u.origin + u.pathname : u.protocol + '[inline-resource]',
                hadQuery: Boolean(u.search), initiatorType: x.initiatorType, startTime: x.startTime, duration: x.duration,
                transferSize: x.transferSize, encodedBodySize: x.encodedBodySize, decodedBodySize: x.decodedBodySize,
                note: u.origin === location.origin ? 'Same-origin timing' : 'Cross-origin timing fields may be restricted without Timing-Allow-Origin' }; }),
              environment: { userAgent: navigator.userAgent, viewport: { width: innerWidth, height: innerHeight }, dpr: devicePixelRatio,
                connection: navigator.connection ? { effectiveType: navigator.connection.effectiveType, downlink: navigator.connection.downlink, rtt: navigator.connection.rtt, saveData: navigator.connection.saveData } : null },
              images: [...document.images].map(image => { const bounds = image.getBoundingClientRect(); const u = image.currentSrc ? new URL(image.currentSrc) : null;
                return { source: u ? (['http:', 'https:'].includes(u.protocol) ? u.origin + u.pathname : u.protocol + '[document-local-image]') : null,
                  complete: image.complete, naturalWidth: image.naturalWidth, naturalHeight: image.naturalHeight, loading: image.loading,
                  renderedInViewport: bounds.width > 0 && bounds.height > 0 && bounds.bottom > 0 && bounds.right > 0 && bounds.top < innerHeight && bounds.left < innerWidth }; }) };
          });
          sample.loaded = true;
          if (routePath === '/platform/promo' && n === 1) {
            try { sample.screenshot = profile.name + '-promo.png'; await page.screenshot({ path: path.join(output, sample.screenshot) }); }
            catch (error) { sample.screenshotErrorType = error.name; }
          }
        } catch (error) {
          sample.loaded = false;
          sample.errorType = error.name;
          sample.errorSummary = String(error.message).split('\n')[0].slice(0, 220);
        } finally {
          sample.wallMs = Date.now() - start;
          result.samples.push(sample); save();
          await context.close();
          process.stdout.write(profile.name + ' ' + routePath + ' #' + n + ' ' + (sample.status || sample.errorType) + '\n');
        }
      }
    } finally { await browser.close(); }
  }
  result.readyAfter = ready(); result.completedAt = new Date().toISOString(); save();
  process.stdout.write(JSON.stringify({ complete: true, samples: result.samples.length, release: expected }) + '\n');
})().catch(error => { result.failure = { type: error.name, message: String(error.message).split('\n')[0] }; save(); process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
