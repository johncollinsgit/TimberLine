import { expect, test } from "@playwright/test";

test("retail offer explains both paths and the complete customer-to-profit journey", async ({ page }) => {
    const writes: string[] = [];
    const assets: string[] = [];
    await page.route("https://fonts.bunny.net/**", (route) => route.abort());
    page.on("request", (request) => {
        if (!["GET", "HEAD"].includes(request.method())) writes.push(request.url());
        if (request.url().includes("/build/assets/")) assets.push(request.url());
    });
    await page.goto("/platform/retail");
    await expect(page.getByRole("heading", { name: "A great shop is more than its checkout." })).toBeVisible();
    for (const id of ["choose-your-path", "customer-experience", "retail-team", "retail-tools", "launch-plan"]) {
        await expect(page.locator("#" + id)).toBeAttached();
    }
    await expect(page.getByText("Record time. Review the hours.", { exact: true })).toBeVisible();
    await expect(page.getByText(/hourly pay or commission/)).toBeVisible();
    await expect(page.getByText(/they do not process payroll/)).toBeVisible();
    await expect(page.getByRole("link", { name: /plan my retail setup/i })).toHaveAttribute("href", /platform\/contact\?intent=walkthrough/);
    await expect(page.getByRole("link", { name: /get started with everbranch/i })).toHaveAttribute("href", /platform\/start/);
    await expect(page.locator("[data-retail-calculator]")).toHaveAttribute("data-retail-mounted", "true");
    expect(assets.some((url) => /\/public-retail-entry-[^/]+\.js/.test(url))).toBe(true);
    expect(assets.filter((url) => /\/(app|bootstrap|client)-[^/]+\.(js|css)/.test(url))).toEqual([]);
    expect(writes).toEqual([]);
});

for (const width of [390, 820, 1440]) {
    test("retail offer fits " + width + "px without losing its sections or primary action", async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await page.emulateMedia({ reducedMotion: "reduce" });
        await page.goto("/platform/retail");
        await expect(page.getByRole("link", { name: /plan my retail setup/i })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width + 1);
        for (const id of ["choose-your-path", "customer-experience", "retail-team", "retail-tools", "launch-plan"]) {
            await page.locator("#" + id).scrollIntoViewIfNeeded();
            expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width + 1);
        }
    });
}

test("retail FAQ works with the keyboard and states rewards integration boundaries", async ({ page }) => {
    await page.goto("/platform/retail");
    const question = page.getByText("Does importing history enable rewards inside Square POS?", { exact: true });
    await question.focus();
    await page.keyboard.press("Enter");
    await expect(question.locator("..")).toHaveAttribute("open", "");
    await expect(page.getByText(/Importing records does not add Everbranch reward earning or redemption/)).toBeVisible();
    const checkout = page.getByText("Can customers use rewards on every website checkout?", { exact: true });
    await checkout.click();
    await expect(page.getByText(/Native Everbranch Website reward redemption is not yet offered/)).toBeVisible();
});

test("public home and fictional retail example lead to the real retail offer", async ({ page }) => {
    await page.goto("/platform/promo");
    await page.getByRole("link", { name: /For retailers · keep Square or move in phases/ }).click();
    await expect(page).toHaveURL(/platform\/retail/);
    await page.getByRole("link", { name: /Explore a fictional retail example/ }).click();
    await expect(page).toHaveURL(/platform\/examples\/retail/);
    await page.getByRole("link", { name: /See the retail offer/ }).click();
    await expect(page).toHaveURL(/platform\/retail/);
});

test("illustrative event graph responds to optional costs and keeps invalid assumptions unknown", async ({ page }) => {
    const writes: string[] = [];
    page.on("request", (request) => { if (!["GET", "HEAD"].includes(request.method())) writes.push(request.url()); });
    await page.goto("/platform/retail");
    const example = page.locator("[data-retail-calculator]");
    await expect(example).toHaveAttribute("data-retail-mounted", "true");
    await expect(example.locator('[data-retail-result="breakEven"]')).toHaveText("$1,556.85");
    await expect(example.locator('[data-retail-result="surplus"]')).toHaveText("$480.60");
    await expect(example.locator('[data-retail-table="sales"]')).toHaveText("$2,400.00");
    await expect(example.locator('[data-retail-table="costs"]')).toHaveText("$1,919.40");
    await example.getByRole("checkbox", { name: "Include a hotel" }).uncheck();
    await expect(example.locator('[data-retail-table="hotel"]')).toHaveText("$0.00");
    await expect(example.locator('[data-retail-result="surplus"]')).toHaveText("$680.60");
    await expect(example.locator('[data-retail-result="breakEven"]')).toHaveText("$1,205.97");
    await example.getByLabel("Product cost (% of sales)", { exact: true }).fill("99");
    await expect(example.locator('[data-retail-result="breakEven"]')).toHaveText("Review inputs");
    await expect(example.locator('[data-retail-table="variable"]')).toHaveText("Unknown");
    await expect(example.locator('[data-retail-table="costs"]')).toHaveText("Unknown");
    await expect(page.getByRole("img", { name: "Fictional two-year sales and cost comparison" })).toBeAttached();
    await expect(page.getByRole("table", { name: "Same fictional values in a table" })).toBeAttached();
    expect(writes).toEqual([]);
});
