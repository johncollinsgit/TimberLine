import {expect, test} from '@playwright/test';
import type {Page} from '@playwright/test';

let pageErrors: string[];
test.beforeEach(async ({page}) => {
  pageErrors = [];
  page.on('pageerror', error => pageErrors.push(error.message));
});
test.afterEach(() => expect(pageErrors).toEqual([]));

async function prepareNativeExports(page: Page) {
  await page.goto('/');
  await page.evaluate(() => {
    const config = document.querySelector('#knife everbranch-product-viewer')!.getAttribute('data-config')!;
    const viewer = document.createElement('everbranch-product-viewer');
    viewer.id = 'native-export-preview';
    viewer.setAttribute('native', ''); viewer.setAttribute('data-config', config);
    document.body.append(viewer);
  });
  const viewer = page.locator('#native-export-preview');
  await viewer.locator('.pv-launch').click();
  await expect.poll(() => viewer.evaluate((el: any) => el.modelReady)).toBe(true);
  await viewer.evaluate((el: any) => {
    const state: any = {photos: [], stops: [], limits: [], exports: [], stoppedTracks: 0, delayedShares: []};
    (window as any).nativeExportFixture = state;
    class Recorder {
      static isTypeSupported() { return true; }
      state = 'inactive'; ondataavailable: any; onstop: any; onerror: any;
      start() { this.state = 'recording'; }
      stop() { this.state = 'inactive'; state.stops.push(() => { this.ondataavailable?.({data: new Blob(['synthetic clip'])}); this.onstop?.(); }); }
    }
    Object.defineProperty(window, 'MediaRecorder', {configurable: true, value: Recorder});
    const canvas = document.createElement('canvas');
    Object.defineProperty(canvas, 'captureStream', {value: () => ({getTracks: () => [{stop: () => ++state.stoppedTracks}]})});
    canvas.toBlob = callback => { state.photos.push(callback); };
    el.compose = () => canvas;
    el.savePreview = (blob: Blob, name: string, context?: {isCurrent: () => boolean}) => { state.exports.push({name, bytes: blob.size, current: context?.isCurrent() ?? true}); };
    const timeout = window.setTimeout.bind(window);
    window.setTimeout = ((callback: TimerHandler, delay?: number, ...args: any[]) => {
      if (delay === 20000) {
        state.limits.push(() => (callback as Function)(...args));
        return timeout(() => undefined, 3600000);
      }
      return timeout(callback, delay, ...args);
    }) as typeof window.setTimeout;
  });
  return viewer;
}

test('close/reopen and shared-model options retain loaded readiness and saved scale', async ({page}) => {
  await page.goto('/');
  const viewer = page.locator('#knife everbranch-product-viewer');
  await viewer.locator('.pv-launch').click();
  await expect.poll(() => viewer.evaluate((el: any) => el.modelReady)).toBe(true);
  const scale = await viewer.locator('model-viewer').evaluate((el: any) => el.scale);
  await viewer.evaluate(el => { (el as any).loads = 0; el.querySelector('model-viewer')!.addEventListener('load', () => ++(el as any).loads); });
  await viewer.getByRole('button', {name: 'Close product preview'}).click();
  await viewer.locator('.pv-launch').click();
  await expect(viewer.locator('.pv-status')).toHaveText('Drag to explore your product.');
  await expect(viewer.locator('model-viewer')).toHaveJSProperty('scale', scale);
  await viewer.getByLabel('Product option').selectOption('2');
  await expect(viewer.locator('.pv-status')).toHaveText('Drag to explore your product.');
  expect(await viewer.evaluate((el: any) => ({ready: el.modelReady, loads: el.loads}))).toEqual({ready: true, loads: 0});
  await expect(viewer.locator('model-viewer')).toHaveJSProperty('scale', scale);
});

