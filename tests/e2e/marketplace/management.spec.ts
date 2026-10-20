import { test, expect, Page } from '@playwright/test';
import fs from 'node:fs';
const tabs = ['overview','makers','listings','orders','customers','discounts','markets','pickups','rates','statements','payments','connections','activity'];
async function fixture(page: Page, role = 'preview', tab = 'overview', simulatedSave = false) {
    const errors: string[] = [], writes: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.url().startsWith('http://127.0.0.1') && response.status() >= 400) errors.push('HTTP '+response.status()+': '+response.url()); });
    await page.route('**/*', async route => {
        const request=route.request(), url=new URL(request.url());
        if (request.method() !== 'GET') { writes.push(url.pathname); if(simulatedSave && request.method()==='POST' && /^\/maker\/\d+\/details$/.test(url.pathname)) return route.continue({headers:{...request.headers(),'x-marketplace-fixture-redirect':'1'}}); return route.abort(); }
        if (url.hostname !== '127.0.0.1') return route.abort();
        let name: string|null = null;
        if (url.pathname === '/marketplace/preview') name='preview-'+(url.searchParams.get('tab') || 'overview');
        if (url.pathname === '/marketplace/operations') name='operator-'+(url.searchParams.get('tab') || 'overview');
        if (url.pathname.startsWith('/marketplace/operations/makers/')) name='operator-maker';
        if (/^\/maker\/\d+$/.test(url.pathname)) name='maker';
        if (url.pathname === '/qa/shopper') name='shopper';
        if (!name) return route.continue();
        const html=fs.readFileSync('storage/framework/testing/marketplace-console/'+name+'.html','utf8')
            .replaceAll('https://app.theeverbranch.test','http://127.0.0.1:4178')
            .replaceAll('https://marketsandmakers.test','http://127.0.0.1:4178')
            .replaceAll('http://localhost','http://127.0.0.1:4178');
        return route.fulfill({contentType:'text/html',body:html});
    });
    const path=role==='maker' ? '/maker/1' : role==='shopper' ? '/qa/shopper' : '/marketplace/'+(role==='preview'?'preview':'operations')+'?tab='+tab;
    await page.goto(path);
    if (role !== 'shopper') await expect(page.locator('body')).toHaveAttribute('data-mm-ready','true');
    return {errors,writes};
}
async function openNav(page: Page) { if (await page.getByRole('button',{name:'Navigation',exact:true}).isVisible()) await page.getByRole('button',{name:'Navigation',exact:true}).click(); }
async function noOverflow(page: Page) { expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true); }

for (const width of [390,1024,1440]) {
    test('preview navigation and useful rows at '+width+'px',async({page},info)=>{
        await page.setViewportSize({width,height:1000});
        const {errors,writes}=await fixture(page);
        await expect(page.getByText('Private preview · Example data',{exact:true})).toBeVisible();
        await page.screenshot({path:'storage/framework/testing/marketplace-console/preview-'+width+'-'+info.project.name+'.png',fullPage:true});
        await page.getByRole('link',{name:'Review example product'}).click();
        await expect(page.locator('[data-mm-detail="product-mug"]')).toHaveAttribute('open','');
        await expect(page.getByText('Example data · Read only',{exact:true}).last()).toBeVisible();
        await noOverflow(page);
        for(const tab of tabs){
            await openNav(page);
            await page.locator('.mm-nav-group a[href$="tab='+tab+'"]').click();
            await expect(page.locator('body')).toHaveAttribute('data-mm-ready','true');
            await expect(page.locator('.mm-nav-group a[aria-current=page]')).toHaveAttribute('href',new RegExp('tab='+tab+'$'));
            await expect(page.getByText('Private preview · Example data',{exact:true})).toBeVisible();
            await expect(page.locator('form')).toHaveCount(0); await noOverflow(page);
        }
        expect(errors).toEqual([]);expect(writes).toEqual([]);
    });
}

