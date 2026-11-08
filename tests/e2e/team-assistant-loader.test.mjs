import test from 'node:test';
import assert from 'node:assert/strict';
import { installTeamsAssistant } from '../../resources/js/team/assistant.js';

function fixture() {
  const host = { isConnected: true }, status = { hidden: true, textContent: '' }, fallback = { hidden: true };
  const entry = { querySelector: selector => selector === '[data-ai-assistant]' ? host : selector === '[data-team-assistant-status]' ? status : fallback };
  const shell = { isConnected: true, querySelector: () => entry };
  return { host, status, fallback, shell };
}
test('legacy embedded or unapproved shell never imports the assistant', async () => {
  let imports = 0;
  const load = () => { imports++; };
  assert.equal(await installTeamsAssistant(null, load), false);
  assert.equal(await installTeamsAssistant({ querySelector: () => null }, load), false);
  assert.equal(imports, 0);
});
test('approved standalone shell lazily mounts the existing assistant once without making AI requests', async () => {
  const { shell } = fixture(); let imports = 0, mounts = 0;
  const load = async () => { imports++; return { mountAiAssistantNow: () => { mounts++; } }; };
  const first = installTeamsAssistant(shell, load), second = installTeamsAssistant(shell, load);
  assert.equal(first, second); assert.equal(await first, true); assert.equal(imports, 1); assert.equal(mounts, 1);
});
test('a retired Teams shell cannot mount an assistant after its lazy import returns', async () => {
  const { shell, host } = fixture(); let resolve, mounts = 0;
  const pending = installTeamsAssistant(shell, () => new Promise(done => { resolve = done; }));
  await Promise.resolve(); host.isConnected = false;
  resolve({ mountAiAssistantNow: () => { mounts++; } });
  assert.equal(await pending, false); assert.equal(mounts, 0);
});
test('bundle failure exposes only the existing tenant-bound fallback and safe status', async () => {
  const { shell, status, fallback } = fixture();
  assert.equal(await installTeamsAssistant(shell, () => Promise.reject(new Error('PRIVATE FAILURE'))), false);
  assert.equal(status.hidden, false); assert.equal(fallback.hidden, false);
  assert.equal(status.textContent, 'Ask Everbranch could not open here. Try the assistant page.');
  assert.ok(!status.textContent.includes('PRIVATE FAILURE'));
});
