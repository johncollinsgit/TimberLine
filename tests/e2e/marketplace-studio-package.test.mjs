import test from 'node:test';
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {File} from 'node:buffer';
import {parseStudioPackage, verifyStudioAsset} from '../../resources/js/marketplace-studio-package.js';

const file = new File(['video fixture'], 'short-01.mp4', {type: 'video/mp4'});
const asset = {path: file.name, bytes: file.size, sha256: createHash('sha256').update('video fixture').digest('hex'), mime_type: file.type, kind: 'short'};
const approved = {schema_version: 1, approval: 'approved', visibility: 'public', assets: [asset]};
const files = new Map([[file.name, file]]);

test('selects approved public Studio clips, verifies selected files and enforces the size limit', async () => {
    assert.deepEqual(parseStudioPackage(approved), [asset]);
    assert.equal(await verifyStudioAsset(asset, files, file.size), file);
    await assert.rejects(verifyStudioAsset({...asset, sha256: 'a'.repeat(64)}, files, file.size), /verification failed/);
    await assert.rejects(verifyStudioAsset(asset, files, file.size - 1), /oversized/);
    await assert.rejects(verifyStudioAsset(asset, new Map(), file.size), /Missing/);
});

test('keeps draft, private, paid, unsupported and ambiguous manifests out of Watch import', () => {
    for (const change of [{approval:'draft'}, {visibility:'members'}, {visibility:'private'}, {schema_version:2},
        {assets:[asset, asset]}, {assets:[{...asset, path:'../short.mp4'}]}]) {
        assert.throws(() => parseStudioPackage({...approved, ...change}));
    }
});
