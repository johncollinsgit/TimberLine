import { expect, test, type Locator } from "@playwright/test";

const offer = "$499 one-time setup + $299/month. Additional text, email, and AI usage fees may apply.";

// Resolve translucent ancestor backgrounds, then compare text luminance (WCAG).
async function contrast(locator: Locator): Promise<number> {
    return locator.evaluate((element) => {
        const rgba = (value: string) => (value.match(/[\d.]+/g) || []).map(Number);
        const layers: number[][] = [];
        for (let node: Element | null = element; node; node = node.parentElement) {
            layers.unshift(rgba(getComputedStyle(node).backgroundColor));
        }
        let background = [255, 255, 255];
        for (const layer of layers) {
            const alpha = layer[3] ?? 1;
            background = background.map((channel, i) => layer[i] * alpha + channel * (1 - alpha));
        }
        const foreground = rgba(getComputedStyle(element).color);
        const alpha = foreground[3] ?? 1;
        const text = background.map((channel, i) => foreground[i] * alpha + channel * (1 - alpha));
        const luminance = (rgb: number[]) => rgb.map((channel) => {
            const v = channel / 255;
            return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
        }).reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
        const values = [luminance(text), luminance(background)].sort((a, b) => a - b);
        return (values[1] + 0.05) / (values[0] + 0.05);
    });
}

test("public header and workflow steps retain readable default, hover and focus states", async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.goto("/platform/promo");
    const results: Record<string, number> = {};
    for (const [name, locator] of [
        ["plans", page.locator(".eb-studio-nav__links").getByRole("link", { name: "Plans", exact: true })],
        ["example", page.locator(".eb-studio-nav__demo-link")],
        ["module", page.locator(".eb-studio-module-list a").first()],
        ["module-description", page.locator(".eb-studio-module-list small").first()],
        ["step", page.getByRole("button", { name: /your team moves/i })],
    ] as const) {
        await expect(locator).toBeVisible();
        results[`${name}-default`] = await contrast(locator);
        expect(results[`${name}-default`]).toBeGreaterThanOrEqual(4.5);
        await locator.hover();
        await expect.poll(() => locator.evaluate((element) => element.matches(":hover"))).toBe(true);
        await locator.evaluate(async (element) => {
            getComputedStyle(element).color;
            await Promise.all(element.getAnimations().map((animation) => animation.finished.catch(() => {})));
        });
        results[`${name}-hover`] = await contrast(locator);
        expect(results[`${name}-hover`]).toBeGreaterThanOrEqual(4.5);
        await page.mouse.move(0, 0);
        if (await locator.evaluate((element) => element.matches("a, button"))) {
            await page.keyboard.press("Tab");
            await locator.focus();
            await expect(locator).toBeFocused();
            await expect(locator).toHaveCSS("outline-style", "solid");
            await expect(locator).toHaveCSS("outline-width", "3px");
        }
    }
    await testInfo.attach("contrast-ratios", { body: JSON.stringify(results), contentType: "application/json" });
});

for (const width of [390, 820, 1440]) {
    test(`core public pages fit ${width}px and retain their primary action`, async ({ page }, testInfo) => {
        await page.setViewportSize({ width, height: 1000 });
        await page.emulateMedia({ reducedMotion: "reduce" });
        const results = [];
        for (const path of ["/platform/promo", "/platform/plans", "/platform/start", "/platform/demo", "/platform/contact", "/explore/modules", "/platform/examples/field"]) {
            const response = await page.goto(path);
            expect(response?.status()).toBe(200);
            const dimensions = await page.evaluate(() => ({ viewport: innerWidth, content: document.documentElement.scrollWidth }));
            expect(dimensions.content, `${path} horizontal overflow`).toBeLessThanOrEqual(dimensions.viewport + 1);
            if (["/platform/promo", "/platform/plans", "/platform/start"].includes(path)) {
                const screenshot = testInfo.outputPath(`${path.split("/").pop()}-${width}.png`);
                await page.screenshot({ path: screenshot });
                await testInfo.attach(`${path}-${width}`, { path: screenshot, contentType: "image/png" });
            }
            const action = ["/platform/start", "/platform/demo", "/platform/contact"].includes(path)
                ? page.locator('button[type="submit"]').first()
                : page.getByRole("link", { name: /get started|create a workspace|choose your branches/i }).first();
            await action.scrollIntoViewIfNeeded();
            await expect(action).toBeVisible();
            const box = await action.boundingBox();
            expect(box!.x).toBeGreaterThanOrEqual(0);
            expect(box!.x + box!.width).toBeLessThanOrEqual(width + 1);
            results.push({ path, ...dimensions, primaryAction: await action.innerText() });
        }
        await testInfo.attach("responsive-pages", { body: JSON.stringify(results), contentType: "application/json" });
    });
}

