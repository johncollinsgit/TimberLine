import assert from 'node:assert/strict';
import {expect} from '@playwright/test';

export async function checkMedicalOriginal(context, origin, engine) {
  const page = await context.newPage();
  await page.setViewportSize({width:engine === 'webkit' ? 390 : 1280, height:850});
  const filename = 'Fictional ' + engine + ' statement.PDF';
  const bytes = Buffer.from('%PDF-1.7\n' + engine + ' original\n%%EOF\n');
  const note = engine + ' household evidence for scheduled payments.';
  await page.goto(origin + '/trajectory?tab=medical');
  const folder = page.locator('[data-medical-documents=""]');
  await folder.locator('summary').click();
  await folder.locator('input[type=file]').setInputFiles({name:filename, mimeType:'application/pdf', buffer:bytes});
  await folder.getByRole('textbox').fill(note);
  await folder.getByRole('button', {name:'Save original'}).click();
  await expect(folder.getByRole('status')).toHaveText('Original saved.');
  await page.reload();
  await folder.locator('summary').click();
  const original = folder.getByRole('link', {name:filename, exact:true});
  await expect(original).toBeVisible();
  const download = await context.request.get(new URL(await original.getAttribute('href'), origin).href);
  assert.deepEqual(await download.body(), bytes);
  await page.locator('.tr-assistant-launch').click();
  await page.locator('#tr-assistant-question').fill('Find my ' + engine + ' medical statement');
  await page.getByRole('button', {name:'Send', exact:true}).click();
  const answer = page.locator('.tr-assistant-panel .tr-ask-documents').last();
  await expect(answer.getByRole('link', {name:filename,exact:true})).toBeVisible();
  await expect(answer).toContainText(note);
  await page.close();
}
