import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/views/enterprise/resources-script.blade.php', import.meta.url), 'utf8');
const stamp = source.match(/^  const resourceStamp=[^\n]+;/m)?.[0];
assert.ok(stamp, 'Exercise the actual resource history formatter');
const context = vm.createContext({});
vm.runInContext(stamp+'\nglobalThis.formatHistoryInstant=resourceStamp;', context);
const instants = [
    ['2026-10-23T04:21:00+00:00', 'Oct 23, 2026, 12:21 AM Eastern · 2026-10-23T04:21:00Z'],
    ['2026-03-08T06:30:00+00:00', 'Mar 8, 2026, 1:30 AM Eastern · 2026-03-08T06:30:00Z'],
    ['2026-03-08T07:30:00+00:00', 'Mar 8, 2026, 3:30 AM Eastern · 2026-03-08T07:30:00Z'],
    ['2026-11-01T05:30:00+00:00', 'Nov 1, 2026, 1:30 AM Eastern · 2026-11-01T05:30:00Z'],
    ['2026-11-01T06:30:00+00:00', 'Nov 1, 2026, 1:30 AM Eastern · 2026-11-01T06:30:00Z'],
];

test('UTC history instants retain Eastern DST and exact UTC evidence regardless of browser-local timezone', () => {
    const previous = process.env.TZ;
    try {
        for (const zone of ['UTC', 'America/New_York', 'Pacific/Honolulu', 'Asia/Tokyo']) {
            process.env.TZ = zone;
            for (const [instant, expected] of instants) {
                assert.equal(context.formatHistoryInstant(instant).replace(' at ', ', ').replace(/\s+/g, ' '), expected, `${zone}: ${instant}`);
            }
        }
    } finally {
        if (previous === undefined) delete process.env.TZ;
        else process.env.TZ = previous;
    }
});
