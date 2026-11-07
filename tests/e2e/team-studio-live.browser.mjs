import { mkdtemp, writeFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { spawn, execFile } from "node:child_process";
import { promisify } from "node:util";
import { chromium } from "playwright";
import assert from "node:assert/strict";
import { createServer } from "node:net";
const exec = promisify(execFile);
const base = resolve();
const probe = createServer();
await new Promise((done) => probe.listen(0, "127.0.0.1", done));
const port = probe.address().port;
await new Promise((done) => probe.close(done));
const dir = await mkdtemp(join(tmpdir(), "codex-teams-studio-"));
await writeFile(join(dir, "db.sqlite"), "");
const env = {
    ...process.env,
    APP_ENV: "testing",
    APP_URL: `http://127.0.0.1:${port}`,
    APP_KEY: "base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=",
    APP_CONFIG_CACHE: join(dir, "config.php"),
    DB_CONNECTION: "sqlite",
    DB_DATABASE: join(dir, "db.sqlite"),
    DB_URL: "",
    DATABASE_URL: "",
    CACHE_STORE: "array",
    SESSION_DRIVER: "database",
    SESSION_SECURE_COOKIE: "false",
    SESSION_DOMAIN: "",
    MAIL_MAILER: "array",
    QUEUE_CONNECTION: "sync",
    EVERBRANCH_AI_STANDARD_ENABLED: "false",
    TEAM_WORK_ENABLED: "true",
    TEAM_WORK_PREMIUM_ENABLED: "true",
    TEAM_OPERATIONS_ENABLED: "true",
    BCRYPT_ROUNDS: "4",
    NIGHTWATCH_ENABLED: "false",
    TELESCOPE_ENABLED: "false",
    PULSE_ENABLED: "false",
};
let server, browser, page;
try {
    await exec("php", ["artisan", "migrate", "--force", "--no-interaction"], {
        cwd: base,
        env,
        maxBuffer: 2e6,
    });
    const fixture = JSON.parse(
        (
            await exec(
                "php",
                [resolve("scripts/everbranch-team/studio-browser-fixture.php")],
                { cwd: base, env },
            )
        ).stdout,
    );
    env.TEAM_WORK_TENANT_ALLOWLIST = fixture.tenant_id;
    server = spawn(
        "php",
        [
            "artisan",
            "serve",
            "--host=127.0.0.1",
            `--port=${port}`,
            "--no-reload",
        ],
        { cwd: base, env, stdio: "ignore" },
    );
    const origin = env.APP_URL;
    for (let i = 0; i < 100; i++) {
        try {
            await fetch(origin + "/up");
            break;
        } catch {
            await new Promise((done) => setTimeout(done, 100));
        }
    }
    browser = await chromium.launch();
    const context = await browser.newContext();
    await context.route("**/*", (route) =>
        new URL(route.request().url()).hostname === "127.0.0.1"
            ? route.continue()
            : route.abort(),
    );
    const login = await context.request.get(origin + "/login");
    const html = await login.text();
    const csrf = html.match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];
    assert.ok(csrf, "Login CSRF token");
    const signed = await context.request.post(origin + "/login", {
        form: {
            email: "owner@fixture.invalid",
            password: "Fictional-local-only-2026!",
            _token: csrf,
        },
        headers: { Accept: "application/json" },
    });
    assert.ok(
        signed.ok(),
        `Login status ${signed.status()}: ${await signed.text()}`,
    );
    page = await context.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await page.goto(origin + "/teams/studio?tenant=" + fixture.tenant);
    await page
        .getByRole("heading", { name: "Your team. Your next event." })
        .waitFor();
    await page.getByRole("button", { name: "＋ Create event" }).click();
    await page
        .getByLabel("Event name", { exact: true })
        .fill("Fictional HTTP browser event");
    await page.getByRole("button", { name: "2. Schedule & venue" }).click();
    await page.getByLabel("First show date").fill("2026-11-06");
    await page.getByLabel("Last show date").fill("2026-11-08");
    await page.getByLabel("Venue timezone").fill("America/New_York");
    await page.getByRole("button", { name: "4. Budget", exact: true }).click();
    await page.getByRole("button", { name: "Add fee", exact: true }).click();
    await page.getByLabel("Fee", { exact: true }).fill("Fictional venue");
    await page.getByLabel("Total fee", { exact: true }).fill("100");
    await page.getByRole("button", { name: "5. Review & create" }).click();
    await page
        .getByRole("button", { name: "Calculate authoritative budget" })
        .click();
    await page.getByText("$100.00", { exact: true }).first().waitFor();
    await page
        .getByLabel(
            "I reviewed the assumptions and missing inputs. Freeze this budget revision.",
        )
        .check();
    let interrupted = false;
    await page.route("**/team/studio/create?*", async (route) => {
        if (!interrupted) {
            interrupted = true;
            const response = await route.fetch();
            assert.ok(response.ok(), await response.text());
            await route.abort("failed");
        } else await route.continue();
    });
    await page
        .getByRole("button", { name: "Create event", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Recover original creation" })
        .click();
    await page.getByRole("tab", { name: "overview", exact: true }).waitFor();
    const records = await context.request.get(
        `${origin}/team/operations/records?tenant=${fixture.tenant}&area=${fixture.area_id}&kind=event`,
        { headers: { Accept: "application/json" } },
    );
    assert.equal((await records.json()).items.length, 1);
    await page
        .getByRole("navigation", { name: "Teams navigation" })
        .getByRole("button", { name: "My Work", exact: true })
        .click();
    await page
        .getByLabel("New task", { exact: true })
        .fill("Fictional HTTP native task");
    await page
        .getByRole("button", { name: "Create task", exact: true })
        .click();
    await page
        .getByRole("button")
        .filter({ hasText: "Fictional HTTP native task" })
        .click();
    await page
        .getByLabel("New comment", { exact: true })
        .fill("Fictional canonical comment");
    await page
        .getByRole("button", { name: "Post comment", exact: true })
        .click();
    await page
        .locator("pre")
        .filter({ hasText: "Fictional canonical comment" })
        .waitFor();
    const fence = await context.request.get(
        `${origin}/team/studio?tenant=${fixture.tenant}&area=${fixture.area_id}`,
        {
            headers: {
                Accept: "application/json",
                "X-Team-Viewer": "99999999",
            },
        },
    );
    assert.equal(fence.status(), 403);
    assert.deepEqual(errors, []);
    console.log(
        JSON.stringify(
            {
                transport: "real Laravel HTTP and private SQLite",
                checks: [
                    "Fortify login and authenticated boot",
                    "server cost preview and wizard creation",
                    "lost creation response creates one canonical event",
                    "native task and comment persist through shared commands",
                    "stale viewer fence refuses access",
                    "no runtime errors",
                ],
                provider_writes: false,
            },
            null,
            2,
        ),
    );
} catch (error) {
    if (page)
        console.error(
            "PAGE",
            (await page.locator("main").innerText()).slice(0, 2000),
        );
    throw error;
} finally {
    if (browser) await browser.close();
    if (server && server.exitCode === null) {
        server.kill("SIGTERM");
        await new Promise((done) => server.once("exit", done));
    }
    await rm(dir, { recursive: true, force: true });
}
