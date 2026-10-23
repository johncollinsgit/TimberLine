import assert from "node:assert/strict";
import {checkVoiceShell} from './enterprise-voice-shell-browser.mjs';
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
const origin = "http://127.0.0.1:8891",
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
        "127.0.0.1:8891",
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
    await page.locator('#reviewer-perspective').selectOption('manager');
    await page.waitForFunction(()=>document.getElementById('reviewer-perspective')?.value==='manager');
    await checkVoiceShell(page,evidence,{sample:true});
    await page.locator('#home-nav').click();
    await page.getByText("Fictional sample controls", { exact: false }).count();
    await page.locator("#briefing-body .briefing-hero").waitFor();
    await page
        .getByRole("button", { name: "Tour", exact: true })
        .click();
    await page
        .getByRole("button", { name: "07 Ask Everbranch", exact: false })
        .click();
    assert.equal(
        await page
            .getByRole("button", {
                name: "Controlled trial connection pending",
                exact: true,
            })
            .isDisabled(),
        true,
    );
    await page
        .locator("[data-tour-trial-status]")
        .waitFor({ state: "visible" });
    const capturesBeforeFinale=await page.evaluate(()=>shellCaptures.length);
    await page.screenshot({path:resolve(evidence,'ask-finale-desktop.png'),fullPage:true});
    await page.getByRole('button',{name:'Open Ask Everbranch',exact:true}).click();
    await page.locator('#ask-voice-controls').getByRole('button',{name:'Talk',exact:true}).waitFor();
    assert.equal(await page.evaluate(()=>shellCaptures.length),capturesBeforeFinale,'Tour navigation never starts a microphone');
    assert.equal(await page.locator('#enterprise-main').getAttribute('data-demo-page'),'enterprise-ask');
    await page.getByRole('button',{name:'Tour',exact:true}).click();
    await page.getByRole("button", { name: "Restart", exact: true }).click();
    await page.keyboard.press("ArrowRight");
    await page
        .getByRole("button", { name: "Preview the sample email", exact: true })
        .click();
    await page
        .getByRole("heading", { name: "Email preview · not sent", exact: true })
        .waitFor();
    await page
        .getByRole("button", { name: "View sample request", exact: true })
        .click();
    await page
        .getByRole("heading", {
            name: "Event audio needs a check · sample",
            exact: true,
        })
        .waitFor();
    await page.locator('#close-work-detail').click();
    await page
        .getByRole("button", { name: "Operations & projects", exact: true })
        .click();
    await page
        .getByRole("button", {
            name: "Campus event readiness · sample",
            exact: true,
        })
        .click();
    await page
        .getByRole("link", { name: "Open workflow workspace", exact: true })
        .click();
    await page.locator("#enterprise-workflow .ew-shell").waitFor();
    assert.ok(
        !new URL(page.url()).search,
        "Public workflow navigation must not use query parameters",
    );
    await page
        .getByRole("heading", {
            name: "From a request to a clear next step.",
            exact: true,
        })
        .waitFor();
    await page
        .getByRole("button", { name: /Illustrative maintenance workflow/ })
        .click();
    await page.getByRole("button", { name: "Save draft", exact: true }).click();
    await page.getByText("SAMPLE DATA", { exact: false }).first().waitFor();
    await page.getByRole("button", { name: /Settings$/ }).click();
    await page
        .getByRole("button", { name: "Review activation preview", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Confirm activation", exact: true })
        .click();
    await page.getByRole("button", { name: /Runs & history$/ }).click();
    await page
        .getByRole("button", { name: "Start a run", exact: true })
        .click();
    await page
        .getByLabel("Work description *", { exact: true })
        .fill("Fictional event audio check");
    await page.getByLabel("Priority *", { exact: true }).selectOption("Urgent");
    await page
        .getByRole("button", { name: "Submit & continue", exact: true })
        .click();
    await page
        .getByRole("region", { name: "Linked native task", exact: true })
        .waitFor();
    const selectedWorkflowUrl = page.url();
    await page
        .getByLabel("Sample perspective", { exact: true })
        .selectOption("worker");
    await page.getByRole("region", { name: "Linked native task", exact: true }).waitFor();
    assert.equal(page.url(), selectedWorkflowUrl, "Perspective switch retains workflow and run");
    await page
        .getByLabel("Complete sound check (native task)", { exact: true })
        .check();
    await page.getByText("3.9 / 4.0", { exact: false }).first().waitFor();
    await page
        .getByLabel("Completion note *", { exact: true })
        .fill("Sample sound check complete; awaiting manager verification");
    await page
        .getByRole("button", { name: "Submit & continue", exact: true })
        .click();
    await page
        .getByLabel("Sample perspective", { exact: true })
        .selectOption("manager");
    await page.getByRole("button", { name: "Approve", exact: true }).click();
    await page
        .locator(".ew-run-layout .ew-badge")
        .filter({ hasText: "completed" })
        .waitFor();
    assert.equal(
        await page.evaluate(
            () =>
                EverbranchSampleTransport.create(
                    JSON.parse(
                        document.getElementById("enterprise-workflow").dataset
                            .bootstrap,
                    ),
                )
                    .snapshot()
                    .tasks.find(
                        (task) =>
                            task.id === EverbranchSampleTransport.ids.task,
                    ).approved,
        ),
        true,
        "Manager workflow approval must verify the same sample task",
    );
    await page.screenshot({
        path: resolve(evidence, "shared-workflow-desktop.png"),
        fullPage: true,
        caret: "initial",
    });
    await page.getByRole("link", { name: /Back to Projects/ }).click();
    await page.locator("#areas button").first().waitFor();
    await page
        .getByRole("heading", {
            name: "Campus event readiness · sample",
            exact: true,
        })
        .waitFor();
    await page
        .getByLabel("Add a note", { exact: true })
        .fill("Sample browser note <img src=x onerror=alert(1)>");
    await page.getByRole("button", { name: "Post note", exact: true }).click();
    await page
        .locator("#discussion-feed")
        .getByText("Sample browser note <img src=x onerror=alert(1)>", {
            exact: true,
        })
        .waitFor();
    assert.equal(
        await page.locator("#discussion-feed img").count(),
        0,
        "Sample note became executable markup",
    );
    await page
        .getByLabel("Attach photos, PDF or text", { exact: true })
        .setInputFiles({
            name: "sample-note.txt",
            mimeType: "text/plain",
            buffer: Buffer.from("Fictional browser-only sample attachment"),
        });
    await page
        .locator("#discussion-files")
        .getByText("sample-note.txt", { exact: false })
        .waitFor();
    assert.ok(
        await page
            .locator("#discussion-files")
            .innerText()
            .then((text) => text.includes("quarantined")),
    );
    const captionForm = page.getByRole("form", {
        name: "Caption for sample-note.txt",
        exact: true,
    });
    await captionForm
        .getByLabel("File caption (metadata only)", { exact: true })
        .fill("Fictional evidence <b>literal caption</b>");
    await captionForm
        .getByRole("button", { name: "Save caption", exact: true })
        .click();
    await page
        .locator("#discussion-files")
        .getByText("Caption: Fictional evidence <b>literal caption</b>", {
            exact: true,
        })
        .waitFor();
    assert.equal(
        await page.locator("#discussion-files b").count(),
        0,
        "Caption became executable markup",
    );
    if(await page.locator('#work-drawer').evaluate(el=>el.open))await page.locator('#close-work-detail').click();
    await page
        .getByLabel("Sample perspective", { exact: true })
        .selectOption("requester");
    await page
        .locator("#requester-portal")
        .getByRole("heading", { name: "My service requests", exact: true })
        .waitFor();
    await page
        .getByLabel("Request title", { exact: true })
        .fill("Reviewer browser service request");
    await page
        .getByLabel("What do you need?", { exact: true })
        .fill("Fictional request for an equipment check.");
    await page.locator("#requester-category").selectOption("Maintenance");
    await page
        .getByRole("button", { name: "Submit service request", exact: true })
        .click();
    await page
        .locator("#requester-detail")
        .getByRole("heading", {
            name: "Reviewer browser service request",
            exact: true,
        })
        .waitFor();
    await page
        .getByLabel("Sample perspective", { exact: true })
        .selectOption("manager");
    await page
        .getByRole("button", {
            name: "Reviewer browser service request",
            exact: true,
        })
        .click();
    await page
        .getByRole("button", { name: "Triage request", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Assign task", exact: true })
        .click();
    await page
        .getByLabel("Requester-visible update", { exact: true })
        .fill("The sample Operations team has assigned your request.");
    await page
        .locator("#request-operations input[name=confirmed_public]")
        .check();
    await page
        .getByRole("button", {
            name: "Publish in-app request update",
            exact: true,
        })
        .click();
    if(await page.locator('#work-drawer').evaluate(el=>el.open))await page.locator('#close-work-detail').click();
    await page
        .getByLabel("Sample perspective", { exact: true })
        .selectOption("requester");
    await page
        .locator("#requester-list")
        .getByRole("button", {
            name: "Reviewer browser service request",
            exact: true,
        })
        .click();
    await page
        .locator("#requester-detail")
        .getByText("The sample Operations team has assigned your request.", {
            exact: true,
        })
        .waitFor();
    assert.ok(
        !(await page.locator("#requester-detail").innerText()).includes(
            "Internal sample notes",
        ),
    );
    await page
        .getByLabel("Sample perspective", { exact: true })
        .selectOption("manager");
    await page.getByRole("button", { name: "Buildings", exact: true }).click();
    await page.locator("#res-list .resource-card").last().waitFor();
    assert.equal(
        await page.locator("#res-list .resource-card").count(),
        68,
        "Official source catalog must contain all 68 locations",
    );
    await page.locator("#res-query").fill("Bryan Gym");
    await page
        .locator("#res-list")
        .getByRole("button", { name: /Bryan Gym/ })
        .click();
    await page.locator('[data-building-details]').click();
    await page
        .locator("#res-detail")
        .getByRole("heading", { name: "Bryan Gym", exact: true })
        .waitFor();
    assert.match(await page.locator("#res-detail").innerText(), /Unverified/);
    await page.getByRole("button", { name: "Assets", exact: true }).click();
    await page.locator("#res-query").fill("");
    await page.locator("#res-list .resource-card").first().click();
    await page
        .getByRole("button", { name: "Return asset", exact: true })
        .click();
    await page
        .locator("#res-form-condition")
        .fill("Fictional reviewer return check");
    await page
        .getByRole("button", { name: "Save exact action", exact: true })
        .click();
    await page.locator("#res-dialog").waitFor({ state: "hidden" });
    await page
        .getByRole("button", { name: "Check out asset", exact: true })
        .waitFor();
    await page.getByRole("button", { name: "Calendar", exact: true }).click();
    for (const [title, start, end] of [
        ["Sample sound check", "2026-10-23T10:00", "2026-10-23T11:00"],
        ["Sample room maintenance", "2026-10-23T10:30", "2026-10-23T11:30"],
    ]) {
        await page
            .getByRole("button", { name: "New reservation", exact: true })
            .click();
        await page.locator("#res-form-title").fill(title);
        await page
            .locator("#res-form-resource")
            .selectOption("70000000-0000-4000-8000-000000000001");
        await page.locator("#res-form-start").fill(start);
        await page.locator("#res-form-end").fill(end);
        await page
            .getByRole("button", { name: "Save exact action", exact: true })
            .click();
        await page.locator("#res-dialog").waitFor({ state: "hidden" });
    }
    assert.match(
        await page.locator("#res-counts").innerText(),
        /1 open conflicts/,
    );
    await page.getByRole("button", { name: "Roll Call", exact: true }).click();
    await page.locator("#roll-meeting-form").waitFor({ state: "visible" });
    await page.locator("#roll-meeting-title").fill("Reviewer browser meeting");
    await page
        .getByRole("button", { name: "Add decision", exact: true })
        .click();
    await page
        .getByLabel("Decision 1", { exact: true })
        .fill("Keep the sample audio booking pending conflict review.");
    await page
        .getByRole("button", { name: "Add commitment", exact: true })
        .click();
    await page
        .getByLabel("Commitment 1", { exact: true })
        .fill("Review the fictional booking conflict");
    await page
        .getByLabel("Commitment 1 owner", { exact: true })
        .selectOption("2");
    await page
        .getByLabel("Commitment 1 due date", { exact: true })
        .fill("2026-10-23");
    await page
        .getByLabel("Commitment 1 project", { exact: true })
        .selectOption("20000000-0000-4000-8000-000000000001");
    await page
        .getByRole("button", { name: "Save meeting notes", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Review task changes", exact: true })
        .click();
    await page.locator("#roll-preview-panel").waitFor({ state: "visible" });
    await page.locator("#roll-confirm-checkbox").check();
    await page
        .getByRole("button", {
            name: "Confirm these task changes",
            exact: true,
        })
        .click();
    await page
        .getByText(
            "Confirmed and immutable. Add another commitment to propose a further change.",
            { exact: true },
        )
        .waitFor();
    const downloaded = page.waitForEvent("download");
    await page
        .getByRole("button", {
            name: "Download fresh one-page PDF",
            exact: true,
        })
        .click();
    const pdf = await downloaded;
    assert.equal(await pdf.failure(), null);
    await pdf.saveAs(resolve(evidence, "sample-meeting-roll-call.pdf"));
    assert.deepEqual(
        await page.evaluate(() => sampleCspDetails),
        [],
        "CSP violations before viewport screenshots",
    );
    for (const [label, width, height] of [
        ["desktop", 1440, 1000],
        ["ipad-portrait", 820, 1180],
        ["ipad-landscape", 1180, 820],
        ["phone", 390, 844],
    ]) {
        await page.setViewportSize({ width, height });
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
            `${label} public sample overflow`,
        );
    }
    assert.deepEqual(await page.evaluate(() => sampleCspDetails), []);
    assert.deepEqual(errors, []);
    assert.ok(
        requests.every(
            (item) =>
                item.method === "GET" &&
                (item.url.startsWith(url) || item.url.startsWith("blob:")),
        ),
        "Sample made an API/provider request",
    );
    // Evidence capture follows the CSP gate. Playwright's WebKit screenshotter
    // injects a nonce-less `body {}` style to synchronize animations; that test
    // runner operation itself raises CSP events. Never weaken the app policy.
    for (const [label, width, height] of [
        ["desktop", 1440, 1000],
        ["ipad-portrait", 820, 1180],
        ["ipad-landscape", 1180, 820],
        ["phone", 390, 844],
    ]) {
        await page.setViewportSize({ width, height });
        await page.screenshot({
            path: resolve(evidence, "shared-reviewer-" + label + ".png"),
            fullPage: true,
            caret: "initial",
        });
    }
    console.log(
        `PASS (${engine}): actual isolated HTTP kernel/controller/CSP renders shared Enterprise shell and workflow; same-task3.9/4.0, notes/quarantined upload, student request cycle, catalog68/asset/overlap and meeting preview/confirm/PDF; zero SQL/cookies/API/provider calls and CSP/JS errors before separate screenshots; desktop/phone/iPad viewport checks. Production retail middleware is verified separately by feature tests.`,
    );
} catch (error) {
    if (page) {
        await page
            .screenshot({
                path: resolve(evidence, "failure.png"),
                fullPage: true,
            })
            .catch(() => {});
        await writeFile(
            resolve(evidence, "failure.txt"),
            error.stack +
                "\n" +
                JSON.stringify({
                    errors,
                    requests,
                    csp: await page
                        .evaluate(() => globalThis.sampleCsp || [])
                        .catch(() => []),
                }) +
                "\n" +
                (await page
                    .locator("body")
                    .innerText()
                    .catch(() => "Unavailable")),
        ).catch(() => {});
    }
    throw error;
} finally {
    if (browser) await browser.close();
    server.kill();
    await exited.catch(() => {});
}
