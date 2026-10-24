import assert from "node:assert/strict";
import { resolve } from "node:path";

export async function checkExecutiveDemo(page, evidence) {
    await page.locator("#executive-demo").waitFor({ state: "visible" });
    assert.equal(
        await page.locator("#reviewer-perspective").inputValue(),
        "executive",
    );
    assert.match(
        await page.locator("#executive-demo").innerText(),
        /Executive · read-only/,
    );
    assert.match(
        await page.locator("#executive-demo").innerText(),
        /no real account or grants/,
    );
    assert.equal(await page.locator("#executive-network button").count(), 4);
    assert.match(
        await page.locator("#executive-network").innerText(),
        /Onboarding/,
    );
    assert.match(
        await page.locator("#executive-network").innerText(),
        /Planned/,
    );
    const plans = await page.locator("#executive-software").innerText();
    for (const value of [
        "1,000",
        "2,000",
        "2,500",
        "Billing is inactive",
        "separate from project costs",
    ])
        assert.ok(plans.includes(value), value);
    await page
        .locator("#executive-metrics button")
        .filter({ hasText: "Invoice review packets" })
        .click();
    await page.locator("#finance-body").waitFor({ state: "visible" });
    assert.equal(
        await page.locator("#finance-new").isHidden(),
        true,
        "Executive sample cannot edit financial packets",
    );
    await page.locator("#home-nav").click();
    await page
        .locator("#executive-metrics button")
        .filter({ hasText: "Contractor renewal attention" })
        .click();
    await page.locator("#certificate-body").waitFor({ state: "visible" });
    assert.equal(await page.locator("#certificate-new").isHidden(), true);
    await page.locator("#home-nav").click();
    await page
        .locator("#executive-metrics button")
        .filter({ hasText: "Quarterly readiness" })
        .click();
    await page.locator("#quarterly-preview").waitFor({ state: "visible" });
    await page.locator("#home-nav").click();
    await page.locator("#executive-network button").first().click();
    await page.locator("#request-list [data-open]").first().waitFor();
    await page.locator("#home-nav").click();
    await page.locator("#executive-pricing-nav").click();
    await page.locator("#executive-software").waitFor({ state: "visible" });
    await page
        .getByRole("button", { name: "Priorities", exact: true })
        .click();
    assert.equal(await page.locator("[data-priority-card]").count(), 9);
    await page.locator("[data-priority-open=executive]").click();
    await page.waitForFunction(
        () => !document.querySelector("[data-priorities-highlight]").hidden,
    );
    await page.locator("[data-priorities-toggle]").click();
    await page.reload();
    await page
        .locator("[data-priorities-toggle]")
        .getByText("Resume tour", { exact: true })
        .waitFor();
    await page.locator("#home-nav").click();
    await page.locator("[data-priorities-end]").click();
    for (const [name, width, height] of [
        ["desktop", 1600, 1100],
        ["ipad-portrait", 820, 1180],
        ["ipad-landscape", 1180, 820],
        ["phone", 390, 844],
    ]) {
        await page.setViewportSize({ width, height });
        await page.evaluate(() => scrollTo(0, 0));
        assert.equal(
            await page.evaluate(
                () => document.documentElement.scrollWidth > innerWidth + 1,
            ),
            false,
            name + " executive overflow",
        );
        const heights = await page
            .locator("#executive-demo button")
            .evaluateAll((nodes) =>
                nodes
                    .filter((e) => e.getClientRects().length)
                    .map((e) => e.getBoundingClientRect().height),
            );
        assert.ok(
            heights.every((height) => height >= 43.5),
            name + " touch targets",
        );
        await page.screenshot({
            path: resolve(evidence, "executive-" + name + ".png"),
            fullPage: true,
        });
    }
    await page.locator("#reviewer-perspective").selectOption("worker");
    await page.waitForFunction(
        () =>
            document.getElementById("reviewer-perspective")?.value ===
                "worker" && document.getElementById("executive-demo")?.hidden,
    );
    assert.equal(await page.locator("#executive-pricing-nav").count(), 0);
    assert.equal(await page.locator("#finance-nav").isHidden(), true);
    console.log(
        "PASS executive sample: sourced labeled persona, configured simulated workspace network, evidence drilldowns, separate uncommitted software figures, read-only finance, saved branch/resume and desktop/phone/iPad viewports. No real grant, billing, campus operating metric or hosted workspace claimed.",
    );
}
