import {test, expect, type Page} from '@playwright/test';
import {readFileSync, mkdirSync, writeFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {join} from 'node:path';

async function loadVideoFixture(page: Page, name: 'editor' | 'player') {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    const base = 'http://127.0.0.1:4178';
    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.hostname !== '127.0.0.1') return route.abort();
        if (url.pathname === '/qa/video') {
            const html = readFileSync('storage/framework/testing/marketplace-console/video-' + name + '.html', 'utf8')
                .replaceAll('https://marketsandmakers.test', base).replaceAll('http://localhost', base);
            return route.fulfill({contentType: 'text/html', body: html});
        }
        if (url.pathname.endsWith('/film')) return route.fulfill({path:'public/images/public-site/everbranch-studio-hero-loop.mp4', contentType:'video/mp4'});
        if (url.pathname.endsWith('/poster.jpg')) return route.fulfill({path:'public/images/marketplace/studio.jpg', contentType:'image/jpeg'});
        if (url.pathname.endsWith('/play')) return route.fulfill({status:204, body:''});
        return route.continue();
    });
    await page.goto('/qa/video');
    return errors;
}

test('phone and desktop maker editor supports adding and removing variant tags', async ({page}) => {
    const errors = await loadVideoFixture(page, 'editor');
    await expect(page.getByRole('heading', {name:'Create a video'})).toBeVisible();
    await page.getByLabel('Product or variant').selectOption({label:'Studio test cup 8 oz · Marketplace'});
    await page.getByRole('button', {name:'Add another product'}).click();
    await expect(page.getByLabel('Product or variant')).toHaveCount(2);
    await page.getByLabel('Product or variant').first().selectOption('');
    await expect(page.getByLabel('Product or variant').first()).toHaveValue('');
    await page.getByText('Import a Windows Studio export', {exact:true}).click();
    await expect(page.getByLabel('Approved public export folder')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    expect(errors).toEqual([]);
});

test('imports a verified PC clip and presents its own product times and captions for maker review', async ({page}, info) => {
    const errors = await loadVideoFixture(page, 'editor');
    const directory = info.outputPath('episode-revision');
    mkdirSync(directory, {recursive:true});
    const bytes = readFileSync('public/images/public-site/everbranch-studio-hero-loop.mp4');
    const text = Buffer.from('WEBVTT\n\n00:00.000 --> 00:01.000\nA hand-thrown cup.\n');
    const label = 'Studio test cup 8 oz · Marketplace';
    const key = await page.getByLabel('Product or variant').locator('option').filter({hasText:label}).getAttribute('value');
    const media = {kind:'short', path:'short-01.mp4', mime_type:'video/mp4', bytes:bytes.length, sha256:createHash('sha256').update(bytes).digest('hex')};
    const captions = {kind:'captions', path:'short-01.vtt', mime_type:'text/vtt', bytes:text.length, sha256:createHash('sha256').update(text).digest('hex'), video:media.path};
    writeFileSync(join(directory, media.path), bytes);
    writeFileSync(join(directory, captions.path), text);
    writeFileSync(join(directory, 'studio-package.json'), JSON.stringify({schema_version:1, title:'Studio clip', approval:'approved', visibility:'public', assets:[media,captions], clips:[{kind:'short', path:media.path, product_tags:[{variant_id:key, label:'Cup', start:2, end:5}]}]}));
    await page.getByText('Import a Windows Studio export', {exact:true}).click();
    await page.getByLabel('Approved public export folder').setInputFiles(directory);
    await page.getByRole('button', {name:'Verify and use this video'}).click();
    await expect(page.getByText(/verified export imported/i)).toBeVisible();
    await expect(page.getByLabel('Title', {exact:true})).toHaveValue('Studio clip');
    await expect(page.getByLabel('English captions (WebVTT)')).toHaveValue(text.toString());
    await expect(page.getByLabel('Product or variant').first()).toHaveValue(key!);
    await expect(page.getByLabel('Until (sec)').first()).toHaveValue('5');
    await expect(page.getByRole('button', {name:'Submit for review'})).toBeEnabled();
    expect(errors).toEqual([]);
});

test('shopper can find a timed product and see when its variant is unavailable', async ({page}) => {
    const errors = await loadVideoFixture(page, 'player');
    await expect(page.getByRole('heading', {name:'Throwing a cup'})).toBeVisible();
    await expect(page.getByRole('heading', {name:'Shop this video'})).toBeVisible();
    await expect(page.getByText('Currently unavailable')).toBeVisible();
    await expect(page.getByRole('button', {name:'Shown at 00:02'})).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    expect(errors).toEqual([]);
});
