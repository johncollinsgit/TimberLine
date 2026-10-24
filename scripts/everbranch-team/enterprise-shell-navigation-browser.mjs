import {clickEnterpriseNavigation} from './enterprise-mobile-navigation-browser.mjs';
import assert from "node:assert/strict";
import {closeWorkDetail} from "./enterprise-work-detail-browser.mjs";

// Invoked by an existing isolated browser harness. This module never starts a
// server, seeds college data, installs an adapter or makes a provider request.
const nav = async (page) =>
    page.evaluate(() =>
        Object.fromEntries(
            new URLSearchParams(
                location.pathname.startsWith("/platform/demos/")
                    ? location.hash.slice(1)
                    : location.search,
            ),
        ),
    );
const tourState = async (page) =>
    page.evaluate(() =>
        Object.fromEntries(
            Object.entries(sessionStorage).filter(([key]) =>
                key.startsWith("everbranch-priorities-"),
            ),
        ),
    );

export async function verifyFilteredRequestMap(
    page,
    { synthetic, areaId, includedId, excludedId, query },
) {
    assert.equal(
        synthetic,
        true,
        "Use isolated fictional fixture records only",
    );
    assert.ok(areaId && includedId && excludedId && query);
    await page.evaluate(
        ({ areaId }) =>
            document.dispatchEvent(
                new CustomEvent("enterprise:navigate", {
                    detail: { area: areaId, section: "requests" },
                }),
            ),
        { areaId },
    );
    await page.locator(`#request-list [data-open="${includedId}"]`).waitFor();
    await page.locator("#search").fill(query);
    await page.locator('[data-view="map"]').click();
    assert.equal(
        await page.locator(`#request-list [data-open="${includedId}"]`).count(),
        1,
    );
    assert.equal(
        await page.locator(`#request-list [data-open="${excludedId}"]`).count(),
        0,
    );
    assert.equal(
        await page.locator('#map-markers [data-map-building="52"]').count(),
        1,
    );
    assert.equal(
        await page.locator('#map-markers [data-map-building="2"]').count(),
        0,
    );
    const listIds = await page
        .locator("#request-list [data-open]")
        .evaluateAll((rows) => rows.map((row) => row.dataset.open));
    const mappedIds = await page
        .locator('#campus-map-tickets [data-open]')
        .evaluateAll(rows=>rows.map(row=>row.dataset.open));
    assert.ok(mappedIds.length && mappedIds.every(id=>listIds.includes(id)),
        'Mapped jobs must remain a subset of the same filtered authorized rows');
    await page.locator('#map-markers [data-map-building="52"]').click();
    await page.locator(`#campus-map-tickets [data-open="${includedId}"]`).click();
    assert.equal((await nav(page)).selected, includedId);
    assert.equal((await nav(page)).section, "requests");
    await closeWorkDetail(page);
    await page.locator("#search").fill("");
}

export async function verifyShellNavigationHistory(
    page,
    { synthetic, areaId, projectId, otherAreaId },
) {
    assert.equal(
        synthetic,
        true,
        "Use isolated fictional fixture records only",
    );
    assert.ok(areaId && projectId && otherAreaId && areaId !== otherAreaId);
    const initialTour = await tourState(page);
    await page.evaluate(
        ({ areaId, projectId }) =>
            document.dispatchEvent(
                new CustomEvent("enterprise:navigate", {
                    detail: {
                        area: areaId,
                        section: "projects",
                        selected: projectId,
                    },
                }),
            ),
        { areaId, projectId },
    );
    await page.locator("#discussion-body").waitFor();
    const original = await page.locator("#discussion-body").inputValue();
    const retained =
        "Synthetic unsent note retained through navigation history.";
    await page.locator("#discussion-body").fill(retained);
    await closeWorkDetail(page);
    await page.locator("#search").fill("");
    await page.locator('[data-view="list"]').click();
    await page.locator('[data-view="calendar"]').click();
    await page.locator(`#request-list [data-open="${projectId}"]`).click();
    assert.equal((await nav(page)).view, "calendar");
    assert.equal((await nav(page)).selected, projectId);
    assert.equal(await page.locator("#discussion-body").inputValue(), retained);
    // Browser history may dismiss/reopen a native modal. Outside page controls
    // are used only after Close, which intentionally clears current selection.
    await page.goBack();
    await page.waitForFunction(() => !document.getElementById('work-drawer').open);
    assert.equal((await nav(page)).view, "calendar");
    assert.equal((await nav(page)).selected, undefined);
    await page.goBack();
    await page.locator('[data-view="list"][aria-pressed="true"]').waitFor();
    assert.equal(
        await page.locator('[data-view="list"]').getAttribute("aria-pressed"),
        "true",
    );
    await page.goForward();
    await page.locator('[data-view="calendar"][aria-pressed="true"]').waitFor();
    assert.equal(
        await page.locator('[data-view="calendar"]').getAttribute("aria-pressed"),
        "true",
    );
    await page.goForward();
    await page.locator("#discussion-body").waitFor();
    assert.equal((await nav(page)).selected, projectId);
    assert.equal(await page.locator("#discussion-body").inputValue(), retained);
    await page.reload();
    await page.locator("#discussion-body").waitFor();
    assert.equal(
        await page.locator('[data-view="calendar"]').getAttribute("aria-pressed"),
        "true",
    );
    assert.equal((await nav(page)).selected, projectId);
    assert.equal(await page.locator("#discussion-body").inputValue(), retained);
    await closeWorkDetail(page);
    await clickEnterpriseNavigation(page,page.locator(`#areas [data-area="${otherAreaId}"]`));
    await page.waitForFunction(
        (id) => new URLSearchParams(
            location.pathname.startsWith("/platform/demos/")
                ? location.hash.slice(1) : location.search,
        ).get("area_id") === id,
        otherAreaId,
    );
    await page.goBack();
    await page.locator(`#request-list [data-open="${projectId}"]`).click();
    await page.locator("#discussion-body").waitFor();
    assert.equal((await nav(page)).area_id, areaId);
    assert.equal((await nav(page)).view, "calendar");
    assert.equal((await nav(page)).selected, projectId);
    assert.equal(await page.locator("#discussion-body").inputValue(), retained);
    assert.deepEqual(
        await tourState(page),
        initialTour,
        "Navigation history cannot replace the saved tour step",
    );
    await page.locator("#discussion-body").fill(original);
}