test('size-corrected room models suppress only the inaccurate Scene Viewer fallback', async ({page}) => {
  await page.goto('/');
  await page.evaluate(() => {
    const original = document.querySelector('#barrel everbranch-product-viewer')!;
    const config = JSON.parse(original.getAttribute('data-config')!);
    config.lengthMm = 450;
    const corrected = document.createElement('everbranch-product-viewer');
    corrected.id = 'corrected';
    corrected.setAttribute('data-config', JSON.stringify(config));
    document.body.append(corrected);
  });
  const viewer = page.locator('#corrected');
  await viewer.locator('.pv-launch').click();
  await expect.poll(() => viewer.evaluate((el: any) => el.modelReady)).toBe(true);
  await expect(viewer.locator('model-viewer')).toHaveAttribute('ar-modes', 'webxr quick-look');
  await expect(viewer.locator('.pv-note')).toContainText('Android Scene Viewer is unavailable for this model');
  const dimensions = await viewer.locator('model-viewer').evaluate((el: any) => el.getDimensions());
  expect(Math.max(dimensions.x, dimensions.y, dimensions.z)).toBeCloseTo(.45, 3);
});

test('camera denial leaves the loaded 3D view available without a live stream', async ({page}) => {
  await page.addInitScript(() => {
    Object.defineProperty(navigator.mediaDevices, 'getUserMedia', {value: async () => { throw new DOMException('Denied for synthetic test', 'NotAllowedError'); }});
  });
  await page.goto('/');
  const viewer = page.locator('#knife everbranch-product-viewer');
  await viewer.locator('.pv-launch').click();
  await expect.poll(() => viewer.evaluate((el: any) => el.modelReady)).toBe(true);
  await viewer.locator('[data-action=camera]').click();
  await expect(viewer.locator('.pv-status')).toContainText('Camera access was declined');
  await expect(viewer.locator('model-viewer')).toBeVisible();
  expect(await viewer.evaluate((el: any) => ({stream: el.stream, ready: el.modelReady}))).toEqual({stream: null, ready: true});
});

test('late source events cannot change the current model and repeated loads do not compound scale', async ({page}) => {
  await page.goto('/');
  const viewer = page.locator('#knife everbranch-product-viewer');
  await viewer.locator('.pv-launch').click();
  await expect.poll(() => viewer.evaluate((el: any) => el.modelReady)).toBe(true);
  const pending = await viewer.evaluate((el: any) => {
    const old = el.mv;
    el.loadModel('/fixtures/barrel.glb');
    const current = el.mv;
    el.loadModel('/fixtures/barrel.glb');
    old.dispatchEvent(new CustomEvent('load', {detail: {url: '/fixtures/knife.glb'}}));
    old.dispatchEvent(new CustomEvent('error', {detail: {type: 'loadfailure'}}));
    current.dispatchEvent(new CustomEvent('load', {detail: {url: '/fixtures/knife.glb'}}));
    return {ready: el.modelReady, loading: el.modelLoading, samePendingElement: current === el.mv, newElement: old !== current};
  });
  expect(pending).toEqual({ready: false, loading: true, samePendingElement: true, newElement: true});
  await expect.poll(() => viewer.evaluate((el: any) => el.modelReady)).toBe(true);
  const scale = await viewer.locator('model-viewer').evaluate((el: any) => el.scale);
  await viewer.evaluate((el: any) => el.mv.dispatchEvent(new CustomEvent('load', {detail: {url: '/fixtures/barrel.glb'}})));
  await expect(viewer.locator('model-viewer')).toHaveJSProperty('scale', scale);
  const dimensions = await viewer.locator('model-viewer').evaluate((el: any) => el.getDimensions());
  expect(Math.max(dimensions.x, dimensions.y, dimensions.z)).toBeCloseTo(.3, 3);
});

