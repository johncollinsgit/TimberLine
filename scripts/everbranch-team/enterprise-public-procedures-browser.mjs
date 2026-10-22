import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { once } from "node:events";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";
const php = process.env.ENTERPRISE_TEST_PHP;
assert.ok(php, "ENTERPRISE_TEST_PHP required");
const { chromium, webkit } = await import(
    pathToFileURL(
        resolve(
            process.env.EVERBRANCH_PLAYWRIGHT_MODULE ||
                "node_modules/playwright/index.mjs",
        ),
    ).href
);
const engine = process.env.ENTERPRISE_BROWSER_ENGINE || "chromium",
    port = Number(process.env.ENTERPRISE_PUBLIC_PROCEDURES_PORT || 8893),
    origin = `http://127.0.0.1:${port}`,
    url = origin + "/platform/demos/lees-mcrae";
assert.ok(["chromium", "webkit"].includes(engine));
const evidence = resolve(
    process.env.ENTERPRISE_EVIDENCE ||
        "../evidence/enterprise/public-procedures/" + engine,
);
await mkdir(evidence, { recursive: true });
const server = spawn(
    php,
    [
        "-S",
        `127.0.0.1:${port}`,
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
        windowsHide: true,
        stdio: ["ignore", "pipe", "pipe"],
    },
);
const exited = once(server, "exit");
let log = "",
    browser,
    page;
const errors = [],
    requests = [];
