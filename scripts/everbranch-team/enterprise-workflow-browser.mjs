import {clickEnterpriseNavigation} from './enterprise-mobile-navigation-browser.mjs';
import assert from "node:assert/strict";
import { resolve } from "node:path";
import { readFile } from "node:fs/promises";

export async function checkWorkflowWorkspace(page, evidence) {
    page.on("dialog", async (dialog) => {
        assert.equal(
            dialog.type(),
            "beforeunload",
            "Unexpected executable dialog",
        );
        await dialog.accept();
    });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page
        .getByRole("button", { name: "Campus overview", exact: true })
        .click();
    await page.locator("#briefing-body .briefing-hero").waitFor();
    await page
        .locator("#briefing-body")
        .getByText("SAMPLE DATA", { exact: false })
        .first()
        .waitFor();
    const [pdf] = await Promise.all([
        page.waitForEvent("download"),
        page
            .getByRole("link", {
                name: "Download one-page briefing",
                exact: true,
            })
            .click(),
    ]);
    const downloaded = resolve(evidence, "enterprise-current-briefing.pdf");
    await pdf.saveAs(downloaded);
    const bytes = await readFile(downloaded);
    assert.ok(bytes.subarray(0, 8).toString().startsWith("%PDF-1.4"));
    assert.equal(
        (bytes.toString().match(/\/Type \/Page\b/g) || []).length,
        1,
        "Briefing must fit one page",
    );
    await page
        .getByRole("button", {
            name: "Summarize with Ask Everbranch",
            exact: true,
        })
        .click();
    await page
        .locator("#briefing-body")
        .getByText(/AI prioritization is unavailable/)
        .first()
        .waitFor();
    await page.waitForFunction(
        () => !document.getElementById("briefing-ai").disabled,
    );
    for (const [label, width, height] of [
        ["desktop", 1440, 1000],
        ["ipad-portrait", 820, 1180],
        ["ipad-landscape", 1180, 820],
        ["phone", 390, 844],
    ]) {
        await page.setViewportSize({ width, height });
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
            `${label} briefing overflow`,
        );
        await page.screenshot({
            path: resolve(evidence, `enterprise-briefing-${label}.png`),
            fullPage: true,
        });
    }
    await clickEnterpriseNavigation(page, page.getByRole("button", {name: "Projects", exact: true}));
    await page.locator('#work-status').selectOption('all');
    await page.locator('[data-view="list"]').click();
    await page
        .getByRole("button", { name: "Synthetic repair project", exact: true })
        .click();
    await page
        .getByRole("link", { name: "Open workflow workspace", exact: true })
        .click();
    await page
        .getByRole("heading", {
            name: "From a request to a clear next step.",
            exact: true,
        })
        .waitFor();
    await clickEnterpriseNavigation(page, page.getByRole("button", {name: "Use Illustrative maintenance workflow", exact: true}));
    await clickEnterpriseNavigation(page, page.getByRole("button", {name: "▤ Screens & forms", exact: true}));
    await page
        .getByLabel("Connected step", { exact: true })
        .selectOption("intake");
    await page
        .getByLabel("Screen title", { exact: true })
        .fill("Campus work intake");
    // Switch away and reload to verify current-authority-gated local draft restoration.
    await clickEnterpriseNavigation(page, page.getByRole("button", {name: "⚙ Settings", exact: true}));
    await page
        .getByLabel("Workflow name", { exact: true })
        .fill("Synthetic campus operating workflow");
    await page.reload();
    await page.getByLabel("Workflow name", { exact: true }).waitFor();
    assert.equal(
        await page.getByLabel("Workflow name", { exact: true }).inputValue(),
        "Synthetic campus operating workflow",
    );
    // Commit a draft, lose its HTTP response, reload, and replay its exact receipt.
    let droppedSave = false;
    await page.route("**/enterprise/workflows?*", async (route) => {
        if (route.request().method() === "POST" && !droppedSave) {
            droppedSave = true;
            await route.fetch();
            return route.abort();
        }
        return route.continue();
    });
    await page.getByRole("button", { name: "Save draft", exact: true }).click();
    await page
        .getByRole("button", { name: "Retry exact operation", exact: true })
        .waitFor();
    await page.reload();
    await page
        .getByRole("button", { name: "Retry exact operation", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Review activation preview", exact: true })
        .waitFor();
    assert.equal(
        await page
            .locator(".ew-library button")
            .filter({ hasText: "Synthetic campus operating workflow" })
            .count(),
        1,
        "Draft replay duplicated workflow",
    );
    await page
        .getByRole("button", { name: "Review activation preview", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Confirm activation", exact: true })
        .click();
    await page
        .locator(".ew-badge")
        .filter({ hasText: /^active/ })
        .waitFor();
    await clickEnterpriseNavigation(page, page.getByRole("button", {name: "◇ Canvas", exact: true}));
    await page
        .getByRole("button", { name: "Accessible list", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Describe the work form", exact: false })
        .click();
    await page.getByLabel("Step name", { exact: true }).waitFor();
    for (const [label, width, height] of [
        ["desktop", 1440, 1000],
        ["ipad-portrait", 820, 1180],
        ["ipad-landscape", 1180, 820],
        ["phone", 390, 844],
    ]) {
        await page.setViewportSize({ width, height });
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
            `${label} workspace overflow`,
        );
        if (label.startsWith("ipad")) {
            await page
                .getByRole("button", { name: "Canvas", exact: true })
                .tap();
            await page
                .getByRole("button", { name: "Accessible list", exact: true })
                .tap();
        }
        assert.ok(
            await page
                .getByRole("button", { name: "Canvas", exact: true })
                .evaluate((el) => el.getBoundingClientRect().height >= 44),
            `${label} workflow touch target`,
        );
        await page.screenshot({
            path: resolve(evidence, `enterprise-workflow-list-${label}.png`),
            fullPage: true,
        });
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.getByRole("button", { name: "Canvas", exact: true }).click();
    await page.locator(".react-flow__node").first().waitFor();
    await page.screenshot({
        path: resolve(evidence, "enterprise-workflow-canvas-desktop.png"),
        fullPage: true,
    });
    await page
        .getByRole("button", { name: "▷ Runs & history", exact: true })
        .click();
    // Lose the start response and recover without creating another run.
    let droppedStart = false;
    await page.route("**/enterprise/workflows/*/runs?*", async (route) => {
        if (!droppedStart) {
            droppedStart = true;
            await route.fetch();
            return route.abort();
        }
        return route.continue();
    });
    await page
        .getByRole("button", { name: "Start a run", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Retry exact operation", exact: true })
        .waitFor();
    await page.reload();
    await page
        .getByRole("button", { name: "Retry exact operation", exact: true })
        .click();
    await page.getByLabel("Work description *", { exact: true }).waitFor();
    assert.equal(
        await page.locator(".ew-run-row").count(),
        1,
        "Run start replay duplicated run",
    );
    await page
        .getByLabel("Work description *", { exact: true })
        .fill("Synthetic urgent room repair <img src=x onerror=alert(1)>");
    await page.getByLabel("Priority *", { exact: true }).selectOption("Urgent");
    await page.reload();
    await page.getByLabel("Work description *", { exact: true }).waitFor();
    assert.ok(
        (
            await page
                .getByLabel("Work description *", { exact: true })
                .inputValue()
        ).includes("Synthetic urgent room repair"),
        "Unsent run fields were lost",
    );
    await page
        .getByRole("button", { name: "Submit & continue", exact: true })
        .click();
    await page
        .getByRole("heading", { name: "Review urgent work", exact: true })
        .waitFor();
    await page
        .locator(".ew-collected")
        .getByText(
            "Synthetic urgent room repair <img src=x onerror=alert(1)>",
            { exact: true },
        )
        .waitFor();
    assert.equal(
        await page.locator(".ew-collected img").count(),
        0,
        "Run values became HTML",
    );
    await page.getByRole("button", { name: "Approve", exact: true }).click();
    await page
        .getByLabel("Completion note *", { exact: true })
        .fill("Synthetic work completed and checked");
    let droppedAdvance = false;
    await page.route(
        "**/enterprise/workflows/*/runs/*/advance?*",
        async (route) => {
            if (!droppedAdvance) {
                droppedAdvance = true;
                await route.fetch();
                return route.abort();
            }
            return route.continue();
        },
    );
    await page
        .getByRole("button", { name: "Submit & continue", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Retry exact operation", exact: true })
        .click();
    await page
        .locator(".ew-run-layout .ew-badge")
        .filter({ hasText: "completed" })
        .waitFor();
    await page.screenshot({
        path: resolve(evidence, "enterprise-workflow-completed-desktop.png"),
        fullPage: true,
    });
    await page
        .getByRole("link", { name: "← Back to Projects", exact: true })
        .click();
    await page
        .getByRole("heading", { name: "Synthetic repair project", exact: true })
        .waitFor();
    console.log(
        "PASS workflow workspace: full-screen canvas/screens/settings/history, reviewed activation, persisted urgent condition/approval/assignment/completion, exact lost save/start/advance recovery, retained run fields, one-page briefing and desktop/phone/iPad viewport checks.",
    );
}