test('native-mode mount unmount and remount applies saved dimensions without an AR session', async ({page}) => {
  await page.goto('/');
  const config = await page.locator('#knife everbranch-product-viewer').getAttribute('data-config');
  for (let i = 0; i < 2; i++) {
    await page.evaluate(config => {
      const viewer = document.createElement('everbranch-product-viewer');
      viewer.id = 'native-preview';
      viewer.setAttribute('native', '');
      viewer.setAttribute('data-config', config!);
      document.body.append(viewer);
    }, config);
    const viewer = page.locator('#native-preview');
    await viewer.locator('.pv-launch').click();
    await expect.poll(() => viewer.evaluate((el: any) => el.modelReady)).toBe(true);
    const dimensions = await viewer.locator('model-viewer').evaluate((el: any) => el.getDimensions());
    expect(Math.max(dimensions.x, dimensions.y, dimensions.z)).toBeCloseTo(.3, 3);
    await expect(viewer.locator('model-viewer')).not.toHaveAttribute('ar');
    await viewer.getByRole('button', {name: 'Close product preview'}).click();
    await viewer.evaluate(el => el.remove());
  }
});

for (const interruption of ['background', 'close', 'unmount', 'browser-fallback']) {
  test(`native recording and pending photo exports are cancelled by ${interruption}`, async ({page}) => {
    const viewer = await prepareNativeExports(page);
    const result = await viewer.evaluate((el: any, interruption) => {
      const state = (window as any).nativeExportFixture;
      el.record(); el.photo();
      if (interruption === 'background') {
        Object.defineProperty(document, 'hidden', {configurable: true, value: true});
        document.dispatchEvent(new Event('visibilitychange'));
      } else if (interruption === 'close') {
        el.dialog.close();
        // The native close path invokes stop as the close event is delivered.
        el.dialog.dispatchEvent(new Event('close'));
      } else if (interruption === 'unmount') el.remove();
      else el.stop(); // Native Browser handoff stops the viewer before opening.
      state.photos.forEach((callback: BlobCallback) => callback(new Blob(['synthetic photo'])));
      state.stops.forEach((callback: Function) => callback());
      return {exports: state.exports, stoppedTracks: state.stoppedTracks, recording: el.recording};
    }, interruption);
    expect(result).toEqual({exports: [], stoppedTracks: 1, recording: false});
  });
}

test('foreground photo explicit recording completion and the recording limit still export', async ({page}) => {
  const viewer = await prepareNativeExports(page);
  const result = await viewer.evaluate((el: any) => {
    const state = (window as any).nativeExportFixture;
    el.photo(); state.photos.shift()(new Blob(['synthetic photo']));
    el.record(); el.record(); state.stops.shift()(); // Deliberate Stop recording.
    el.record(); state.limits.at(-1)(); state.stops.shift()(); // Foreground 20s completion.
    return {exports: state.exports, stoppedTracks: state.stoppedTracks};
  });
  expect(result.exports.map((item: any) => [item.name, item.current])).toEqual([
    ['everbranch-product-preview.png', true], ['everbranch-product-preview.mp4', true], ['everbranch-product-preview.mp4', true],
  ]);
  expect(result.stoppedTracks).toBe(2);
});

test('an already dispatched native export context becomes invalid after cancellation', async ({page}) => {
  const viewer = await prepareNativeExports(page);
  await viewer.evaluate((el: any) => {
    const state = (window as any).nativeExportFixture;
    el.savePreview = (_blob: Blob, _name: string, context: {isCurrent: () => boolean}) => new Promise((_resolve, reject) => {
      state.delayedShares.push(() => { if (context.isCurrent()) state.exports.push('late native share'); reject(new Error('Synthetic late native failure')); });
    });
    el.photo(); state.photos.shift()(new Blob(['synthetic photo']));
    el.stop(); state.delayedShares.shift()();
  });
  await expect(viewer.locator('.pv-status')).toHaveText('Camera stopped. You can continue exploring in 3D.');
  expect(await page.evaluate(() => (window as any).nativeExportFixture.exports)).toEqual([]);
});
