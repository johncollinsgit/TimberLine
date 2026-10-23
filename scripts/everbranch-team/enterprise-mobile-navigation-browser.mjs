import assert from 'node:assert/strict';

// Follow the visible desktop/mobile controls; never force-click hidden legacy chrome.
async function showMenu(page) {
  const toggle=page.locator('#enterprise-navigation-toggle'),menu=page.locator('#enterprise-mobile-navigation');
  assert.equal(await toggle.isVisible(),true,'A hidden navigation target requires the visible mobile menu');
  if(await toggle.getAttribute('aria-expanded')!=='true')await toggle.click();
  await menu.waitFor({state:'visible'});
  await page.waitForFunction(()=>document.getElementById('enterprise-navigation-toggle')?.getAttribute('aria-expanded')==='true');
  assert.equal(await toggle.getAttribute('aria-expanded'),'true');
  return toggle;
}

export async function clickEnterpriseNavigation(page,target) {
  if(!await target.isVisible())await showMenu(page);
  await target.click();
}

export async function openEnterpriseAction(page,name,desktop) {
  if(await page.locator('#enterprise-navigation-toggle').isVisible()) {
    const toggle=await showMenu(page);
    await page.locator('#enterprise-mobile-navigation').getByRole('button',{name,exact:true}).click();
    return toggle;
  }
  await desktop.click();
  return desktop;
}
