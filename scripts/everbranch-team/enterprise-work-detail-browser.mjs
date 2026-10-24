import assert from 'node:assert/strict';

// Use the real accessible Close control before operating outside the native modal.
// Never force a click or alter dialog/selection state from the fixture.
export async function closeWorkDetail(page) {
    const drawer = page.locator('#work-drawer');
    if (!await drawer.count() || !await drawer.evaluate(node => node.open)) return;
    await drawer.getByRole('button', {name: 'Close', exact: true}).click();
    await drawer.waitFor({state: 'hidden'});
    assert.equal(await drawer.evaluate(node => node.open), false, 'Close restores the work table');
}
