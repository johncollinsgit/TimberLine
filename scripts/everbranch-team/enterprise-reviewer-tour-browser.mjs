import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

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
const browser = await (engine === "webkit" ? webkit : chromium).launch(
    engine === "chromium" && process.platform === "win32"
        ? {
              executablePath:
                  "C:/Program Files/Google/Chrome/Application/chrome.exe",
              headless: true,
          }
        : { headless: true },
);
const evidence = resolve(
    process.env.ENTERPRISE_EVIDENCE ||
        "../evidence/enterprise/reviewer-tour/" + engine,
);
await mkdir(evidence, { recursive: true });
const image = await readFile(
    "resources/js/enterprise/reviewer/assets/lees-mcrae-official-wordmark.png",
);
const markup = (
    await readFile("resources/views/enterprise/reviewer-tour.blade.php", "utf8")
)
    .replace(
        /\{\{ base64_encode\(file_get_contents\(resource_path\('js\/enterprise\/reviewer\/assets\/lees-mcrae-official-wordmark.png'\)\)\) \}\}/g,
        image.toString("base64"),
    )
    .replace(/\{\{--[\s\S]*?--\}\}/g, "")
    .replace(/@php[\s\S]*?@endphp/g, "")
    .replace("{{ ($enterpriseWorkspaceTour ?? false) ? ($enterprisePresentation['identity']['name'] ?? 'Current workspace') : 'Lees-McRae' }}", "Lees-McRae")
    .replace(/@if\(\$tourProposalHref\)[\s\S]*?@else([\s\S]*?)@endif/g, "$1")
    .replace(/\{\{ \$enterprisePresentation\['identity'\]\['name'\] \?\? 'Current workspace' \}\}/g, "Fictional reviewer campus")
    .replace(/\{\{ \(\$enterprisePriorityOnly \?\? false\) \? 'true' : 'false' \}\}/g, "false")
    // This standalone fixture selects the public branch of the shared Blade
    // markup. Actual private/public Blade rendering is covered by PHP tests.
    .replace(
        /@if\(\$enterpriseWorkspaceTour \?\? false\)[\s\S]*?@else([\s\S]*?)@endif/g,
        "$1",
    )
    .replace(
        /\{\{ \(\$enterpriseWorkspaceTour \?\? false\) \? '[^']*' : '([^']*)' \}\}/g,
        "$1",
    );
assert.ok(!/@(?:php|if|else|endif)|\{\{/.test(markup), "Standalone public fixture must resolve every Blade control and expression");
assert.ok(
    !markup.includes("file_get_contents"),
    "Fixture must render the actual embedded brand asset",
);
const source = await readFile(
    "resources/js/enterprise/reviewer/tour.js",
    "utf8",
);
const page = await browser.newPage({ hasTouch: true });
const external = [],
    errors = [];
page.on("request", (request) => {
    if (!request.url().startsWith("data:")) external.push(request.url());
});
page.on("pageerror", (error) => errors.push(error.message));
try {
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.setContent(
        '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:18px;font-family:system-ui,sans-serif;background:#f7f8f2}</style>' +
            markup,
    );
    await page.addScriptTag({ content: source });
    await page.evaluate(() => {
        globalThis.tourActions = [];
        globalThis.tourInstance = EverbranchReviewerTour.mount(
            document.querySelector("[data-reviewer-tour-root]"),
            {
                onNavigate: (section) =>
                    tourActions.push(["navigate", section]),
                onSampleRequest: (id) => tourActions.push(["sample", id]),
                onTryIt: () => tourActions.push(["try"]),
            },
        );
    });
    const launcher = page.getByRole("button", {
        name: "Tour",
        exact: true,
    });
    for (const [label, width, height] of [
        ["desktop", 1440, 1000],
        ["ipad-portrait", 820, 1180],
        ["ipad-landscape", 1180, 820],
        ["phone", 390, 844],
    ]) {
        await page.setViewportSize({ width, height });
        await launcher.click();
        assert.equal(
            await page
                .locator("[data-reviewer-tour-root]")
                .getAttribute("data-tour-playing"),
            "false",
            "Reduced motion starts paused",
        );
        await page
            .getByRole("heading", {
                name: "Find campus work",
                exact: true,
            })
            .waitFor();
        assert.equal(
            await page
                .locator("[data-tour-title]")
                .evaluate((element) => element === document.activeElement),
            true,
        );
        await page.keyboard.press("ArrowRight");
        await page
            .getByRole("heading", {
                name: "Review a request",
                exact: true,
            })
            .waitFor();
        const preview = page.getByRole("button", {
            name: "Preview the sample email",
            exact: true,
        });
        await preview.click();
        await page
            .getByRole("heading", {
                name: "Email preview · not sent",
                exact: true,
            })
            .waitFor();
        assert.equal(
            await page
                .locator("[data-tour-email-title]")
                .evaluate((element) => element === document.activeElement),
            true,
        );
        assert.ok(
            await page
                .locator("[data-tour-email] img")
                .evaluate((image) => image.complete && image.naturalWidth > 0),
        );
        assert.ok(
            await page
                .locator("[data-tour-email]")
                .evaluate(
                    (element) => element.scrollWidth <= element.clientWidth + 1,
                ),
            `${label} email overflow`,
        );
        await page.screenshot({
            path: resolve(evidence, "tour-email-" + label + ".png"),
            fullPage: true,
        });
        await page.keyboard.press("Escape");
        assert.equal(
            await preview.evaluate(
                (element) => element === document.activeElement,
            ),
            true,
            "Email close returns focus",
        );
        await page
            .getByRole("button", { name: "Play tour", exact: true })
            .click();
        await page
            .getByRole("button", { name: "Pause tour", exact: true })
            .click();
        assert.ok(
            await page
                .locator("[data-tour-dialog]")
                .evaluate(
                    (element) => element.scrollWidth <= element.clientWidth + 1,
                ),
            `${label} tour overflow`,
        );
        await page.keyboard.press("Escape");
        assert.equal(
            await launcher.evaluate(
                (element) => element === document.activeElement,
            ),
            true,
            "Tour close returns focus",
        );
    }
    for (let count = 0; count < 3; count++) {
        await launcher.click();
        await page
            .getByRole("button", { name: "07 Ask Everbranch", exact: false })
            .click();
        await page
            .getByRole("button", { name: "Restart", exact: true })
            .click();
        await page
            .getByRole("heading", {
                name: "Find campus work",
                exact: true,
            })
            .waitFor();
        await page
            .getByRole("button", { name: "Skip tour", exact: true })
            .click();
    }
    assert.deepEqual(
        await page.evaluate(() => tourActions),
        [],
        "Replay must not invoke actions",
    );
    await launcher.click();
    await page.keyboard.press("ArrowRight");
    await page
        .getByRole("button", { name: "Preview the sample email", exact: true })
        .click();
    await page
        .getByRole("button", { name: "View sample request", exact: true })
        .click();
    assert.deepEqual(
        await page.evaluate(() => tourActions),
        [["sample", "sample-request-audio"]],
        "Preview CTA is isolated sample navigation only",
    );
    // Complete playback through the visible controls. Play at the end must restart,
    // not silently remain stopped; virtual time changes no DOM or product state.
    const replayPage = await browser.newPage();
    await replayPage.clock.install();
    await replayPage.setContent('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">' + markup);
    await replayPage.addScriptTag({content:source});
    await replayPage.evaluate(() => EverbranchReviewerTour.mount(document.querySelector('[data-reviewer-tour-root]')));
    await replayPage.locator('[data-tour-open]').click();
    await replayPage.locator('[data-tour-scene="6"]').click();
    await replayPage.locator('[data-tour-play]').click();
    const remaining = await replayPage.locator('[data-tour-progress]').evaluate(node => node.max-node.value);
    await replayPage.clock.runFor(remaining + 100);
    assert.equal(await replayPage.locator('[data-tour-progress]').evaluate(node => node.value),180000);
    assert.equal(await replayPage.locator('[data-tour-play]').getAttribute('aria-pressed'),'false');
    await replayPage.locator('[data-tour-play]').click();
    assert.equal(await replayPage.locator('[data-tour-play]').getAttribute('aria-pressed'),'true');
    assert.equal(await replayPage.locator('[data-tour-progress]').evaluate(node => node.value)<1000,true);
    assert.match(await replayPage.locator('[data-tour-count]').innerText(),/1 of 7/);
    await replayPage.locator('[data-tour-play]').click();
    await replayPage.locator('[data-tour-scene="3"]').click();
    assert.match(await replayPage.locator('[data-tour-count]').innerText(),/4 of 7/);
    await replayPage.locator('[data-tour-back]').click();
    assert.match(await replayPage.locator('[data-tour-count]').innerText(),/3 of 7/);
    await replayPage.locator('[data-tour-close]').click();
    assert.equal(await replayPage.locator('[data-tour-dialog]').evaluate(node=>node.open),false);
    await replayPage.close();
    assert.deepEqual(external, []);
    assert.deepEqual(errors, []);
    console.log(
        `PASS (${engine}): seven-scene/180-second immutable tour, keyboard controls, reduced motion, phone/iPad/desktop email preview, Escape/focus return, replay without actions, isolated sample CTA, zero external requests/errors. Viewport simulation, not physical iPad acceptance.`,
    );
} finally {
    await browser.close();
}