server.stdout.on("data", (b) => {
    log = (log + b).slice(-8000);
});
server.stderr.on("data", (b) => {
    log = (log + b).slice(-8000);
});
try {
    for (let i = 0; i < 40; i++) {
        try {
            const r = await fetch(url);
            if (r.status === 200) break;
        } catch {}
        if (i === 39) throw new Error("HTTP fixture unavailable: " + log);
        await new Promise((r) => setTimeout(r, 100));
    }
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
        acceptDownloads: true,
    });
    page.on("pageerror", (e) => errors.push(e.message));
    page.on("request", (r) =>
        requests.push({ method: r.method(), url: r.url() }),
    );
    await page.addInitScript(() => {
        globalThis.sampleCsp = [];
        globalThis.sampleCspDetails = [];
        document.addEventListener("securitypolicyviolation", (e) => {
            sampleCsp.push(e.violatedDirective + ":" + e.blockedURI);
            sampleCspDetails.push({
                directive: e.violatedDirective,
                line: e.lineNumber,
                column: e.columnNumber,
                source: e.sourceFile,
                sample: e.sample,
            });
        });
    });
    const response = await page.goto(url);
    assert.equal(response.status(), 200);
    assert.equal(response.headers()["x-reviewer-fixture-sql"], "0");
    assert.equal(response.headers()["set-cookie"], undefined);
    await page.locator("#briefing-body .briefing-hero").waitFor();
    await page.locator("#people-nav").click();
    await page.locator("#certificate-body").waitFor({ state: "visible" });
    await page.locator("#certificate-list [data-certificate]").first().click();
    await page
        .getByRole("form", {
            name: "Propose certificate replacement",
            exact: true,
        })
        .waitFor();
    let proposal = page.getByRole("form", {
        name: "Propose certificate replacement",
        exact: true,
    });
    await proposal
        .locator("[name=document_reference]")
        .fill("FICTIONAL replacement <img src=x onerror=alert(1)>");
    await proposal.locator("[name=expires_on]").fill("2027-10-25");
    await proposal
        .getByRole("button", { name: "Save replacement proposal", exact: true })
        .click();
    let review = page.getByRole("form", {
        name: "Review exact certificate replacement",
        exact: true,
    });
    await review.waitFor();
    assert.equal(await page.locator("#certificate-detail img").count(), 0);
    await review.locator("[name=decision]").selectOption("accept");
    await review
        .locator("[name=reason]")
        .fill("Fictional walkthrough; metadata only");
    await review.locator("[name=confirmed]").check();
    await review
        .getByRole("button", {
            name: "Confirm replacement review",
            exact: true,
        })
        .click();
    await page
        .getByRole("form", {
            name: "Propose certificate replacement",
            exact: true,
        })
        .waitFor();
    assert.match(
        await page.locator("#certificate-detail").innerText(),
        /2027-10-25/,
    );
    await page.locator("#roll-call-nav").click();
    await page.locator("#quarterly-period").waitFor({ state: "visible" });
    await page.locator("#quarterly-preview").click();
    await page
        .locator("#quarterly-preview-body")
        .getByRole("heading", { name: "How to read this report" })
        .waitFor();
    assert.match(
        await page.locator("#quarterly-preview-body").innerText(),
        /FICTIONAL BROWSER SAMPLE/,
    );
    const download = page.waitForEvent("download");
    await page.locator("#quarterly-json").click();
    const artifact = await download;
    await artifact.saveAs(resolve(evidence, "fictional-period.json"));
    await page.locator("#finance-nav").click();
    await page.locator("#finance-body").waitFor({ state: "visible" });
    await page.locator("[data-finance-record]").first().click();
    await page.locator("#finance-detail h3").waitFor();
    await page
        .getByRole("button", { name: "Edit packet draft", exact: true })
        .click();
    await page
        .locator("#finance-title")
        .fill("Retained fictional invoice draft");
    await page.locator("#people-nav").click();
    await page.locator("#finance-nav").click();
    await page.locator("#finance-form").waitFor({ state: "visible" });
    assert.equal(
        await page.locator("#finance-title").inputValue(),
        "Retained fictional invoice draft",
    );
    await page
        .locator("#finance-title")
        .fill("Fictional invoice <img src=x onerror=alert(1)>");
    await page
        .getByRole("button", { name: "Save packet draft", exact: true })
        .click();
    await page
        .getByRole("form", {
            name: "Submit exact packet for review",
            exact: true,
        })
        .waitFor();
    assert.equal(await page.locator("#finance-detail img").count(), 0);
    const transition = async (name) => {
        const f = page.getByRole("form", { name, exact: true });
        await f
            .locator("[name=reason]")
            .fill("Fictional procedural checkpoint");
        await f.locator("[name=confirmed]").check();
        await f.getByRole("button", { name, exact: true }).click();
    };
    await transition("Submit exact packet for review");
    await page
        .getByRole("form", {
            name: "Confirm internal packet review",
            exact: true,
        })
        .waitFor();
    review = page.getByRole("form", {
        name: "Confirm internal packet review",
        exact: true,
    });
    await review.locator("[name=decision]").selectOption("approved");
    await transition("Confirm internal packet review");
    await page
        .getByRole("form", {
            name: "Record existing Finance completion",
            exact: true,
        })
        .waitFor();
    const viewports = [
        ["desktop", 1440, 1000],
        ["ipad-portrait", 820, 1180],
        ["ipad-landscape", 1180, 820],
        ["phone", 390, 844],
    ];
    for (const [label, width, height] of viewports) {
        await page.setViewportSize({ width, height });
        assert.equal(
            await page.evaluate(
                () => document.documentElement.scrollWidth > innerWidth + 1,
            ),
            false,
            label + " horizontal overflow",
        );
        for (const id of ["finance-refresh", "finance-new"]) {
            const box = await page.locator("#" + id).boundingBox();
            assert.ok(box.height >= 44, label + " touch target");
        }
    }
    const completion = page.getByRole("form", {
        name: "Record existing Finance completion",
        exact: true,
    });
    await completion
        .locator("[name=external_reference]")
        .fill("FICTIONAL external Finance reference only");
    await transition("Record existing Finance completion");
    await page.waitForFunction(() =>
        document
            .querySelector("#finance-detail")
            ?.textContent.includes("externally_completed"),
    );
    await page.locator("#reviewer-perspective").selectOption("worker");
    await page.locator("#finance-nav").waitFor({ state: "hidden" });
    assert.equal(await page.locator("#finance-body").isVisible(), false);
    assert.equal(await page.locator("#finance-detail").innerText(), "");
    await page.locator("#reviewer-perspective").selectOption("requester");
    assert.equal(await page.locator("#finance-nav").isVisible(), false);
    assert.equal(await page.locator("#certificate-body").isVisible(), false);
    assert.deepEqual(errors, []);
    assert.deepEqual(await page.evaluate(() => sampleCsp), []);
    assert.equal(
        requests.filter(
            (r) =>
                new URL(r.url).origin !== origin ||
                r.method !== "GET" ||
                /\/(enterprise|team)\//.test(new URL(r.url).pathname),
        ).length,
        0,
        JSON.stringify(requests),
    );
    // Validate the complete interaction sequence before separate image capture:
    // Playwright/WebKit screenshots inject an inline reset stylesheet even with
    // caret='initial'. Keep that automation artifact outside application CSP checks.
    await page.locator("#reviewer-perspective").selectOption("manager");
    await page.locator("#finance-nav").click();
    await page.locator("[data-finance-record]").first().click();
    await page.locator("#finance-detail h3").waitFor();
    assert.deepEqual(errors, []);
    assert.deepEqual(await page.evaluate(() => sampleCsp), []);
    for (const [label, width, height] of viewports) {
        await page.setViewportSize({ width, height });
        await page.screenshot({
            path: resolve(evidence, "finance-" + label + ".png"),
            fullPage: true,
            caret: "initial",
        });
    }
    console.log(
        `PASS (${engine}): public shared report/certificate/invoice interfaces, fictional review lifecycles, labeled downloads, retained drafts, hostile text, manager perspective filtering, desktop/phone/iPad touch layouts; zero operational SQL/cookies/API/provider calls and CSP/JS errors before separate screenshots. Viewport simulation only.`,
    );
} catch (error) {
    if (page) {
        await page
            .screenshot({
                path: resolve(evidence, "failure.png"),
                fullPage: true,
                caret: "initial",
            })
            .catch(() => {});
        await writeFile(
            resolve(evidence, "failure.txt"),
            error.stack +
                "\n" +
                JSON.stringify({
                    errors,
                    requests,
                    csp: await page.evaluate(() => sampleCspDetails),
                }) +
                "\n" +
                (await page.locator("body").innerText()),
        ).catch(() => {});
    }
    throw error;
} finally {
    if (browser) await browser.close();
    server.kill();
    await exited.catch(() => {});
}