test("public forms expose accessible labels including optional fields", async ({ page }) => {
    for (const path of ["/platform/start", "/platform/demo", "/platform/contact"]) {
        await page.goto(path);
        for (const details of await page.locator("details").all()) {
            await details.locator("summary").click();
        }
        for (const field of await page.locator('form input:not([type="hidden"]), form select, form textarea').all()) {
            if (await field.isVisible()) await expect(field).toHaveAccessibleName(/\S/);
        }
    }
});

test("the current offer and conditional fees are visible before requesting access", async ({ page }) => {
    await page.emulateMedia({ reducedMotion: "reduce" });
    for (const path of ["/platform/promo", "/platform/plans", "/platform/start", "/explore/modules"]) {
        await page.goto(path);
        await expect(page.getByText(offer, { exact: true }).filter({ visible: true }).first()).toBeVisible();
    }
    await page.goto("/platform/start");
    const pricing = page.getByRole("tab", { name: "Pricing", exact: true });
    await pricing.focus();
    await page.keyboard.press("Enter");
    await expect(pricing).toHaveAttribute("aria-selected", "true");
    await expect(page.getByRole("tabpanel", { name: "Pricing" })).toContainText("$299/month");
    await expect(page.getByRole("tabpanel", { name: "Pricing" })).toContainText("$499 one-time");
    await page.locator("details summary").click();
    await expect(page.getByLabel("Preferred offer")).toHaveValue("standard");
    expect(await page.getByLabel("Preferred offer").locator("option").evaluateAll((options) => options.map((option) => (option as HTMLOptionElement).value))).toEqual(["", "standard"]);
});

test("the parent site presents the same offer at phone, tablet and desktop widths", async ({ page }, testInfo) => {
    // Resolve this public host through the disposable loopback server only.
    await page.route("**/*", async (route) => {
        const url = new URL(route.request().url());
        if (url.hostname !== "evergrovesoftware.com") return route.abort();
        const response = await route.fetch({
            url: `http://127.0.0.1:4177${url.pathname}${url.search}`,
            headers: { ...route.request().headers(), host: "evergrovesoftware.com" },
        });
        await route.fulfill({ response });
    });
    await page.emulateMedia({ reducedMotion: "reduce" });
    for (const width of [390, 820, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        await page.goto("http://evergrovesoftware.com/");
        const pricing = page.locator("#pricing");
        await pricing.scrollIntoViewIfNeeded();
        await expect(pricing).toContainText(offer);
        await expect(pricing).toContainText("$499 one-time");
        await expect(pricing.locator(".eg-pricing-card")).toHaveCount(1);
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width + 1);
        const screenshot = testInfo.outputPath(`evergrove-pricing-${width}.png`);
        await page.screenshot({ path: screenshot });
        await testInfo.attach(`parent-pricing-${width}`, { path: screenshot, contentType: "image/png" });
    }
});


test("choosing an included branch carries the selection into signup", async ({ page }) => {
    await page.goto("/explore/modules/messaging");
    await expect(page.getByText("Included with Everbranch Simple", { exact: true }).filter({ visible: true }).first()).toBeVisible();
    await expect(page.locator("main")).not.toContainText("$19.99");
    await page.getByRole("link", { name: "Choose this branch", exact: true }).click();
    await expect(page).toHaveURL(/branch=messaging/);
    await expect(page.getByLabel("Messaging", { exact: true })).toBeVisible();
    await expect(page.getByLabel("Messaging", { exact: true })).toBeChecked();
    await expect(page.getByLabel("Preferred offer")).toHaveValue("standard");
});
