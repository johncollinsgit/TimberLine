import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = new URL('../../', import.meta.url), read = path => fs.readFileSync(new URL(path, root), 'utf8');
const html = read('resources/demos/lees-mcrae-campus.html'), code = read('resources/js/campus/media.js');
const manifest = JSON.parse(read('resources/data/campus-media.json'));
const copy = value => JSON.parse(JSON.stringify(value));
function media() {
  const catalog = copy(manifest); for (const asset of Object.values(catalog.assets)) if (asset.file) asset.src = 'data:image/jpeg;base64,eA==';
  const context = { CAMPUS_MEDIA_CATALOG: catalog, CAMPUS_JOB_PHOTOS: [{ id: 'plumbing', src: 'data:image/jpeg;base64,eA==' }], document: { addEventListener() {}, querySelectorAll: () => [], body: {} }, MutationObserver: class { observe() {} }, requestAnimationFrame() {} };
  vm.createContext(context); vm.runInContext(code, context); return { api: context.CampusMedia, catalog };
}
async function projections() {
  const storage = new Map(), context = { structuredClone, URL, Response, location: { href: 'https://photos.example.test/' }, sessionStorage: { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value) } };
  vm.createContext(context); const start = html.indexOf('const OFFLINE_FIXTURE='); vm.runInContext(html.slice(start, html.indexOf('const icons=', start)), context);
  return { storage, role: async persona => { await context.fetch('/api/persona', { method: 'POST', body: JSON.stringify({ persona }) }); return (await context.fetch('/api/state')).json(); } };
}
test('photographs have pinned local bytes, source credits and an embedded payload budget', () => {
  let bytes = 0;
  for (const photo of Object.values(manifest.assets)) {
    assert(photo.author && photo.license && photo.source.startsWith('https://'));
    if (!photo.file) { assert.equal(photo.legacy_id, 'plumbing'); continue; }
    const value = fs.readFileSync(new URL('resources/demos/campus-media/' + photo.file, root)); bytes += value.length;
    assert.equal(value.length, photo.bytes); assert.equal(createHash('sha256').update(value).digest('hex'), photo.sha256);
    assert.equal(value[0], 255); assert.equal(value[1], 216); assert(photo.width >= 800);
  }
  assert(bytes < 1600000);
  for (const mappings of Object.values(manifest.records)) for (const ids of Object.values(mappings)) for (const id of ids) assert(Object.hasOwn(manifest.assets, id));
});
test('every persona gallery resolves only current projected items and never changes stored work', async () => {
  const fixture = await projections(), { api } = media();
  for (const persona of ['admin', 'manager', 'leader', 'staff', 'it', 'contractor']) {
    const data = await fixture.role(persona), original = JSON.stringify(data), saved = [...fixture.storage]; api.mount({ data, view: 'home' });
    for (const [kind, mappings] of Object.entries(manifest.records)) for (const id of Object.keys(mappings)) {
      const rows = { project: [...data.projects, ...data.operations.projects], task: data.tasks, job: [...data.jobs, ...data.operations.jobs], request: data.requests }[kind];
      assert.equal(api.photosFor(kind, id).length > 0, rows.some(row => row.id === id), persona + ' ' + kind + ':' + id);
    }
    assert.equal(JSON.stringify(data), original); assert.deepEqual([...fixture.storage], saved);
  }
});
test('retirement, revoked access, deleted records and unknown items cannot retain a gallery', () => {
  const { api } = media(); const data = { persona: 'admin', projects: [{ id: 'commons', name: 'Tech work' }], tasks: [], jobs: [], requests: [], operations: {} };
  api.mount({ data, view: 'home' }); assert.equal(api.photosFor('project', 'commons').length, 3);
  api.retire(); assert.equal(api.photosFor('project', 'commons').length, 0);
  api.mount({ data: { ...data, projects: [] }, view: 'home' }); assert.equal(api.photosFor('project', 'commons').length, 0);
  assert.equal(api.render('project', '__proto__'), ''); assert.equal(api.render('task', 'new-custom-task'), '');
});
test('contractor photos derive only from currently visible assigned jobs', () => {
  const { api } = media(); api.mount({ view: 'contractors', data: { persona: 'contractor', operations: { contractors: [{ id: 'c', name: 'Demo', assigned_job_ids: ['J09', 'J10'] }], jobs: [{ id: 'J09' }] } } });
  assert.deepEqual([...api.photosFor('contractor', 'c').map(photo => photo.id)], ['lounge', 'presentation']);
});
test('images cannot cause runtime provider requests and item titles are escaped', () => {
  const { api, catalog } = media(); api.mount({ view: 'home', data: { persona: 'admin', projects: [{ id: 'visit', name: '<img onerror="bad">' }] } });
  let markup = api.render('project', 'visit', 'cover'); assert.match(markup, /&lt;img onerror=&quot;bad&quot;&gt;/); assert.doesNotMatch(markup, /<img onerror/);
  catalog.assets.campus.src = 'https://tracking.example.test/pixel.jpg'; assert.equal(api.photosFor('project', 'visit').length, 1);
  catalog.assets.presentation.src = 'javascript:bad'; assert.equal(api.render('project', 'visit'), '');
});
test('single photo and multiple photo rows have appropriate controls', () => {
  const { api } = media(); api.mount({ view: 'home', data: { persona: 'admin', projects: [{ id: 'visit' }, { id: 'winter' }] } });
  assert.match(api.render('project', 'visit'), /data-photo-action="next"/); assert.doesNotMatch(api.render('project', 'winter'), /data-photo-action="next"/);
  assert.doesNotMatch(api.render('project', 'visit', 'thumb'), /data-photo-action="next"/); assert.match(api.render('project', 'visit', 'thumb'), /data-photo-action="open"/);
});
test('shared search and photo embedding is idempotent for the full hosted document', () => {
  const dir = fs.mkdtempSync(join(tmpdir(), 'campus-photos-')), path = join(dir, 'campus.html');
  try {
    fs.writeFileSync(path, html); const script = new URL('scripts/demos/embed-campus-search.php', root);
    execFileSync('php', [fileURLToPath(script), path], { cwd: fileURLToPath(root) });
    const once = fs.readFileSync(path); execFileSync('php', [fileURLToPath(script), path]);
    assert.deepEqual(fs.readFileSync(path), once); assert.equal(once.toString().split('<!-- CAMPUS_MEDIA_BEGIN -->').length, 2);
  } finally { fs.unlinkSync(path); fs.rmdirSync(dir); }
});
