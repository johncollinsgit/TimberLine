import {expect, test} from '@playwright/test';

let pageErrors: string[];
test.beforeEach(async ({page}) => {
  pageErrors = [];
  page.on('pageerror', error => pageErrors.push(error.message));
});
test.afterEach(() => expect(pageErrors).toEqual([]));

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
