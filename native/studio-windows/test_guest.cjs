// Run with Node and the repo's Playwright dependency. Both device streams are synthetic.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const http = require("node:http");
const path = require("node:path");
const { chromium } = require("playwright");
const source = path.resolve(__dirname, "../../public/studio-guest");
const types = {".html":"text/html", ".css":"text/css",".js":"application/javascript"};
const server = http.createServer((req,res) => {
  const name = req.url === "/" ? "index.html" : req.url.slice(1);
  if (!["index.html","guest.css","guest.js"].includes(name)) { res.writeHead(404).end(); return; }
  res.writeHead(200,{"Content-Type":types[path.extname(name)]});
  fs.createReadStream(path.join(source,name)).pipe(res);
});
(async () => {
  await new Promise(resolve => server.listen(0, "127.0.0.1",resolve));
  let browser;
  try {
    browser = await chromium.launch({channel:process.env.STUDIO_TEST_BROWSER || "chrome",
      args:["--use-fake-device-for-media-stream","--use-fake-ui-for-media-stream","--autoplay-policy=no-user-gesture-required"]});
    const address = "http://127.0.0.1:" + server.address().port;
    async function guest(name) {
      const context = await browser.newContext({permissions:["camera","microphone"]});
      const page = await context.newPage();
      await page.goto(address);
      await page.locator("#name").fill(name);
      await page.locator("#allow").click();
      await page.locator("#start:enabled").waitFor();
      return page;
    }
    const host = await guest("Host fixture");
    const caller = await guest("Guest fixture");
    for (const page of [host,caller]) await page.locator("summary").click();
    await host.locator("#invite").click();
    await host.waitForFunction(() => document.querySelector("#outgoing").value.includes("offer"));
    await caller.locator("#incoming").fill(await host.locator("#outgoing").inputValue());
    await caller.locator("#answer").click();
    await caller.waitForFunction(() => document.querySelector("#outgoing").value.includes("answer"));
    await host.locator("#incoming").fill(await caller.locator("#outgoing").inputValue());
    await host.locator("#connect").click();
    await host.waitForFunction(() => document.getElementById("remote").readyState >= 2);
    await caller.waitForFunction(() => document.getElementById("remote").readyState >= 2);
    await caller.locator("#start").click();
    // Wait for committed chunks, then simulate abrupt tab loss.
    await caller.waitForFunction(async () => {
      const db = await new Promise(resolve => {const req=indexedDB.open("everbranch-studio-guest-v1");req.onsuccess=()=>resolve(req.result);});
      return await new Promise(resolve => {const req=db.transaction("chunks").objectStore("chunks").count();req.onsuccess=()=>{db.close();resolve(req.result>=2);};});
    });
    const context=caller.context();
    await caller.close({runBeforeUnload:false});
    const recovered=await context.newPage();
    await recovered.goto(address);
    await recovered.getByText(/MB · partial/).waitFor();
    await recovered.getByRole("button",{name:"Prepare download"}).click();
    await recovered.getByRole("link",{name:"Save recording"}).waitFor();
    const [download]=await Promise.all([recovered.waitForEvent("download",{timeout:10000}),recovered.getByRole("link",{name:"Save recording"}).click()]);
    assert.ok(fs.statSync(await download.path()).size>1000);
    await host.locator("#start").click();
    await host.waitForTimeout(1500);
    await host.locator("#stop").click();
    await host.getByText(/MB · finished/).waitFor();
    await host.getByRole("button",{name:"Prepare download"}).click();
    const [complete]=await Promise.all([host.waitForEvent("download"),host.getByRole("link",{name:"Save recording"}).click()]);
    assert.ok(fs.statSync(await complete.path()).size>1000);
    console.log("Two-browser peer call, finished recording, interrupted recording recovery, and both downloads passed using synthetic devices.");
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
