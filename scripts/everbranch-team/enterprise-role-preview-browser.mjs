import assert from "node:assert/strict";
import { resolve } from "node:path";

/** Read-only against the existing disposable HTTP fixture. Does not create users, memberships or work. */
export async function checkRolePreview(
    page,
    evidence,
    tenant = "synthetic-enterprise",
) {
    const endpoint = await page
        .locator("#enterprise-role-preview")
        .getAttribute("data-endpoint");
    assert.ok(
        endpoint,
        "Private owner/admin preview must be mounted in the approved fixture",
    );
    const initial = await page.evaluate(async (endpoint) => {
        const target = new URL(endpoint, location.href);
        target.pathname = target.pathname.replace(/\/role-preview$/, "/data");
        const response = await fetch(target.href, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });
        return { status: response.status, data: await response.json() };
    }, endpoint);
    assert.equal(initial.status, 200);
    const area =
        initial.data.areas.find(
            (row) => row.id === "90000000-0000-4000-8000-000000000001",
        ) ||
        initial.data.areas.find(
            (row) =>
                row.profile === "enterprise_shared" ||
                row.visibility === "shared",
        ) ||
        initial.data.areas[0];
    assert.ok(area, "An existing synthetic workspace is required");
    const destination = new URL(endpoint, page.url());
    destination.pathname = destination.pathname.replace(
        /\/enterprise\/role-preview$/,
        "/",
    );
    destination.search = new URLSearchParams({
        tenant,
        area_id: area.id,
        section: "people",
    }).toString();
    const response = page.waitForResponse(
        (result) =>
            new URL(result.url()).pathname.endsWith(
                "/enterprise/role-preview",
            ) && !new URL(result.url()).searchParams.has("member_id"),
    );
    await page.goto(destination.href);
    const rosterResponse = await response;
    assert.equal(rosterResponse.status(), 200);
    const roster = await rosterResponse.json();
    assert.equal(roster.read_only, true);
    assert.equal(roster.impersonation, false);
    const viewer = roster.roster.find((row) => row.tenant_role === "viewer");
    assert.ok(
        viewer,
        "The shared synthetic fixture must contain its actual read-only viewer",
    );
    await page
        .locator("#enterprise-role-preview")
        .waitFor({ state: "visible" });
    await page.waitForFunction(
        () => !document.querySelector("[data-erp-member]").disabled,
    );
    assert.match(await page.locator("[data-erp-roster]").innerText(), /viewer/);
    await page.locator("[data-erp-member]").selectOption(String(viewer.id));
    await page.locator("[data-erp-preview]").waitFor({ state: "visible" });
    assert.match(
        await page.locator("[data-erp-member-heading]").innerText(),
        /Read-only preview/,
    );
    assert.equal(
        await page.locator("[data-erp-member-heading]").innerText(),
        "Read-only preview: " + viewer.name,
    );
    assert.equal(
        await page
            .locator(
                "#enterprise-role-preview input[type=password],#enterprise-role-preview input[type=file],#enterprise-role-preview textarea",
            )
            .count(),
        0,
    );
    assert.equal(
        await page
            .locator("[data-erp-projects] img,[data-erp-requests] img")
            .count(),
        0,
    );
    for (const [name, width, height] of [
        ["desktop", 1440, 1000],
        ["ipad-portrait", 820, 1180],
        ["ipad-landscape", 1180, 820],
        ["phone", 390, 844],
    ]) {
        await page.setViewportSize({ width, height });
        await page.evaluate(
            () =>
                new Promise((resolve) =>
                    requestAnimationFrame(() =>
                        requestAnimationFrame(() => resolve(null)),
                    ),
                ),
        );
        const layout = await page.evaluate(() => ({
            width: innerWidth,
            scroll: document.documentElement.scrollWidth,
            x: scrollX,
            body: document.body.getBoundingClientRect().toJSON(),
            wide: [...document.querySelectorAll("body *")]
                .filter(
                    (n) =>
                        n.getClientRects().length &&
                        n.scrollWidth > n.clientWidth + 1,
                )
                .slice(0, 25)
                .map((n) => ({
                    id: n.id,
                    tag: n.tagName,
                    class: n.className,
                    width: n.clientWidth,
                    scroll: n.scrollWidth,
                    overflow: getComputedStyle(n).overflowX,
                })),
            offenders: [...document.querySelectorAll("body *")]
                .filter(
                    (node) =>
                        node.getClientRects().length &&
                        node.getBoundingClientRect().right + scrollX >
                            innerWidth + 1,
                )
                .slice(0, 15)
                .map((node) => ({
                    tag: node.tagName,
                    id: node.id,
                    class: node.className,
                    right: node.getBoundingClientRect().right + scrollX,
                })),
        }));
        assert.ok(
            layout.scroll <= layout.width + 1,
            name + " must not overflow: " + JSON.stringify(layout),
        );
        assert.equal(
            await page
                .locator("[data-erp-member]")
                .evaluate((node) => node.getBoundingClientRect().height >= 44),
            true,
        );
        await page.screenshot({
            path: resolve(evidence, "role-preview-" + name + ".png"),
            fullPage: true,
            caret: "initial",
        });
    }
    await page.locator("[data-erp-member]").focus();
    assert.equal(
        await page
            .locator("[data-erp-member]")
            .evaluate((node) => node === document.activeElement),
        true,
    );
    let release, captured;
    const held = new Promise((resolve) => {
        release = resolve;
    });
    const arrived = new Promise((resolve) => {
        captured = resolve;
    });
    const pattern = "**/enterprise/role-preview?*";
    await page.route(pattern, async (route) => {
        assert.equal(route.request().method(), "GET");
        if (!new URL(route.request().url()).searchParams.has("member_id"))
            return route.continue();
        const result = await route.fetch();
        captured();
        await held;
        try {
            await route.fulfill({ response: result });
        } catch (error) {
            if (
                !/closed|aborted|canceled|cancelled|interrupted|Invalid InterceptionId|Route is already handled/i.test(
                    String(error),
                )
            )
                throw error;
        }
    });
    try {
        await page.locator("[data-erp-refresh]").click();
        await arrived;
        await page.locator('[data-section="projects"]').click();
        assert.equal(
            await page.locator("#enterprise-role-preview").isHidden(),
            true,
        );
        assert.equal(await page.locator("[data-erp-roster] li").count(), 0);
        assert.equal(await page.locator("[data-erp-projects] li").count(), 0);
    } finally {
        release();
        await page.unroute(pattern);
    }
    await page.waitForTimeout(100);
    assert.equal(
        await page.locator("#enterprise-role-preview").isHidden(),
        true,
        "Late success cannot restore a former scope",
    );
    await page.locator('[data-section="people"]').click();
    await page.waitForFunction(
        () => !document.querySelector("[data-erp-member]").disabled,
    );
    await page.locator("[data-erp-member]").selectOption(String(viewer.id));
    await page.locator("[data-erp-preview]").waitFor({ state: "visible" });
    await page.route(pattern, (route) =>
        route.fulfill({
            status: 403,
            contentType: "application/json",
            body: JSON.stringify({ message: "Synthetic current-role denial" }),
        }),
    );
    await page.locator("[data-erp-refresh]").click();
    await page
        .locator("[data-erp-status]")
        .getByText(
            "This preview is unavailable in your current access. No previous member data is retained.",
            { exact: true },
        )
        .waitFor();
    assert.equal(await page.locator("[data-erp-roster] li").count(), 0);
    assert.equal(await page.locator("[data-erp-preview]").isHidden(), true);
    await page.unroute(pattern);
    console.log(
        "PASS admin read-only role preview: actual verified viewer roster, identity-preserving summaries, keyboard/touch viewports, stale-scope response denial and simulated fresh-role loss. No member or access mutation; viewport simulation is not physical iPad testing.",
    );
}
