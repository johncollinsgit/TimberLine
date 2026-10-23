import assert from "node:assert/strict";
import {checkExecutiveDemo} from './enterprise-executive-browser.mjs';
import { spawn } from "node:child_process";
import { once } from "node:events";
import { mkdir, writeFile } from "node:fs/promises";
import { request } from "node:http";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

const php = process.env.ENTERPRISE_TEST_PHP;
assert.ok(php, "ENTERPRISE_TEST_PHP is required");
const { chromium, webkit } = await import(
    pathToFileURL(
        resolve(
            process.env.EVERBRANCH_PLAYWRIGHT_MODULE ||
                "node_modules/playwright/index.mjs",
        ),
    ).href
);
const engine = process.env.ENTERPRISE_BROWSER_ENGINE || "chromium";
assert.ok(["chromium", "webkit"].includes(engine));
const origin = "http://127.0.0.1:8892",
    url = origin + "/platform/demos/lees-mcrae";
const evidence = resolve(
    process.env.ENTERPRISE_EVIDENCE ||
        "../evidence/enterprise/reviewer-shared/" + engine,
);
await mkdir(evidence, { recursive: true });
const server = spawn(
    php,
    [
        "-S",
        "127.0.0.1:8892",
        "-t",
        "apps/enterprise/public",
        "scripts/everbranch-team/enterprise-reviewer-http-router.php",
    ],
    {
        env: {
            ...process.env,
            ENTERPRISE_APP_ENV: "testing",
            ENTERPRISE_APP_URL: origin,
            ENTERPRISE_APP_KEY:
                "base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=",
            ENTERPRISE_RUNTIME_ISOLATED: "true",
            ENTERPRISE_DB_DRIVER: "sqlite",
            ENTERPRISE_DB_DATABASE: ":memory:",
            ENTERPRISE_ENABLED: "false",
            ENTERPRISE_WRITES_ENABLED: "false",
        },
        stdio: ["ignore", "pipe", "pipe"],
        windowsHide: true,
    },
);
let serverLog = "",
    readiness = "";
server.stdout.on("data", (chunk) => {
    serverLog = (serverLog + chunk).slice(-12000);
});
server.stderr.on("data", (chunk) => {
    serverLog = (serverLog + chunk).slice(-12000);
});
const exited = once(server, "exit");
let browser, page;
const requests = [],
    errors = [];
