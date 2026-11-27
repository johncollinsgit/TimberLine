export async function openReportControls(page){
  const trigger=page.locator('#tr-date-toggle');
  if(await trigger.isVisible()&&await trigger.getAttribute('aria-expanded')!=='true')await trigger.click();
}
export async function closeReportControls(page){
  const trigger=page.locator('#tr-date-toggle');
  if(await trigger.isVisible()&&await trigger.getAttribute('aria-expanded')==='true')await page.locator('#tr-date-close').click();
}
export async function openReportOptions(page){
  await openReportControls(page);
  const trigger=page.getByRole('button',{name:'Report options',exact:true});
  if(await trigger.isVisible()&&await trigger.getAttribute('aria-expanded')!=='true')await trigger.click();
}
export async function selectReportRange(page,key){await openReportControls(page);await page.locator('#tr-range').selectOption(key);}
export async function selectReportAccount(page,value){await openReportControls(page);await page.locator('#tr-account-filter').selectOption(String(value));await closeReportControls(page);}
export async function selectReportScope(page,key){await openReportControls(page);await page.locator(`#tr-space-tabs [data-scope="${key}"]`).click();await closeReportControls(page);}
