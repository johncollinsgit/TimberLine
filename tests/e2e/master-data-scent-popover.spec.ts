import { expect, test } from "@playwright/test";

test("master data scents popover editor supports escape + save", async ({ page }) => {
    test.skip(
        !process.env.PW_ADMIN_EMAIL || !process.env.PW_ADMIN_PASSWORD,
        "Set PW_ADMIN_EMAIL and PW_ADMIN_PASSWORD to run this regression test."
    );

    const consoleErrors: string[] = [];
    const pageErrors: string[] = [];

    page.on("console", (message) => {
        if (message.type() === "error") {
            consoleErrors.push(message.text());
        }
    });

    page.on("pageerror", (error) => {
        pageErrors.push(error.message);
    });

    await page.goto("/login");
    await page.getByLabel(/email/i).fill(process.env.PW_ADMIN_EMAIL ?? "");
    await page.getByLabel(/password/i).fill(process.env.PW_ADMIN_PASSWORD ?? "");
    await page.getByRole("button", { name: /log in|sign in/i }).click();
    await page.waitForLoadState("networkidle");

    await page.goto("/admin?tab=master-data&resource=scents");
    await expect(page.locator("#master-data-grid")).toBeVisible();

    const canvas = page.locator("#master-data-grid canvas").first();
    await expect(canvas).toBeVisible();

    const bounds = await canvas.boundingBox();
    expect(bounds).not.toBeNull();

    const cellX = (bounds?.x ?? 0) + 190;
    const cellY = (bounds?.y ?? 0) + 64;

    await page.mouse.click(cellX, cellY);
    await page.mouse.dblclick(cellX, cellY);

    const popover = page.getByTestId("md-popover-editor");
    const input = page.getByTestId("md-popover-input");
    const diagnostics = page.getByTestId("md-diagnostics");

    await expect(popover).toBeVisible();
    await expect(input).toBeFocused();
    await expect(diagnostics).toHaveAttribute("data-popover-visible", "true");
    await expect(diagnostics).toHaveAttribute("data-focus-inside-editor", "true");

    const originalValue = await input.inputValue();
    const escapeValue = `${originalValue} [tmp escape]`;

    await input.fill(escapeValue);
    await input.press("Escape");
    await expect(popover).toBeHidden();

    await page.mouse.dblclick(cellX, cellY);
    await expect(popover).toBeVisible();
    const reopenedInput = page.getByTestId("md-popover-input");
    await expect(reopenedInput).toHaveValue(originalValue);

    const savedValue = `${originalValue} [tmp save]`;
    await reopenedInput.fill(savedValue);
    await reopenedInput.press("Enter");
    await expect(popover).toBeHidden();

    await page.mouse.dblclick(cellX, cellY);
    const verifyInput = page.getByTestId("md-popover-input");
    await expect(verifyInput).toHaveValue(savedValue);

    await verifyInput.fill(originalValue);
    await verifyInput.press("Enter");
    await expect(popover).toBeHidden();

    await expect(diagnostics).toHaveAttribute("data-last-failed-save", "");
    expect(consoleErrors).toEqual([]);
    expect(pageErrors).toEqual([]);
});
