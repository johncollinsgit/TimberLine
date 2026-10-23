/** Current native form flow; no direct command or forced hidden-control interaction. */
export async function openProjectWizard(page,title){
    await page.locator('#create-project-only').click();
    await page.locator('#job-title').fill(title);
    await page.locator('#job-next').click();
    await page.locator('#job-next').click();
}
export async function createProjectThroughWizard(page,title){await openProjectWizard(page,title);await page.locator('#job-save').click();await page.locator('#job-wizard').waitFor({state:'hidden'});}