test('search filters details and Back/Forward preserve coherent preview state',async({page})=>{
    const {errors,writes}=await fixture(page,'preview','listings');
    const search=page.getByRole('searchbox');
    await search.fill('Willow');await expect(page.locator('[data-mm-row]:visible')).toHaveCount(2);
    await page.getByLabel('Status',{exact:true}).selectOption('Published');
    await page.locator('[data-mm-detail="product-board"] summary').click();
    await expect(page).toHaveURL(/detail=product-board/);
    await page.locator('[data-mm-detail="product-board"]').getByRole('link',{name:'View order',exact:false}).click();
    await expect(page.locator('[data-mm-detail="order-102"]')).toHaveAttribute('open','');
    await page.goBack();
    await expect(search).toHaveValue('Willow');await expect(page.getByLabel('Status',{exact:true})).toHaveValue('Published');
    await expect(page.locator('[data-mm-detail="product-board"]')).toHaveAttribute('open','');
    await page.goForward();await expect(page.locator('[data-mm-detail="order-102"]')).toHaveAttribute('open','');
    await page.getByRole('searchbox').fill('no such example');await expect(page.getByText('No matching items.',{exact:false})).toBeVisible();
    await page.getByRole('button',{name:'Clear',exact:true}).click();await expect(page.locator('[data-mm-row]:visible')).toHaveCount(3);
    expect(errors).toEqual([]);expect(writes).toEqual([]);
});

test('phone drawer traps keyboard focus closes with Escape and restores its opener',async({page})=>{
    await page.setViewportSize({width:390,height:850});await fixture(page);
    const menu=page.getByRole('button',{name:'Navigation',exact:true});await menu.focus();await menu.press('Enter');
    await expect(page.getByRole('dialog',{name:'Markets & Makers navigation'})).toBeVisible();
    const close=page.getByRole('button',{name:'Close navigation ×'});await expect(close).toBeFocused();
    await page.locator('.mm-sidebar-bottom a').last().focus();await page.keyboard.press('Tab');await expect(page.locator('.mm-brand')).toBeFocused();
    await page.keyboard.press('Escape');await expect(menu).toBeFocused();await expect(menu).toHaveAttribute('aria-expanded','false');
    await menu.click();await close.click();await expect(menu).toBeFocused();await noOverflow(page);
});

for (const width of [390,1024,1440]) {
    test('real operator and maker presentation keeps actual commands in focused details at '+width+'px',async({page},info)=>{
        await page.setViewportSize({width,height:1000});
        const {errors,writes}=await fixture(page,'operator','listings');
        await page.getByRole('searchbox').fill('Test market mug');await expect(page.locator('[data-mm-row]:visible')).toHaveCount(1);
        await page.locator('.mm-record>summary').click();
        await expect(page.getByRole('button',{name:'Approve listing',exact:true})).toBeVisible();
        await expect(page.locator('input[name=uuid]').first()).toHaveAttribute('value',/[a-f0-9-]{36}/);
        await noOverflow(page);await page.screenshot({path:'storage/framework/testing/marketplace-console/operator-'+width+'-'+info.project.name+'.png',fullPage:true});
        await page.goto('/maker/1');await expect(page.locator('body')).toHaveAttribute('data-mm-ready','true');
        if(width>800) await expect(page.getByText('No Everbranch subscription required.',{exact:false})).toBeVisible();
        await page.locator('#products .mm-record>summary').click();await expect(page.getByText('Manage allocated stock',{exact:true})).toBeVisible();
        await page.goto('/maker/1#orders');await expect(page.locator('#orders')).toBeVisible();await expect(page.locator('#products')).toBeHidden();
        await page.locator('#orders .mm-record>summary').click();await expect(page.getByText('Please wait before fulfillment.',{exact:false})).toBeVisible();
        await expect(page.getByRole('button',{name:'Confirm shipped'})).toHaveCount(0);
        await page.goto('/maker/1#business-details');await expect(page.getByLabel('Legal business name')).toHaveValue('Private Supplier LLC');
        await noOverflow(page);await page.screenshot({path:'storage/framework/testing/marketplace-console/maker-'+width+'-'+info.project.name+'.png',fullPage:true});
        expect(errors).toEqual([]);expect(writes).toEqual([]);
    });
}

