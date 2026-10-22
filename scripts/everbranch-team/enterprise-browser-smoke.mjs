import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdir, writeFile, readFile, unlink } from 'node:fs/promises';
import { createServer as createHttpsServer, request as httpsRequest } from 'node:https';
import { request as httpRequest } from 'node:http';
import { once } from 'node:events';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { checkWorkflowWorkspace } from './enterprise-workflow-browser.mjs';
import { checkMappedOperationsWorkflow } from './enterprise-operations-workflow-browser.mjs';
import { checkRollCallWorkspace } from './enterprise-roll-call-browser.mjs';
import { checkResourceWorkspace } from './enterprise-resources-browser.mjs';
import { checkTaskEvidenceWorkflow } from './enterprise-task-evidence-browser.mjs';
const operationsMode = process.env.ENTERPRISE_TEST_OPERATIONS === '1';
const operationsScenario = process.env.ENTERPRISE_OPERATIONS_SCENARIO || 'all';
assert.ok(['all', 'mapped', 'roll-call', 'resources', 'task-evidence'].includes(operationsScenario), 'Unsupported Operations browser scenario');
const php = process.env.ENTERPRISE_TEST_PHP;
assert.ok(php, 'ENTERPRISE_TEST_PHP is required');
const {
  chromium, webkit
} = await import(pathToFileURL(resolve(process.env.EVERBRANCH_PLAYWRIGHT_MODULE)).href);
const db = resolve(`apps/enterprise/storage/framework/testing/enterprise-browser-${Date.now()}.sqlite`);
const browserEngine = process.env.ENTERPRISE_BROWSER_ENGINE || 'chromium';
assert.ok(['chromium','webkit'].includes(browserEngine),'Unsupported test browser engine');
await mkdir(resolve('apps/enterprise/storage/framework/testing'), {
  recursive: true
});
await writeFile(db, '');
const evidence = resolve(process.env.ENTERPRISE_EVIDENCE || '../evidence/enterprise');
await mkdir(evidence, {
  recursive: true
});
const origin = 'https://127.0.0.1:8879';
const env = {
  ...process.env,
  ENTERPRISE_APP_ENV: 'testing',
  ENTERPRISE_APP_URL: origin,
  ENTERPRISE_APP_KEY: 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
  ENTERPRISE_RUNTIME_ISOLATED: 'true',
  ENTERPRISE_DB_DRIVER: 'sqlite',
  ENTERPRISE_DB_DATABASE: db,
  ENTERPRISE_ENABLED: 'true',
  ENTERPRISE_WRITES_ENABLED: 'true',
  ENTERPRISE_COLLABORATION_ENABLED: 'true',
  ENTERPRISE_PLANNING_ENABLED: 'true',
  ENTERPRISE_ASK_ENABLED: 'true',
  ENTERPRISE_EMAIL_DRAFTS_ENABLED: 'true',
  ENTERPRISE_WORKFLOW_ENABLED: 'true',
  ENTERPRISE_BRIEFING_ENABLED: 'true',
  ENTERPRISE_SAMPLE_DATA: 'true',
  ENTERPRISE_OPERATIONS_ENABLED: operationsMode ? 'true' : 'false',
  ENTERPRISE_ROLL_CALL_ENABLED: operationsMode ? 'true' : 'false',
  ENTERPRISE_RESOURCES_ENABLED: operationsMode ? 'true' : 'false',
  ENTERPRISE_REQUESTER_ENABLED: operationsMode ? 'true' : 'false',
  ENTERPRISE_TENANT_ALLOWLIST: '1'
};
const keyFile = db + '.key', certFile = db + '.crt';
let server, serverExit, proxy, browser, page;
try {
  const seeded = spawnSync(php, ['scripts/everbranch-team/enterprise-browser-fixture.php'], {
    env,
    encoding: 'utf8',
    windowsHide: true
  });
  assert.equal(seeded.status, 0, seeded.stdout + seeded.stderr);
  // One-use loopback TLS fixture. No trust-store change or persistent credential.
  const generated = spawnSync(process.env.ENTERPRISE_TEST_OPENSSL || (process.platform === 'win32' ? 'C:/Program Files/Git/usr/bin/openssl.exe' : 'openssl'),
    ['req','-x509','-newkey','rsa:2048','-nodes','-keyout',keyFile,'-out',certFile,'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],
    {encoding:'utf8',windowsHide:true});
  assert.equal(generated.status,0,'Unable to create the disposable loopback TLS fixture');
  const key = await readFile(keyFile), cert = await readFile(certFile);
  await unlink(keyFile); await unlink(certFile);
  server = spawn(php, ['-S', '127.0.0.1:8878', '-t', 'apps/enterprise/public', 'tests/Support/enterprise-https-router.php'], {
    env,
    stdio: 'ignore',
    windowsHide: true
  });
  serverExit = once(server,'exit');
  proxy = createHttpsServer({key,cert}, (req,res) => {
    const upstream = httpRequest({hostname:'127.0.0.1',port:8878,path:req.url,method:req.method,headers:req.headers}, response => {
      res.writeHead(response.statusCode,response.headers); response.pipe(res);
    });
    upstream.on('error', () => {if (!res.headersSent) res.writeHead(503); res.end('Local fixture is starting.');});
    req.pipe(upstream);
  });
  proxy.listen(8879,'127.0.0.1'); await once(proxy,'listening');
  let ready = false;
  for (let i = 0; i < 40; i++) {
    try {
      const ok = await new Promise(resolve => {
        const request = httpsRequest(origin + '/login',{ca:cert}, response => {response.resume(); resolve(response.statusCode === 200);});
        request.on('error',() => resolve(false)); request.end();
      });
      if (ok) {
        ready = true;
        break;
      }
    } catch {}
    await new Promise(r => setTimeout(r, 250));
  }
  assert.ok(ready, 'Isolated local server did not start');
  browser = await (browserEngine === 'webkit' ? webkit : chromium).launch({
    headless: true,
    ...(browserEngine !== 'chromium' ? {} : process.env.ENTERPRISE_CHROME ? {
      executablePath: process.env.ENTERPRISE_CHROME
    } : process.platform === 'win32' ? {
      executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe'
    } : {})
  });
  const context = await browser.newContext({
    hasTouch: true,
    ignoreHTTPSErrors: true, // This context only: the one-use self-signed loopback fixture.
    viewport: {
      width: 1440,
      height: 1000
    }
  });
  const external = [];
  let dynamicDispatch = 0, dynamicQueue = Promise.resolve();
  await context.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.hostname === '127.0.0.1') {
      // The combined synthetic scenario performs far more actions than a single user.
      // Respect the real server throttle; never disable it for browser acceptance.
      if (operationsMode && operationsScenario === 'all' && !url.pathname.startsWith('/build/')) {
        await (dynamicQueue = dynamicQueue.then(async () => {
          const delay = Math.max(0, dynamicDispatch + 800 - Date.now());
          if (delay) await new Promise(resolve => setTimeout(resolve, delay));
          dynamicDispatch = Date.now();
        }));
      }
      return route.continue();
    }
    external.push(route.request().url());
    return route.abort();
  });
  page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(origin + '/login');
  await page.getByLabel('Email', {
    exact: true
  }).fill('fixture-owner@example.invalid');
  await page.getByLabel('Password', {
    exact: true
  }).fill('Synthetic-browser-only-2026!');
  const [login] = await Promise.all([page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/login'),
    page.getByRole('button', {name:'Sign in',exact:true}).click()]);
  assert.equal(login.status(),200,'Enterprise sign-in must preserve its secure session');
  await page.getByRole('link', {
    name: 'Synthetic Enterprise'
  }).click();
  if (operationsMode) {
    if (['all', 'mapped'].includes(operationsScenario)) await checkMappedOperationsWorkflow(page,evidence);
    if (['all', 'roll-call'].includes(operationsScenario)) await checkRollCallWorkspace(page,evidence);
    if (['all', 'resources'].includes(operationsScenario)) await checkResourceWorkspace(page,evidence);
    if (['all', 'task-evidence'].includes(operationsScenario)) await checkTaskEvidenceWorkflow(page,evidence);
  } else {
  await page.getByRole('button', {
    name: 'New workspace',
    exact: true
  }).click();
  await page.getByLabel('Title', {
    exact: true
  }).fill('Synthetic Facilities');
  await page.getByRole('button', {
    name: 'Save',
    exact: true
  }).click();
  await page.locator('#visibility').filter({
    hasText: 'Department only'
  }).waitFor();
  await page.getByRole('button', {
    name: 'Operations & projects',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'New project',
    exact: true
  }).click();
  await page.getByLabel('Title', {
    exact: true
  }).fill('Synthetic repair project');
  await page.getByRole('button', {
    name: 'Save',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Synthetic repair project',
    exact: true
  }).waitFor();
  await page.getByRole('button', {
    name: 'Requests',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'New request',
    exact: true
  }).click();
  await page.getByLabel('Title', {
    exact: true
  }).fill('Synthetic inspection');
  await page.getByLabel('Work description').fill('Disposable UI fixture; no college incident.');
  await page.getByLabel('Due date').fill('2026-11-01');
  await page.getByLabel('Location label').fill('Fixture coordinate');
  await page.getByLabel('Latitude (optional)', {
    exact: true
  }).fill('35');
  await page.getByLabel('Longitude (optional)', {
    exact: true
  }).fill('-82');
  await page.getByRole('button', {
    name: 'Save',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Synthetic inspection',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Triage request',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Assign task',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Save progress',
    exact: true
  }).waitFor();
  await page.getByRole('button', {
    name: 'Map',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Open Synthetic inspection',
    exact: true
  }).click();
  await page.screenshot({
    path: resolve(evidence, 'enterprise-map-desktop.png'),
    fullPage: true
  });
  await page.getByRole('button', {
    name: 'Hide Map',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'View Map',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Calendar',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Synthetic inspection',
    exact: true
  }).click();
  await page.getByRole('button', {
    name: 'Complete work',
    exact: true
  }).click();
  await page.locator('#detail .badge').filter({
    hasText: 'completed'
  }).waitFor();
  await page.getByRole('button', {
    name: 'Audit history',
    exact: true
  }).click();
  await page.getByText('Version 4 · request.complete', {
    exact: false
  }).waitFor();
  await page.reload();
  await page.getByRole('button', {
    name: 'Synthetic inspection',
    exact: true
  }).click();
  await page.locator('#detail .badge').filter({
    hasText: 'completed'
  }).waitFor();
  await page.setViewportSize({
    width: 390,
    height: 844
  });
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Mobile horizontal overflow');
  await page.screenshot({
    path: resolve(evidence, 'enterprise-mobile.png'),
    fullPage: true
  });
  await page.getByRole('button', {
    name: 'Contractors',
    exact: true
  }).click();
  await page.getByRole('heading', {name:'Contractors & plans',exact:true}).waitFor();
  await page.getByLabel('Record title', {exact:true}).fill('Synthetic contractor');
  await page.getByRole('button', {name:'Save record',exact:true}).click();
  await page.locator('#planning-list').getByText('Synthetic contractor', {exact:true}).waitFor();
  await page.getByLabel('Record type').selectOption('schedule');
  await page.getByLabel('Record title', {exact:true}).fill('Synthetic date block');
  await page.getByLabel('Start date', {exact:true}).fill('2026-11-04');
  await page.getByLabel('End date (inclusive)', {exact:true}).fill('2026-11-05');
  await page.getByRole('button', {name:'Save record',exact:true}).click();
  await page.locator('#planning-list').getByText('Synthetic date block', {exact:true}).waitFor();
  await page.getByRole('button', {name:'Operations & projects',exact:true}).click();
  await page.getByRole('button', {name:'Synthetic repair project',exact:true}).click();
  const draftNote = 'Synthetic unsent project draft <img src=x onerror="window.__enterpriseInjection=1">';
  await page.getByLabel('Add a note').fill(draftNote);
  await page.getByRole('button', {name:'Requests',exact:true}).click();
  await page.getByRole('button', {name:'Operations & projects',exact:true}).click();
  await page.waitForFunction(value => document.getElementById('discussion-body')?.value === value, draftNote);
  await page.reload();
  await page.waitForFunction(value => document.getElementById('discussion-body')?.value === value, draftNote);
  let droppedNote = false;
  await page.route('**/enterprise/discussion/project/*/comments?*', async route => {
    if (!droppedNote) {droppedNote = true; await route.fetch(); return route.abort();}
    return route.continue();
  });
  await page.getByRole('button', {name:'Post note',exact:true}).click();
  await page.locator('#status').filter({hasText:'retained for retry'}).waitFor();
  await page.getByRole('button', {name:'Post note',exact:true}).click();
  await page.locator('.discussion-note').filter({hasText:'Synthetic unsent project draft'}).waitFor();
  assert.equal(await page.locator('.discussion-note').filter({hasText:'Synthetic unsent project draft'}).count(),1,'Interrupted note was duplicated');
  assert.equal(await page.locator('#discussion img').count(),0,'Record text became HTML');
  assert.equal(await page.evaluate(() => window.__enterpriseInjection),undefined,'Record text executed a script');
  let droppedChunk = false;
  await page.route('**/team/file-uploads/*/chunks/*?*', async route => {
    if (!droppedChunk) {droppedChunk = true; await route.fetch(); return route.abort();}
    return route.continue();
  });
  const attachment = {name:'synthetic-resume.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic resumable attachment. '.repeat(12000))};
  await page.locator('#discussion-files-picker').setInputFiles(attachment);
  await page.locator('#upload-status').filter({hasText:'Select the same file to resume'}).waitFor();
  await page.locator('#discussion-files-picker').setInputFiles([]);
  await page.locator('#discussion-files-picker').setInputFiles(attachment);
  await page.locator('#discussion-files').getByText('synthetic-resume.txt · quarantined', {exact:true}).waitFor();
  assert.equal(await page.locator('#discussion-files .file-row').count(),1,'Interrupted upload was duplicated');
  assert.equal(await page.locator('#discussion-files').getByRole('link',{name:'Download',exact:true}).count(),0,'Unscanned bytes became downloadable');
  await page.locator('#discussion-files-picker').setInputFiles([
    {name:'synthetic-brief.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF')},
    {name:'synthetic-photo.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jxioAAAAASUVORK5CYII=','base64')}
  ]);
  await page.locator('#discussion-files').getByText('synthetic-photo.png · quarantined',{exact:true}).waitFor();
  await page.locator('#discussion-files').getByText('synthetic-brief.pdf · quarantined',{exact:true}).waitFor();
  assert.equal(await page.locator('#discussion-files .file-row').count(),3,'Supported photo/PDF/text uploads did not persist');
  for (const [label,width,height] of [['desktop',1440,1000],['ipad-portrait',820,1180],['ipad-landscape',1180,820],['phone',390,844]]) {
    await page.setViewportSize({width,height});
    await page.getByLabel('Add a note').fill(`Unsent ${label} draft`);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),`${label} horizontal overflow`);
    assert.ok(await page.locator('#note-submit').evaluate(el => el.getBoundingClientRect().height >= 44),`${label} note touch target too small`);
    assert.ok(await page.locator('#discussion-files-picker').evaluate(el => el.accept.includes('application/pdf') && el.accept.includes('image/jpeg')),`${label} photo/PDF picker missing`);
    await page.screenshot({path:resolve(evidence,`enterprise-next-${label}.png`),fullPage:true});
  }
  await page.getByRole('button', {name:'Ask Everbranch',exact:true}).click();
  await page.getByLabel('Message Ask Everbranch').fill('What is planned?');
  await page.getByRole('button', {name:'Ask',exact:true}).click();
  await page.locator('#chat-log a').filter({hasText:'View source:'}).first().waitFor();
  await page.locator('#chat-log p').filter({hasText:'Synthetic date block'}).first().waitFor();
  await page.locator('#mailbox-status').filter({hasText:'No delivery transport connected'}).waitFor();
  await page.locator('#gmail-status').filter({hasText:'approved Enterprise Google client'}).waitFor();
  assert.equal(await page.locator('#gmail-connect').isDisabled(),true,'Unconfigured Google connection must stay disabled');
  assert.equal(await page.locator('#gmail-disconnect').isVisible(),false,'No invented Google grant may be displayed');
  for (const [label,width,height] of [['ipad-portrait',820,1180],['ipad-landscape',1180,820],['phone',390,844]]) {
    await page.setViewportSize({width,height});
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),`${label} Ask overflow`);
    assert.ok(await page.locator('#ask-submit').evaluate(el => el.getBoundingClientRect().height >= 44),`${label} Ask touch target`);
    await page.screenshot({path:resolve(evidence,`enterprise-ask-${label}.png`),fullPage:true});
  }
  await checkWorkflowWorkspace(page,evidence);
  }
  assert.deepEqual(errors, []);
  assert.deepEqual(external, []);
  console.log(operationsMode ? `PASS (${browserEngine}): Operations browser scenario ${operationsScenario}; zero external requests and JavaScript errors. Viewport simulation only, not physical iPad acceptance.` : `PASS (${browserEngine}): independent runtime intake lifecycle and map; contractor/date planning; larger project details, retained drafts, exact interrupted note retry, resumable private quarantined upload; desktop/phone/iPad portrait/landscape viewport layout and touch targets; user-aware record-backed Ask citations and disconnected mailbox; zero external requests and JavaScript errors. Viewport simulation only, not physical iPad acceptance.`);
} catch(error) {
  if(page) {await page.screenshot({path:resolve(evidence,'failure.png'),fullPage:true}).catch(()=>{});await writeFile(resolve(evidence,'failure.txt'),`${error.stack}\n${await page.locator('body').innerText().catch(()=>'Unavailable')}`).catch(()=>{});}
  throw error;
} finally {
  if (browser) await browser.close();
  if (proxy) {proxy.closeAllConnections(); await new Promise(resolve => proxy.close(resolve));}
  if (server && server.exitCode === null) {server.kill(); await serverExit;}
  await unlink(db).catch(() => {});
  await unlink(keyFile).catch(() => {}); await unlink(certFile).catch(() => {});
}
