// UI acceptance check against the production loopback server with isolated generated media.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { spawn } = require("node:child_process");
const { chromium } = require("playwright");
const delay = (ms) => new Promise((r) => setTimeout(r, ms));
async function stop(child) {
    if (child.exitCode !== null) return;
    child.stdin.end("\n");
    for (let i = 0; i < 60 && child.exitCode === null; i++) await delay(100);
    if (child.exitCode === null) child.kill();
}
async function backend(empty = false) {
    const py = process.env.STUDIO_TEST_PYTHON || "py";
    const args = [
        ...(py === "py" ? ["-3"] : []),
        "-B",
        path.join(__dirname, "test_editor_fixture.py"),
        ...(empty ? ["--empty"] : []),
    ];
    const child = spawn(py, args, { cwd: __dirname, stdio: ["pipe", "pipe", "pipe"], windowsHide: true });
    let out = "",
        err = "";
    child.stdout.on("data", (s) => (out += s));
    child.stderr.on("data", (s) => (err += s));
    for (let i = 0; i < 300; i++) {
        const match = out.match(/http:\/\/127\.0\.0\.1:\d+\/launch\?key=[\w-]+/);
        if (match) return { child, url: match[0] };
        if (child.exitCode !== null) throw Error(err);
        await delay(100);
    }
    child.kill();
    throw Error("Fixture server did not start. " + err);
}
(async () => {
    const server = await backend();
    let browser;
    try {
        browser = await chromium.launch({
            channel: process.env.STUDIO_TEST_BROWSER || "chrome",
            args: ["--autoplay-policy=no-user-gesture-required"],
        });
        const page = await browser.newPage({
            viewport: { width: 1440, height: 900 },
            deviceScaleFactor: 1,
        });
        const errors = [];
        page.on("pageerror", (e) => errors.push(e.message));
        await page.goto(server.url);
        await page.getByText("The maker’s workshop", { exact: true }).first().waitFor();
        await page.waitForFunction(() => document.querySelector("#video").readyState >= 2);
        const live = () => page.evaluate(async () => await (await fetch("/api/state")).json());
        const seq = async (count) =>
            page.waitForFunction((n) => document.querySelectorAll("[data-segment]").length === n, count);
        await seq(3);
        // The camera files contain no sound. Prove both independent voice files reach their
        // separate Web Audio gain nodes, and a track mute leaves the other voice playing.
        assert.ok((await live()).project.sources.filter((s) => s.kind === "camera").every((s) => !s.audio));
        await page.locator("#play").click();
        await page.waitForFunction(() => {
            const voices = state.project.sources.filter((s) => s.kind === "voice");
            return (
                voices.length === 2 &&
                voices.every((s) => {
                    const node = audioNodes.get(s.id);
                    if (!node?.an || node.el.paused) return false;
                    const data = new Float32Array(node.an.fftSize);
                    node.an.getFloatTimeDomainData(data);
                    return data.some((x) => Math.abs(x) > 0.004);
                })
            );
        });
        await page.locator(".block.audio").first().click({ button: "right" });
        await page.getByRole("menuitem", { name: "Mute track", exact: true }).click();
        await page.waitForFunction(() => {
            const voices = state.project.sources.filter((s) => s.kind === "voice");
            return (
                !pending &&
                voices[0].muted &&
                audioNodes.get(voices[0].id).el.paused &&
                !audioNodes.get(voices[1].id).el.paused
            );
        });
        await page.locator("#play").click();
        await page.locator("#undo").click();
        await page.waitForFunction(
            () => !state.project.sources.find((s) => s.kind === "voice").muted && !pending,
        );
        // Drag the playhead inside the first clip, then split with a real context menu.
        const ruler = await page.locator("#ruler").boundingBox();
        await page.mouse.move(ruler.x + ruler.width * 0.05, ruler.y + 8);
        await page.mouse.down();
        await page.mouse.move(ruler.x + ruler.width * 0.16, ruler.y + 8, { steps: 8 });
        await page.mouse.up();
        await page.locator("[data-segment='0']").click({ button: "right", position: { x: 15, y: 13 } });
        await page.getByRole("menuitem", { name: "Split at playhead" }).click();
        await seq(4);
        assert.equal((await live()).project.sequence.length, 4);
        await page.locator("#undo").click();
        await seq(3);
        // Context menu also dismisses and restores keyboard focus.
        await page.locator("[data-segment='1']").focus();
        await page.keyboard.press("Shift+F10");
        await page.getByRole("menuitem", { name: "Duplicate segment" }).waitFor();
        await page.keyboard.press("Escape");
        assert.equal(await page.locator("#menu").isVisible(), false);
        await page.locator("[data-segment='1']").click({ button: "right" });
        await page.getByRole("menuitem", { name: "Duplicate segment" }).click();
        await seq(4);
        await page.keyboard.press("Control+z");
        await seq(3);
        const audio = page.locator(".block.audio").first();
        await audio.click({ button: "right" });
        await page.getByRole("menuitem", { name: "Mute track", exact: true }).click();
        await page.waitForFunction(async () => {
            const s = await (await fetch("/api/state")).json();
            return s.project.sources.some((t) => t.muted);
        });
        assert.equal((await live()).project.sources.find((t) => t.kind === "voice").muted, true);
        await page.waitForFunction(() => !pending);
        await page.getByRole("button", { name: "Undo", exact: true }).click();
        await page.waitForFunction(
            () => !state.project.sources.find((t) => t.kind === "voice").muted && !pending,
        );
        await page.locator("#gap").click();
        await page.getByRole("button", { name: "Analyze gaps" }).click();
        await page.locator("#gapResults input[type=checkbox]").first().waitFor({ timeout: 30000 });
        await page.locator("[data-audition]").first().click();
        await delay(250);
        const gaps = (await live()).gaps;
        assert.ok(gaps.length);
        await page.getByRole("button", { name: "Remove selected" }).click();
        await page.waitForFunction(() => !document.querySelector("#modal").open);
        const trimmed = (await live()).project.sequence.reduce((n, s) => n + s.end - s.start, 0);
        assert.ok(trimmed < 14);
        await page.locator("#undo").click();
        await seq(3);
        // A trim handle updates the actual stored source coordinates.
        const handle = page.locator("[data-segment='0'] .out-handle");
        const box = await handle.boundingBox();
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(box.x - 26, box.y + 4, { steps: 6 });
        await page.mouse.up();
        await page.waitForFunction(async () => {
            const s = await (await fetch("/api/state")).json();
            return s.project.sequence[0].end < 5.1;
        });
        assert.ok((await live()).project.sequence[0].end < 5.1);
        await page.locator("#undo").click();
        // Capture a representative sequence frame and inspected source segment.
        await page.locator("[data-segment='0']").click({ position: { x: 25, y: 14 } });
        await page.locator("#ruler").click({ position: { x: ruler.width * 0.13, y: 8 } });
        await page.waitForFunction(
            () =>
                document.querySelector("#video").readyState >= 2 &&
                !document.querySelector("#video").seeking,
        );
        await page.locator(".wave path").first().waitFor();
        await page.mouse.move(4, 4);
        await page.waitForFunction(() => !document.querySelector("#toasts .notification"));
        if (process.env.STUDIO_UI_SCREENSHOT) {
            await page.screenshot({ path: process.env.STUDIO_UI_SCREENSHOT, animations: "disabled" });
        }
        await page.setViewportSize({ width: 800, height: 600 });
        await delay(150);
        if (process.env.STUDIO_UI_SMALL_SCREENSHOT)
            await page.screenshot({ path: process.env.STUDIO_UI_SMALL_SCREENSHOT, animations: "disabled" });
        assert.ok((await page.locator("#play").isVisible()) && (await page.locator("#gap").isVisible()));
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.setViewportSize({ width: 680, height: 540 });
        await page.getByRole("button", { name: "Mix", exact: true }).click();
        assert.ok(await page.locator("#inspectorBody").isVisible());
        await page.keyboard.press("Escape");
        await page.emulateMedia({ reducedMotion: "reduce" });
        assert.deepEqual(errors, []);
        console.log(
            "Real editor browser: project playback, source trims, right-click, keyboard, split, duplicate, undo, mute, silence review/removal, real waveform, responsive viewport and reduced motion passed.",
        );
        const empty = await backend(true);
        try {
            const home = await browser.newPage({ viewport: { width: 1440, height: 900 } });
            await home.goto(empty.url);
            await home.locator("#welcomeNew").waitFor();
            const calls = [];
            home.on("request", (req) => {
                if (req.url().endsWith("/api/command")) calls.push(req.postDataJSON().action);
            });
            await home.getByRole("button", { name: "Import media", exact: true }).first().click();
            await home.locator("#newTitle").fill("Private onboarding check");
            await home.getByRole("button", { name: "Create episode" }).click();
            await home.waitForFunction(
                () => document.getElementById("projectTitle").textContent === "Private onboarding check",
            );
            for (let i = 0; i < 30 && !calls.includes("import_media"); i++) await delay(100);
            assert.deepEqual(calls.slice(0, 2), ["new_project", "import_media"]);
            await home.close();
        } finally {
            await stop(empty.child);
        }
    } finally {
        if (browser) await browser.close();
        await stop(server.child);
    }
})().catch((e) => {
    console.error(e.message);
    process.exitCode = 1;
});