try {
    let ready = false;
    for (let index = 0; index < 40; index++) {
        ready = await new Promise((resolve) => {
            const check = request(url, (response) => {
                let body = "";
                response.on("data", (chunk) => {
                    body = (body + chunk).slice(-1000);
                });
                response.on("end", () => {
                    readiness = String(response.statusCode) + ":" + body;
                    resolve(response.statusCode === 200);
                });
            });
            check.on("error", () => resolve(false));
            check.end();
        });
        if (ready) break;
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    assert.ok(
        ready,
        "Isolated reviewer HTTP fixture did not become ready: " +
            readiness +
            "\n" +
            serverLog,
    );
    browser = await (engine === "webkit" ? webkit : chromium).launch(
        engine === "chromium" && process.platform === "win32"
            ? {
                  executablePath:
                      "C:/Program Files/Google/Chrome/Application/chrome.exe",
                  headless: true,
              }
            : { headless: true },
    );
    page = await browser.newPage({
        hasTouch: true,
        viewport: { width: 1440, height: 1000 },
    });
    await page.addInitScript(() => {
        globalThis.sampleCsp = [];
        globalThis.sampleCspDetails = [];
        document.addEventListener("securitypolicyviolation", (event) => {
            sampleCsp.push(event.violatedDirective + ":" + event.blockedURI);
            sampleCspDetails.push({
                directive: event.violatedDirective,
                line: event.lineNumber,
                column: event.columnNumber,
                source: event.sourceFile,
                sample: event.sample,
            });
        });
    });
    page.on("request", (request) =>
        requests.push({ method: request.method(), url: request.url() }),
    );
    page.on("pageerror", (error) => errors.push(error.message));
    const response = await page.goto(url);
    assert.equal(response.status(), 200);
    assert.equal(response.headers()["x-reviewer-fixture-sql"], "0");
    assert.equal(response.headers()["set-cookie"], undefined);
    await checkExecutiveDemo(page,evidence);
    await page.locator('#reviewer-perspective').selectOption('manager');
    await page.waitForFunction(()=>document.getElementById('reviewer-perspective')?.value==='manager');
    await page.emulateMedia({ reducedMotion: "reduce" });
    const opener = page.getByRole("button", {name: "Priorities", exact:true});
    for (const [label,width,height] of [["desktop",1600,1280],["ipad-portrait",820,1180],["ipad-landscape",1180,820],["phone",390,844]]) {
        await page.setViewportSize({width,height});
        await opener.click();
        const dialog=page.locator("[data-priorities-dialog]");
        await page.getByRole("heading",{name:"Explore campus work.",exact:true}).waitFor();
        assert.equal(await dialog.locator("[data-priority-card]").count(),8);
        assert.equal(await page.locator("#erp-title").evaluate(e=>e===document.activeElement),true);
        assert.equal(await dialog.evaluate(e=>e.scrollWidth<=e.clientWidth+1),true,label+" modal overflow");
        await page.screenshot({path:resolve(evidence,"priorities-"+label+".png"),fullPage:true});
        for (const button of await dialog.locator('[data-priority-open]').all()) assert.ok((await button.boundingBox()).height>=44,label+' touch target');
        if(label==='phone') {
            await dialog.locator('[data-priority-card="funds"]').scrollIntoViewIfNeeded();
            await page.screenshot({path:resolve(evidence,'priorities-phone-bottom.png'),fullPage:true});
        }
        await page.keyboard.press("Escape");
        assert.equal(await opener.evaluate(e=>e===document.activeElement),true,"focus return");
    }
    await page.setViewportSize({width:1440,height:1000});
    await opener.click();
    await page.keyboard.press("Shift+Tab");
    assert.equal(await page.locator('[data-priorities-restart]').evaluate(e=>e===document.activeElement),true,'Modal reverse-tab trap');
    await page.keyboard.press("Tab");
    assert.equal(await page.locator('[data-priorities-close]').evaluate(e=>e===document.activeElement),true,'Modal forward-tab trap');
    await page.locator('[data-priority-open="visibility"]').click();
    await page.locator('#request-list [data-open]').first().waitFor();
    await page.waitForFunction(()=>!document.querySelector('[data-priorities-highlight]').hidden);
    const bar=page.locator('[data-priorities-bar]');
    assert.equal(await bar.isVisible(),true);
    assert.equal(await bar.getByRole('button',{name:'Proposal unavailable in this workspace',exact:true}).isDisabled(),true,'No proposal readiness claimed');
    await page.locator('[data-priorities-toggle]').click();
    assert.equal(await page.locator('[data-priorities-highlight]').isVisible(),false,'Hide clears spotlight');
    await page.locator('#request-list [data-open]').first().click();
    await page.locator('#discussion-body').fill('Synthetic unsent project note retained while exploring priorities.');
    const chosenProject=await page.locator('#request-list [data-open][aria-expanded="true"]').getAttribute('data-open');
    await page.locator('#close-work-detail').click();
    const selectedBefore=new URL(page.url()).hash;
    await page.locator('[data-priorities-overview]').click();
    await page.keyboard.press('Escape');
    assert.equal(new URL(page.url()).hash,selectedBefore,'Overview must preserve the closed-table navigation');
    await page.locator(`#request-list [data-open="${chosenProject}"]`).click();
    assert.equal(await page.locator('#discussion-body').inputValue(),'Synthetic unsent project note retained while exploring priorities.');
    await page.locator('#close-work-detail').click();
    for (const [id,target] of [['buildings','#res-query'],['crews','#roll-scope'],['schedules','#res-query'],['reports','#quarterly-preview'],['certificates','#certificate-new'],['invoices','#finance-list [data-finance-record]'],['funds','#finance-list [data-finance-record]']]) {
        await page.locator('[data-priorities-overview]').click();
        await page.locator(`[data-priority-open="${id}"]`).click();
        await page.locator(target).first().waitFor();
        await page.waitForFunction(()=>!document.querySelector('[data-priorities-highlight]').hidden);
        assert.equal(await page.locator('[data-priorities-tip-title]').innerText(),await bar.locator('[data-priorities-current]').innerText());
    }
    await page.locator('[data-priorities-toggle]').click();
    await page.reload();
    await bar.waitFor();
    assert.equal(await bar.locator('[data-priorities-current]').innerText(),'Check a fund reference','Reload restores the current step');
    assert.equal(await page.locator('[data-priorities-toggle]').innerText(),'Resume tour','Reload retains free exploration');
    for (const [label,width,height] of [['desktop',1440,1000],['ipad-portrait',820,1180],['ipad-landscape',1180,820],['phone',390,844]]) {
        await page.setViewportSize({width,height});
        assert.equal(await bar.evaluate(e=>e.scrollWidth<=e.clientWidth+1),true,label+' controls overflow');
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,label+' page overflow');
        await page.screenshot({path:resolve(evidence,'priorities-controls-'+label+'.png'),fullPage:false});
    }
    await page.locator('[data-priorities-toggle]').click();
    await page.locator('[data-priorities-continue]').click();
    assert.equal(await page.locator('[data-priority-card="buildings"]').getAttribute('aria-current'),'step','Continue advances from funds to the Buildings step');
    await page.keyboard.press('Escape');
    await page.locator('[data-priorities-continue]').click();
    assert.equal(await page.locator('[data-priority-card="visibility"]').getAttribute('aria-current'),'step','Continue from the final Buildings step wraps to the first priority');
    await page.keyboard.press('Escape');
    await page.locator('[data-priorities-end]').click();
    assert.equal(await bar.isVisible(),false);
    await opener.click();
    await page.locator('[data-priority-open="visibility"]').click();
    await page.locator('#request-list [data-open]').first().waitFor();
    await page.locator('[data-priorities-toggle]').click();
    await page.locator('#request-list [data-open]').first().click();
    assert.equal(await page.locator('#discussion-body').inputValue(),'Synthetic unsent project note retained while exploring priorities.','Navigation/reload retains unsent work');
    const workflow=page.getByRole('link',{name:'Open workflow workspace',exact:true});
    await workflow.click();
    await page.waitForURL('**/platform/demos/lees-mcrae/workflows**');
    await page.getByRole('button',{name:'Save draft',exact:true}).waitFor();
    await page.locator('[data-priorities-bar]').waitFor();
    assert.equal(await page.locator('[data-priorities-toggle]').innerText(),'Resume tour','Full-screen builder retains hidden/saved step');
    await page.reload();
    await page.getByRole('button',{name:'Save draft',exact:true}).waitFor();
    await page.locator('[data-priorities-overview]').click();
    await page.locator('[data-priority-open="visibility"]').click();
    await page.waitForURL(url=>url.pathname==='/platform/demos/lees-mcrae');
    assert.equal(await page.locator('#work-drawer').evaluate(el=>el.open),false,'Tour returns to the table');
    await page.locator(`#request-list [data-open="${chosenProject}"]`).click();
    assert.equal(await page.locator('#discussion-body').inputValue(),'Synthetic unsent project note retained while exploring priorities.','Builder return retains the exact project draft');
    await page.locator('#close-work-detail').click();
    await page.locator('[data-priorities-continue]').click();
    await page.keyboard.press('Escape');
    const forwardTarget=page.url();let historySteps=0;
    do {await page.goBack();historySteps++;} while(new URL(page.url()).pathname!=='/platform/demos/lees-mcrae/workflows'&&historySteps<4);
    assert.equal(new URL(page.url()).pathname,'/platform/demos/lees-mcrae/workflows','Back crosses explicit drawer selection entries to the workflow canvas');
    await page.locator('[data-priorities-bar]').waitFor();
    assert.equal(await page.locator('[data-priorities-bar]').count(),1,'History back restores one priorities controller');
    assert.equal(await page.locator('[data-priorities-current]').innerText(),'Review daily work','History back uses the latest saved step');
    for(let index=0;index<historySteps;index++)await page.goForward();
    await page.waitForURL(forwardTarget);
    assert.equal(page.url(),forwardTarget,'Forward restores the same closed-table URL');
    await page.locator('#request-list [data-open]').first().waitFor();
    assert.equal(await page.locator('[data-priorities-current]').innerText(),'Review daily work','History forward keeps the latest saved step');
    await page.locator(`#request-list [data-open="${chosenProject}"]`).click();
    assert.equal(await page.locator('#discussion-body').inputValue(),'Synthetic unsent project note retained while exploring priorities.','History forward preserves unsent work');
    await page.locator('#close-work-detail').click();
    for(let replay=0;replay<3;replay++) {
        await page.locator('[data-priorities-overview]').click();
        await page.keyboard.press('Escape');
    }
    assert.deepEqual(await page.evaluate(()=>sampleCsp),[],'Actual public CSP is preserved');
    assert.deepEqual(errors,[]);
    assert.equal(requests.filter(r=>!r.url.startsWith(origin)&&!r.url.startsWith("data:")).length,0);
    assert.equal(requests.filter(r=>r.method!=='GET').length,0,'Tour exploration never dispatches a server mutation');
    await writeFile(resolve(evidence,"verification.txt"),JSON.stringify({engine,render:"actual isolated HTTP kernel and shared Enterprise UI",cards:8,viewports:4,errors,externalRequests:0,sql:0,live:false},null,2));
    console.log("PASS priorities: actual HTTP overview, seven current-screen destinations, anchored highlights, free exploration/hide/resume, selected/unsent draft retention, reload and full-screen workflow return, keyboard/focus, desktop/phone/iPad controls, zero SQL/provider/CSP/JS errors. Viewport simulation, not physical iPad acceptance.");
} catch (error) {
    if (page) {
        await page.screenshot({path:resolve(evidence,'failure.png'),fullPage:true}).catch(()=>{});
        await writeFile(resolve(evidence,'failure.json'),JSON.stringify({error:String(error),errors,requests,csp:await page.evaluate(()=>sampleCsp).catch(()=>[])},null,2)).catch(()=>{});
    }
    throw error;
} finally {
    if (browser) await browser.close();
    server.kill();
    await exited.catch(()=>{});
}