test('shopper retains the public shell and never receives management CSS or navigation',async({page})=>{
    const {errors,writes}=await fixture(page,'shopper');
    await expect(page.locator('.site-header')).toBeVisible();await expect(page.locator('.mm-sidebar')).toHaveCount(0);
    expect(await page.locator('script[src*="marketplace-management"]').count()).toBe(0);
    expect(errors).toEqual([]);expect(writes).toEqual([]);
});

test('all real operator sections and maker hash destinations remain usable',async({page})=>{
    await page.setViewportSize({width:1440,height:1000});const {errors,writes}=await fixture(page,'operator');
    for(const tab of tabs){
        await page.locator('.mm-nav-group a[href$="tab='+tab+'"]').click();
        await expect(page.locator('body')).toHaveAttribute('data-mm-ready','true');
        await expect(page.locator('.mm-topbar')).toContainText('Operator');await noOverflow(page);
    }
    await page.goto('/marketplace/operations?tab=payments');
    await page.getByText('Store shipping, promotions, policies & launch controls',{exact:true}).click();
    await expect(page.getByRole('button',{name:'Save store settings',exact:true})).toBeVisible();
    await expect(page.locator('input[name=checkout_enabled]')).toBeVisible();
    await page.goto('/maker/1');await expect(page.locator('body')).toHaveAttribute('data-mm-ready','true');
    for(const id of ['products','orders','earnings','markets','agreements','business-details','storefronts','connection']){
        await page.locator('[data-mm-pane-link="'+id+'"]').click();await expect(page.locator('#'+id)).toBeVisible();
        await expect(page.locator('[data-mm-pane-link="'+id+'"]').first()).toHaveAttribute('aria-current','page');
    }
    await page.goBack();await expect(page.locator('#storefronts')).toBeVisible();
    await page.goForward();await expect(page.locator('#connection')).toBeVisible();
    await page.locator('[data-mm-pane-link=products]').click();await page.getByRole('link',{name:'Add a product +',exact:true}).click();
    await expect(page.locator('#new-product')).toHaveAttribute('open','');
    await expect(page.locator('#new-product input[name=title]')).toBeVisible();
    expect(errors).toEqual([]);expect(writes).toEqual([]);
});

// This intercept verifies browser return behavior only, never a real save.
test('maker form redirects retain the selected section without changing the command path',async({page})=>{
    const {errors,writes}=await fixture(page,'maker','overview',true);
    await page.goto('/maker/1#business-details');
    await page.getByRole('button',{name:'Save business details',exact:true}).click();
    await expect(page).toHaveURL(/\/maker\/1#business-details$/);
    await expect(page.locator('#business-details')).toBeVisible();
    expect(writes).toEqual(['/maker/1/details']);expect(errors).toEqual([]);
});

for(const width of [390,1024,1440]){
    test('focused maker profile keeps useful section links in one row at '+width+'px',async({page},info)=>{
        await page.setViewportSize({width,height:1000});const {errors,writes}=await fixture(page,'operator');
        await page.goto('/marketplace/operations/makers/1');await expect(page.locator('body')).toHaveAttribute('data-mm-ready','true');
        const nav=page.getByRole('navigation',{name:'Maker profile sections'});
        await expect(nav.getByRole('link')).toHaveCount(6);
        const tops=await nav.getByRole('link').evaluateAll(links=>links.map(el=>Math.round(el.getBoundingClientRect().top)));
        expect(new Set(tops).size).toBe(1);
        await nav.getByRole('link',{name:'Connections',exact:true}).click();await expect(page).toHaveURL(/#connections$/);
        await expect(page.getByRole('heading',{name:'Shared storefronts and sources.'})).toBeVisible();
        await page.goto('/marketplace/operations/makers/1');await noOverflow(page);
        await page.screenshot({path:'storage/framework/testing/marketplace-console/maker-profile-'+width+'-'+info.project.name+'.png',fullPage:true});
        expect(errors).toEqual([]);expect(writes).toEqual([]);
    });
}
