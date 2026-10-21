// Development-only visual evidence. Uses a new headless process, never user tabs.
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
const {
  chromium
} = await import(pathToFileURL(resolve(process.env.EVERBRANCH_PLAYWRIGHT_MODULE)).href);
const output = resolve(process.env.ENTERPRISE_EVIDENCE || '../evidence/enterprise');
await mkdir(output, {
  recursive: true
});
const browser = await chromium.launch({
  headless: true,
  executablePath: process.env.ENTERPRISE_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe'
});
try {
  const page = await browser.newPage({
    viewport: {
      width: 1440,
      height: 1000
    }
  });
  await page.route('**/*', route => route.request().url().startsWith('file:') ? route.continue() : route.abort());
  await page.goto(pathToFileURL(resolve('resources/demos/lees-mcrae-campus.html')).href);
  await page.screenshot({
    path: resolve(output, 'campus-reference.png'),
    fullPage: false
  });
  console.log(JSON.stringify({
    title: await page.title(),
    screenshot: resolve(output, 'campus-reference.png')
  }));
} finally {
  await browser.close();
}
