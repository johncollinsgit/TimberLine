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

    test("opens a click-through website and workspace example", async ({ page }) => {
        await page.goto("/platform/promo");
        const projectCard = page.getByRole("button", { name: /project work/i });
        await projectCard.focus();
        await page.keyboard.press("Enter");

        const demo = page.locator("[data-industry-demo]");
        await expect(demo).toBeVisible();
        await expect(projectCard).toHaveAttribute("aria-pressed", "true");
        await expect(demo.getByRole("tab", { name: "Website" })).toHaveAttribute("aria-selected", "true");
        await expect(demo.getByText("Northline Build", { exact: true })).toBeVisible();

        await demo.getByRole("tab", { name: "Website" }).click();
        await demo.getByRole("button", { name: /start a project conversation/i }).click();
        await expect(demo.getByText(/request received/i)).toBeVisible();
        await demo.getByRole("button", { name: /manage business/i }).click();
        await expect(demo.getByRole("tab", { name: /everbranch workspace/i })).toHaveAttribute("aria-selected", "true");

        await demo.getByRole("button", { name: "Messages" }).click();
        await expect(demo.getByRole("heading", { name: /project update gives/i })).toBeVisible();
        await demo.getByRole("button", { name: /send project update/i }).click();
        await expect(demo.getByRole("button", { name: "Reply prepared" })).toBeVisible();

        await demo.getByRole("button", { name: "Marketing" }).click();
        await expect(demo.getByRole("heading", { name: /email and text/i })).toBeVisible();
        await expect(demo.getByText(/before-and-after project journal/i)).toBeVisible();
    });

    test("keeps the selected website static when reduced motion is requested", async ({ page }) => {
        await page.emulateMedia({ reducedMotion: "reduce" });
        await page.goto("/platform/promo");
        const fieldCard = page.getByRole("button", { name: /field & service teams/i });
        await fieldCard.click();
        const demo = page.locator("[data-industry-demo]");
        await page.waitForTimeout(1500);
        await expect(demo.getByRole("tab", { name: "Website" })).toHaveAttribute("aria-selected", "true");
        await expect(demo.getByRole("tab", { name: /everbranch workspace/i })).toHaveAttribute("aria-selected", "false");
    });

    test("makes every public industry example and workspace control reachable", async ({ page }) => {
        test.setTimeout(60_000);
        await page.goto("/platform/promo");
        const demo = page.locator("[data-industry-demo]");
        const examples = [
            { card: /retail & product brands/i, brand: "Juniper & Wick", fifth: "Follow-up" },
            { card: /field & service teams/i, brand: "Current & Air", fifth: "Schedule" },
            { card: /project work/i, brand: "Northline Build", fifth: "Projects" },
            { card: /independent studios/i, brand: "Field Notes Studio", fifth: "Pipeline" },
        ];

        for (const example of examples) {
            await page.getByRole("button", { name: example.card }).click();
            await demo.getByRole("tab", { name: "Website" }).click();
            await expect(demo.getByText(example.brand, { exact: true })).toBeVisible();

            const siteControls = demo.locator("[data-industry-site-nav]");
            for (let index = 0; index < await siteControls.count(); index += 1) {
                if (await siteControls.nth(index).isVisible()) await siteControls.nth(index).click();
            }
            await expect(demo.getByText(/interactive example keeps visitors/i)).toBeVisible();
            await demo.locator("[data-industry-site-action]").click();
            await expect(demo.getByText(/request received/i)).toBeVisible();

            await demo.locator("[data-industry-admin]").click();
            for (const name of ["Inbox", "Customers", "Work", "Messages", "Marketing", example.fifth]) {
                const control = demo.getByRole("button", { name, exact: true });
                await control.click();
                await expect(control).toHaveAttribute("aria-pressed", "true");
            }
            await demo.locator("[data-industry-message-action]").click();
            await expect(demo.getByRole("button", { name: "Reply prepared" })).toBeVisible();
        }
    });

    test("has a stable public landing visual", async ({ page }) => {
        await page.emulateMedia({ reducedMotion: "reduce" });
        await page.goto("/platform/promo");
        await page.locator(".eb-studio-photo-card").scrollIntoViewIfNeeded();
        await expect(page.locator(".eb-studio-photo-card img")).toBeVisible();
        await expect(page).toHaveScreenshot("studio-landing.png", { fullPage: true, animations: "disabled" });
    });
});
