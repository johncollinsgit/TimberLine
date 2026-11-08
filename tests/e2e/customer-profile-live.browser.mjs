import { mkdtemp, writeFile, readFile, mkdir, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { spawn, execFile } from "node:child_process";
import { promisify } from "node:util";
import { createServer } from "node:net";
import { randomUUID } from "node:crypto";
import { chromium } from "playwright";
import assert from "node:assert/strict";

const exec = promisify(execFile);
const base = resolve();
const evidence = resolve(process.env.CUSTOMER_PROFILE_EVIDENCE || "test-results/customer-profile-browser");
await mkdir(evidence, { recursive: true });
const probe = createServer();
await new Promise((done) => probe.listen(0, "127.0.0.1", done));
const port = probe.address().port;
await new Promise((done) => probe.close(done));
const dir = await mkdtemp(join(tmpdir(), "codex-customer-profile-"));
await writeFile(join(dir, "db.sqlite"), "");
await writeFile(join(dir, "http.log"), "");
const origin = `http://127.0.0.1:${port}`;
const env = {
  ...process.env, APP_ENV: "testing", APP_DEBUG: "false", APP_URL: origin,
  APP_KEY: "base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=", APP_CONFIG_CACHE: join(dir, "config.php"),
  DB_CONNECTION: "sqlite", DB_DATABASE: join(dir, "db.sqlite"), DB_URL: "", DATABASE_URL: "",
  CACHE_STORE: "array", SESSION_DRIVER: "database", SESSION_SECURE_COOKIE: "false", SESSION_DOMAIN: "",
  MAIL_MAILER: "array", QUEUE_CONNECTION: "sync", BCRYPT_ROUNDS: "4", LOG_CHANNEL: "null",
  NIGHTWATCH_ENABLED: "false", TELESCOPE_ENABLED: "false", PULSE_ENABLED: "false", EVERBRANCH_AI_STANDARD_ENABLED: "false",
  CUSTOMER_PROFILE_EMAIL_DISPATCH_ENABLED: "false", CUSTOMER_PROFILE_SMS_DISPATCH_ENABLED: "false",
  MARKETING_EMAIL_ENABLED: "false", MARKETING_SMS_ENABLED: "false", CUSTOMER_PROFILE_BROWSER_HTTP_LOG: join(dir, "http.log"),
};
let server, browser, page;
const errors = [], blockedNetwork = [], screenshots = [];
async function fixtureReport() {
  return JSON.parse((await exec("php", [resolve("scripts/everbranch-team/customer-profile-browser-fixture.php"), "report"], { cwd: base, env })).stdout);
}
async function login(name, viewport = { width: 1440, height: 1000 }) {
  const context = await browser.newContext({ viewport });
  await context.route("**/*", (route) => {
    if (new URL(route.request().url()).origin === origin) return route.continue();
    blockedNetwork.push(new URL(route.request().url()).hostname);
    return route.abort();
  });
  const loginPage = await context.request.get(origin + "/login");
  const csrf = (await loginPage.text()).match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];
  assert.ok(csrf, "Real login CSRF token");
  const response = await context.request.post(origin + "/login", { form: {
    email: `${name}@customer-fixture.invalid`, password: "Fictional-local-only-2026!", _token: csrf,
  }, headers: { Accept: "application/json" } });
  assert.ok(response.ok(), `Fortify login failed: ${response.status()}`);
  return context;
}
async function snapshot(name, viewport, target) {
  await page.setViewportSize(viewport);
  if (target) await target.scrollIntoViewIfNeeded();
  await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))));
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `${name}: no horizontal page overflow`);
  if (target && await target.evaluate((element) => element.tagName === "IFRAME")) {
    const detail = join(evidence, `${name}-email.png`);
    await target.screenshot({ path: detail });
    screenshots.push(detail);
  }
  const file = join(evidence, `${name}.png`);
  // The app scrolls inside a fixed workspace shell; capture its actual viewport.
  await page.screenshot({ path: file });
  screenshots.push(file);
}
try {
  await exec("php", ["artisan", "migrate", "--force", "--no-interaction"], { cwd: base, env, maxBuffer: 2e6 });
  const fixture = JSON.parse((await exec("php", [resolve("scripts/everbranch-team/customer-profile-browser-fixture.php")], { cwd: base, env })).stdout);
  server = spawn("php", ["-S", `127.0.0.1:${port}`, "-t", resolve("public"), resolve("tests/e2e/customer-profile-browser-router.php")], { cwd: base, env, stdio: "ignore" });
  let ready = false;
  for (let i = 0; i < 100; i++) {
    try { if ((await fetch(origin + "/up")).ok) { ready = true; break; } } catch {}
    await new Promise((done) => setTimeout(done, 100));
  }
  assert.ok(ready, "Private Laravel HTTP server started");
  browser = await chromium.launch();
  const owner = await login("owner");
  page = await owner.newPage();
  page.setDefaultTimeout(10000);
  page.on("pageerror", (error) => errors.push(error.message));
  const directory = `${origin}/workspace/customers?tenant=${fixture.tenant}`;
  const profile = `${origin}/workspace/customers/${fixture.profile_id}?tenant=${fixture.tenant}`;
  await page.goto(directory);
  await page.getByRole("heading", { name: "Fictional Customer Shop · Customers", exact: true }).waitFor();
  await page.getByLabel("Name, phone or email", { exact: true }).fill("8645550101");
  await page.getByRole("button", { name: "Find customers", exact: true }).click();
  await page.getByRole("link").filter({ has: page.getByText("Ada Browser", { exact: true }) }).click();
  await page.getByRole("heading", { name: "Ada Browser", exact: true }).waitFor();
  await page.getByRole("link", { name: "All customers", exact: true }).click();
  await page.getByRole("heading", { name: "Fictional Customer Shop · Customers", exact: true }).waitFor();
  await page.locator("[data-command-trigger]:visible").first().click();
  await page.locator("[data-command-input]:visible").fill("8645550101");
  await page.locator("[data-command-result]").filter({ hasText: "Ada Browser" }).click();
  await page.waitForURL(profile);
  await page.getByRole("heading", { name: "Ada Browser", exact: true }).waitFor();
  const pine = page.locator("li").filter({ has: page.getByText("Fictional Pine", { exact: true }) });
  await pine.getByText("3 units · 1 purchase", { exact: true }).waitFor();
  await page.locator(".customer-commerce li").filter({ has: page.getByText("Fictional Cedar", { exact: true }) }).getByText("1 unit · 1 purchase", { exact: true }).waitFor();
  assert.equal(await page.getByText("PRIVATE marketing-only fixture note", { exact: true }).count(), 0);
  await snapshot("profile-desktop", { width: 1440, height: 1000 });
  await snapshot("profile-mobile", { width: 390, height: 844 });
  await snapshot("profile-small-phone", { width: 320, height: 844 });
  const commerce = page.getByRole("region", { name: "Purchases and rewards", exact: true });
  const originalNames = await commerce.locator(".customer-commerce-name").allTextContents();
  await commerce.locator(".customer-commerce-name").evaluateAll((names) => names.forEach((name, index) => {
    name.textContent = index % 2 ? "FictionalCedarCandleWithAnUnbrokenRecordedProductNameThatMustWrap" : "Fictional Pine · Northern Woodland Collection · Large Candle";
  }));
  for (const width of [320, 390, 1440]) {
    await page.setViewportSize({ width, height: width === 1440 ? 1000 : 844 });
    const rows = await commerce.locator("li:has(.customer-commerce-count)").evaluateAll((items) => items.map((row) => {
      const name = row.querySelector(".customer-commerce-name").getBoundingClientRect();
      const count = row.querySelector(".customer-commerce-count").getBoundingClientRect();
      const bounds = row.getBoundingClientRect();
      return { fits: name.right <= bounds.right + 1 && count.right <= bounds.right + 1, stacked: count.top >= name.bottom - 1, aligned: Math.abs(count.top - name.top) <= 1 };
    }));
    assert.equal(rows.every((row) => row.fits && (width <= 640 ? row.stacked : row.aligned)), true, `Shared commerce count alignment at ${width}`);
    await commerce.getByText("No rewards balance recorded", { exact: true }).waitFor();
    await snapshot(`commerce-long-names-${width}`, { width, height: width === 1440 ? 1000 : 844 }, commerce);
    await snapshot(`commerce-ranked-products-${width}`, { width, height: width === 1440 ? 1000 : 844 }, commerce.locator("li:has(.customer-commerce-count)").last());
  }
  await commerce.locator(".customer-commerce-name").evaluateAll((names, originals) => names.forEach((name, index) => { name.textContent = originals[index]; }), originalNames);
  await page.setViewportSize({ width: 390, height: 844 });
  const preferences = page.getByRole("region", { name: "Customer preferences", exact: true });
  const receiptEmail = preferences.locator('select[name="changes[receipt_email]"]');
  const receiptSms = preferences.locator('select[name="changes[receipt_sms]"]');
  assert.equal(await receiptEmail.inputValue(), "");
  assert.equal(await receiptSms.inputValue(), "");
  await receiptEmail.selectOption("0");
  await preferences.getByRole("checkbox", { name: "The customer explicitly reported the choices I am applying.", exact: true }).check();
  await Promise.all([page.waitForNavigation({ waitUntil: "domcontentloaded" }), preferences.getByRole("button", { name: "Save customer choices", exact: true }).click()]);
  await page.waitForURL(profile);
  let report = await fixtureReport();
  assert.equal(report.receipts.email, false);
  assert.equal(report.receipts.sms, null);
  assert.equal(report.declared.favorite_scents, null);
  assert.equal(await receiptEmail.inputValue(), "0");
  await snapshot("preferences-mobile", { width: 390, height: 844 }, preferences.locator("dl"));
  await snapshot("preferences-desktop", { width: 1440, height: 1000 }, preferences.locator("dl"));
  await preferences.getByRole("checkbox", { name: /Apply this customer.*favorite scents choice/ }).check();
  await preferences.getByLabel("Favorite scents (one per line)", { exact: true }).fill("Fictional Cedar");
  await preferences.getByRole("checkbox", { name: "The customer explicitly reported the choices I am applying.", exact: true }).check();
  await Promise.all([page.waitForNavigation({ waitUntil: "domcontentloaded" }), preferences.getByRole("button", { name: "Save customer choices", exact: true }).click()]);
  await page.waitForURL(profile);
  report = await fixtureReport();
  assert.deepEqual(report.declared.favorite_scents, ["Fictional Cedar"]);
  assert.equal(report.receipts.email, false);
  assert.equal(report.receipts.sms, null);
  const personal = page.getByRole("region", { name: "Personalized messages", exact: true });
  await personal.getByLabel("Email subject", { exact: true }).fill("A note for {{first_name}}");
  await personal.locator('textarea[name="body"]').fill("Hi {{first_name}}, your most purchased scent is {{most_purchased_scent}}. Your declared favorite is {{favorite_scents}}.");
  await personal.getByText("Email formatting", { exact: true }).click();
  await personal.getByLabel("Heading", { exact: true }).fill("Just for {{first_name}}");
  await personal.getByLabel("Button text", { exact: true }).fill("Browse {{company_name}}");
  await personal.getByLabel("Button HTTPS link", { exact: true }).fill("https://example.invalid/fictional-shop");
  await personal.getByRole("button", { name: "Preview personalized message", exact: true }).click();
  const expectedBody = "Hi Ada, your most purchased scent is Fictional Pine. Your declared favorite is Fictional Cedar.";
  await personal.getByText(expectedBody, { exact: true }).waitFor();
  const preview = page.frameLocator('iframe[title="Formatted email preview"]');
  await preview.getByText("Just for Ada", { exact: true }).waitFor();
  await preview.getByRole("link", { name: "Browse Fictional Customer Shop", exact: true }).waitFor();
  assert.equal(await page.locator('iframe[title="Formatted email preview"]').getAttribute("sandbox"), "");
  assert.ok((await page.locator('iframe[title="Formatted email preview"]').boundingBox()).height >= 380, "Current built CSS gives the formatted preview a readable height");
  await snapshot("personal-preview-mobile", { width: 390, height: 844 }, page.locator('iframe[title="Formatted email preview"]'));
  await snapshot("personal-preview-desktop", { width: 1440, height: 1000 }, page.locator('iframe[title="Formatted email preview"]'));
  const frozenSrcdoc = await page.locator('iframe[title="Formatted email preview"]').getAttribute("srcdoc");
  const originalInput = await personal.locator("form").evaluate((form) => Object.fromEntries(new FormData(form)));
  await personal.getByRole("button", { name: "Save campaign draft for review", exact: true }).click();
  await page.waitForURL(/\/workspace\/customers\/\d+\/outreach\/\d+\?tenant=/);
  const reviewUrl = page.url();
  await page.getByRole("heading", { name: "Review personal email draft", exact: true }).waitFor();
  await page.getByRole("region", { name: "Frozen customer message", exact: true }).getByText(expectedBody, { exact: true }).waitFor();
  assert.equal(await page.locator('iframe[title="Frozen formatted email preview"]').getAttribute("srcdoc"), frozenSrcdoc);
  assert.equal(await page.getByRole("button", { name: "Send approved message", exact: true }).count(), 0);
  await page.getByText("outreach email safety review required", { exact: true }).waitFor();
  await page.getByRole("checkbox", { name: "I reviewed this exact prepared content for this customer. Approval does not send or grant marketing consent.", exact: true }).check();
  await page.getByRole("button", { name: "Approve frozen content", exact: true }).click();
  await page.getByText("Content approval recorded. No message sent and no consent changed.", { exact: true }).waitFor();
  await snapshot("frozen-review-desktop", { width: 1440, height: 1000 }, page.getByRole("region", { name: "Frozen customer message", exact: true }));
  await snapshot("frozen-review-mobile", { width: 390, height: 844 }, page.getByRole("region", { name: "Current delivery checks", exact: true }));
  const saved = await owner.request.post(`${origin}/workspace/customers/${fixture.profile_id}/outreach?tenant=${fixture.tenant}`, {
    form: { ...originalInput, action: "save" }, maxRedirects: 0,
  });
  assert.equal(saved.status(), 302);
  assert.equal(saved.headers().location, reviewUrl);
  const jsonReview = await owner.request.get(reviewUrl, { headers: { Accept: "application/json" } });
  const current = (await jsonReview.json()).review;
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute("content");
  const blockedSend = await owner.request.post(reviewUrl, { headers: { Accept: "application/json", "X-CSRF-TOKEN": csrf }, data: {
    action: "send", request_id: randomUUID(), reviewed_content_hash: current.content_hash, confirm_send: true,
  } });
  assert.equal(blockedSend.status(), 200);
  assert.equal((await blockedSend.json()).action_result.outcome, "blocked");
  const member = await login("member", { width: 390, height: 844 });
  const readOnly = await member.newPage();
  readOnly.on("pageerror", (error) => errors.push(error.message));
  await readOnly.goto(profile);
  await readOnly.getByRole("heading", { name: "Ada Browser", exact: true }).waitFor();
  assert.equal(await readOnly.getByRole("button", { name: "Save customer choices", exact: true }).count(), 0);
  assert.equal(await readOnly.getByRole("region", { name: "Personalized messages", exact: true }).count(), 0);
  const forbiddenReview = await member.request.get(reviewUrl, { headers: { Accept: "application/json" } });
  assert.equal(forbiddenReview.status(), 403);
  const preferenceUrl = `${origin}/workspace/customers/${fixture.profile_id}/preferences?tenant=${fixture.tenant}`;
  const noCsrf = await member.request.post(preferenceUrl, { data: {}, headers: { Accept: "application/json" } });
  assert.equal(noCsrf.status(), 419);
  const memberCsrf = await readOnly.locator('meta[name="csrf-token"]').getAttribute("content");
  const memberWrite = await member.request.post(preferenceUrl, { data: {}, headers: { Accept: "application/json", "X-CSRF-TOKEN": memberCsrf } });
  assert.equal(memberWrite.status(), 403);
  for (const url of [
    `${origin}/workspace/customers/${fixture.foreign_profile_id}?tenant=${fixture.tenant}`,
    `${origin}/workspace/customers/${fixture.foreign_profile_id}?tenant=${fixture.foreign_tenant}`,
  ]) {
    const response = await owner.request.get(url, { headers: { Accept: "application/json" } });
    assert.ok([403, 404].includes(response.status()), "Foreign tenant/customer denied");
    assert.ok(!(await response.text()).includes("Foreign Private Browser Customer"));
  }
  report = await fixtureReport();
  assert.equal(report.campaign_count, 1);
  assert.equal(report.approval_count, 1);
  assert.equal(report.email_delivery_count, 0);
  assert.equal(report.sms_delivery_count, 0);
  assert.equal(report.consent_event_count, 0);
  assert.equal(report.email_consent, false);
  assert.equal(report.sms_consent, false);
  assert.equal(report.email_dispatch_enabled, false);
  assert.equal(report.sms_dispatch_enabled, false);
  assert.equal(await readFile(join(dir, "http.log"), "utf8"), "");
  assert.deepEqual(errors, []);
  await rm(join(evidence, "failure.png"), { force: true });
  await rm(join(evidence, "failure.txt"), { force: true });
  const result = { transport: "real Fortify/Laravel HTTP and disposable SQLite", checks: [
    "Global-member owner opens Core profile through both directory and global normalized-phone search",
    "Confirmed purchase scents remain distinct from customer-declared favorites",
    "Unknown receipt preference remains null while explicit No persists false",
    "Formatted preview resolves profile fields in a sandboxed iframe",
    "Save redirects to immutable personal review; retry preserves one campaign",
    "Content approval never sends or grants consent; explicit send remains blocked",
    "Ordinary member reads without edit/review authority; missing CSRF and foreign tenant/customer denied",
    "Desktop/mobile pages have no horizontal overflow or JavaScript errors",
    "Shared commerce names/counts align at 320/390/1440; long labels wrap, singular labels are accurate, and unknown rewards stay unknown",
    "No provider HTTP, delivery rows or consent grants",
  ], report, screenshots, blocked_external_browser_hosts: [...new Set(blockedNetwork)] };
  await writeFile(join(evidence, "acceptance.json"), JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result, null, 2));
} catch (error) {
  if (page) {
    await page.screenshot({ path: join(evidence, "failure.png"), fullPage: true }).catch(() => {});
    await writeFile(join(evidence, "failure.txt"), `${error.stack}\n${await page.locator("body").innerText().catch(() => "Unavailable")}`);
  }
  throw error;
} finally {
  if (browser) await browser.close();
  if (server && server.exitCode === null) { server.kill("SIGTERM"); await new Promise((done) => server.once("exit", done)); }
  await rm(dir, { recursive: true, force: true });
}
