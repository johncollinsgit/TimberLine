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
        await expect(page.locator("[data-studio-hero]")).toHaveAttribute("data-studio-hero-rotation", "reduced");
        await expect(page.locator("[data-studio-hero-slide]")).toHaveCount(3);
        await expect(page.locator("[data-studio-hero-slide].is-active")).toHaveCount(1);
    });

    test("rotates the field-service and owner hero scenes", async ({ page }) => {
        await page.goto("/platform/promo");
        await expect(page.locator("[data-studio-hero]")).toHaveAttribute("data-studio-hero-rotation", "active");
        await expect(page.locator("[data-studio-hero-slide]")).toHaveCount(3);
        await expect(page.locator("[data-studio-hero-slide]").nth(2)).toHaveAttribute("src", /everbranch-field-owner-office\.jpg/);
    });

    test("links every industry card to a dedicated example page", async ({ page }) => {
        await page.goto("/platform/promo");
        const examples = ["retail", "field", "projects", "studio", "practice", "community"];
        await expect(page.locator("[data-industry-option]")).toHaveCount(6);
        for (const discipline of examples) {
            await expect(page.locator(`[data-industry-option="${discipline}"]`)).toHaveAttribute("href", new RegExp(`/platform/examples/${discipline}`));
        }
    });

    test("opens a linkable example with a persistent control bar and a four-second handoff", async ({ page }) => {
        test.setTimeout(20_000);
        await page.goto("/platform/examples/projects");
        const demo = page.locator("[data-industry-page]");
        await expect(demo).toHaveAttribute("data-industry-mounted", "true");
        await expect(page.getByRole("link", { name: /back to everbranch/i })).toHaveAttribute("href", /promo#industries/);
        await expect(page.getByRole("link", { name: "Project work" })).toHaveAttribute("aria-current", "page");
        await expect(page.getByRole("tab", { name: "Website" })).toHaveAttribute("aria-selected", "true");
        await expect(page.getByText("Northline Build", { exact: true })).toBeVisible();

        await page.getByRole("button", { name: /start a project conversation/i }).click();
        await expect(page.getByText(/request received/i)).toBeVisible();
        await page.getByRole("button", { name: /open operations workspace/i }).click();
        await expect(page.locator("[data-industry-page-frame]")).toHaveClass(/is-switching/);
        await page.waitForTimeout(4_100);
        await expect(page.getByRole("tab", { name: /operations workspace/i })).toHaveAttribute("aria-selected", "true");

        await page.getByRole("button", { name: "Messages" }).click();
        await expect(page.getByRole("heading", { name: /project update gives/i })).toBeVisible();
        await page.getByRole("button", { name: /send project update/i }).click();
        await expect(page.getByRole("button", { name: "Reply prepared" })).toBeVisible();
        await page.getByRole("button", { name: "Marketing" }).click();
        await expect(page.getByText(/before-and-after project journal/i)).toBeVisible();
    });

    test("keeps example controls immediate for reduced-motion visitors and exposes all disciplines", async ({ page }) => {
        await page.emulateMedia({ reducedMotion: "reduce" });
        for (const discipline of ["retail", "field", "projects", "studio", "practice", "community"]) {
            await page.goto(`/platform/examples/${discipline}`);
            await expect(page.locator("[data-industry-page]")).toHaveAttribute("data-industry-mounted", "true");
            await expect(page.getByRole("tab", { name: "Website" })).toHaveAttribute("aria-selected", "true");
        }
        await page.getByRole("button", { name: /open operations workspace/i }).click();
        await expect(page.getByRole("tab", { name: /operations workspace/i })).toHaveAttribute("aria-selected", "true");
    });

    test("has a stable public landing visual", async ({ page }) => {
        await page.emulateMedia({ reducedMotion: "reduce" });
        await page.goto("/platform/promo");
        await page.locator(".eb-studio-photo-card").scrollIntoViewIfNeeded();
        await expect(page.locator(".eb-studio-photo-card img")).toBeVisible();
        await expect(page).toHaveScreenshot("studio-landing.png", { fullPage: true, animations: "disabled" });
    });

    test("has a stable public industry-system visual", async ({ page }) => {
        await page.emulateMedia({ reducedMotion: "reduce" });
        await page.goto("/platform/examples/field");
        await expect(page.locator("[data-industry-page-frame]")).toBeVisible();
        await expect(page).toHaveScreenshot("industry-system.png", { fullPage: true, animations: "disabled" });
    });
});
