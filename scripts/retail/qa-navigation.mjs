// Open the containing disclosure through its visible summary before clicking a menu item.
export async function revealAction(page,selector){
 const target=page.locator(selector).first();
 if(!await target.isVisible()){
  const summary=target.locator('xpath=ancestor::details[1]/summary');
  if(await summary.count())await summary.click();
 }
 return target;
}
