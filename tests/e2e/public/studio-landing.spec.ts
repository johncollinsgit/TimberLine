import { expect, test } from "@playwright/test";

test.describe("Everbranch public studio", () => {
    test("keeps the launch-partner path and primary navigation available", async ({ page }) => {
        await page.goto("/platform/promo");
        await expect(page.getByRole("heading", { name: /your business has a rhythm/i })).toBeVisible();
        await expect(page.getByRole("link", { name: /become a launch partner/i }).first()).toHaveAttribute("href", /start/);
        await expect(page.getByRole("link", { name: "Plans" }).first()).toBeVisible();
        await expect(page.locator("[data-studio-story]")).toBeVisible();
    });

    test("supports keyboard workflow and story-dialog controls", async ({ page }) => {
        await page.goto("/platform/promo");
        await expect(page.locator("[data-studio-story]")).toHaveAttribute("data-studio-mounted", "true");
        const workStep = page.getByRole("button", { name: /your team moves/i });
        await workStep.focus();
        await page.keyboard.press("Enter");
        await expect(workStep).toHaveAttribute("aria-pressed", "true");
        await expect(page.locator("[data-studio-frame-headline]")).toContainText("Monroe Avenue");

        const storyButton = page.getByRole("button", { name: /see the everbranch story/i });
        await storyButton.focus();
        await page.keyboard.press("Enter");
        await expect(page.getByRole("dialog")).toBeVisible();
        await page.keyboard.press("Escape");
        await expect(page.getByRole("dialog")).not.toBeVisible();
        await expect(storyButton).toBeFocused();
    });

    test("honors reduced motion", async ({ page }) => {
        await page.emulateMedia({ reducedMotion: "reduce" });
        await page.goto("/platform/promo");
        await expect(page.locator("[data-studio-story]")).toHaveAttribute("data-studio-mounted", "true");
        await expect(page.locator(".eb-studio-hero video")).toHaveJSProperty("paused", true);
    });

    test("has a stable public landing visual", async ({ page }) => {
        await page.emulateMedia({ reducedMotion: "reduce" });
        await page.goto("/platform/promo");
        await page.locator(".eb-studio-photo-card").scrollIntoViewIfNeeded();
        await expect(page.locator(".eb-studio-photo-card img")).toBeVisible();
        await expect(page).toHaveScreenshot("studio-landing.png", { fullPage: true, animations: "disabled" });
    });
});
