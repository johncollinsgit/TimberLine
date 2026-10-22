import test from 'node:test';
import assert from 'node:assert/strict';
import { createScenario, filteredVehicles, attentionItems, dispatchCandidates, loadScenario } from '../../resources/js/highlevel/pest-demo-data.js';

test('250 distinct fictional trucks have coherent branch filters and attention targets', () => {
    const d = createScenario();
    assert.equal(new Set(d.vehicles.map(v => v.id)).size, 250);
    assert.equal(d.jobs.length, 258);
    assert.equal(filteredVehicles(d, { branch: 'Greenville', territory: '', crew: '', search: '' }).length, 63);
    assert.equal(filteredVehicles(d, { branch: '', territory: '', crew: '', search: 'Truck 250' })[0].id, 250);
    const rows = attentionItems(d);
    assert.equal(rows.filter(r => r.kind === 'Unassigned').length, 8);
    assert(rows.every(r => r.jobId ? d.jobs.some(j => j.id === r.jobId) : d.vehicles.some(v => v.id === r.vehicleId)));
    assert(!rows.some(r => r.key === 'late-1')); // completed job must not be called late
    assert(rows.some(r => r.key === 'stale-19'));
    assert(rows.some(r => r.key === 'offline-37'));
    assert(!rows.some(r => r.key === 'stale-37'));
});

test('dispatch confirms every constraint, excludes unknown schedules and rechecks assignments', () => {
    const d = createScenario(), j = d.jobs.find(j => j.id === 1001);
    const candidates = dispatchCandidates(d, j), good = candidates.filter(c => !c.reasons.length);
    assert(good.length > 0);
    for (const c of good) {
        assert.equal(c.vehicle.branch, j.branch);
        assert(c.vehicle.skills.includes('termite'));
        assert(c.vehicle.stock['bait stations'] >= 8);
        assert(c.vehicle.capacity >= 1);
        assert.equal(c.vehicle.outOfService, false);
    }
    const chosen = good[0].vehicle;
    d.jobs.push({ id: 9000, vehicleId: chosen.id, status: 'scheduled', start: null, end: null });
    assert(dispatchCandidates(d, j).find(c => c.vehicle.id === chosen.id).reasons.includes('Schedule conflict or unknown window'));
    chosen.capacity = 0;
    assert(dispatchCandidates(d, j).find(c => c.vehicle.id === chosen.id).reasons.includes('Insufficient confirmed stop capacity'));
    j.end = null;
    assert(dispatchCandidates(d, j).every(c => c.reasons.includes('Time window missing')));
});

test('browser restore rejects corrupt state and admits only known fixture records', () => {
    assert.equal(loadScenario({ getItem: () => '{' }).recovered, true);
    assert.equal(loadScenario({ getItem: () => { throw Error(); } }).data.vehicles.length, 250);
    const d = createScenario();
    d.vehicles[0].technician = '<script>untrusted record</script>';
    d.jobs[0].vehicleId = 999999;
    d.settings.units = 'km'; d.setup[0] = true;
    const result = loadScenario({ getItem: () => JSON.stringify(d) });
    assert.equal(result.data.settings.units, 'km');
    assert.equal(result.data.setup[0], true);
    assert.equal(result.data.vehicles[0].technician, 'Demo technician 001');
    assert.equal(result.data.jobs[0].vehicleId, null);
});
