#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// Read-only: never fetches, writes files, installs, claims tasks, pushes or deploys.
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const args = process.argv.slice(2), action = args[0] ?? 'status', options = new Map();
function usage() {
  console.log('node scripts/dev/workspace.mjs status [--json]');
  console.log('node scripts/dev/workspace.mjs handoff --device PC|Mac --task "short task" [--record "existing PR/task URL"]');
  console.log('Read-only. Review handoff output before sharing.');
}
function fail(message) { console.error(message); process.exit(1); }
if (['--help', 'help'].includes(action)) { usage(); process.exit(0); }
if (!['status', 'handoff'].includes(action)) { usage(); fail(`Unknown action: ${action}`); }
for (let i = 1; i < args.length; i++) {
  const flag = args[i];
  if (flag === '--json' && action === 'status') { options.set(flag, true); continue; }
  if (action === 'handoff' && ['--device', '--task', '--record'].includes(flag) && args[i + 1] && !args[i + 1].startsWith('--')) {
    options.set(flag, args[++i]); continue;
  }
  fail(`Unsupported or incomplete option: ${flag}`);
}
if (action === 'handoff' && (!options.get('--device') || !options.get('--task'))) fail('Handoff requires --device and --task.');
const git = (...command) => execFileSync('git', command, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trimEnd();
const optionalGit = (...command) => { try { return git(...command); } catch { return null; } };
let head;
try { head = git('rev-parse', '--verify', 'HEAD'); } catch { fail('Cannot inspect this checkout. Resolve Git access/ownership explicitly; no trust settings were changed.'); }
const branch = git('branch', '--show-current') || '(detached HEAD)';
const originMain = optionalGit('rev-parse', '--verify', 'refs/remotes/origin/main');
const mergeBase = originMain ? optionalGit('merge-base', 'refs/remotes/origin/main', 'HEAD') : null;
let divergence = null;
if (mergeBase) {
  const [behind, ahead] = git('rev-list', '--left-right', '--count', 'refs/remotes/origin/main...HEAD').split(/\s+/).map(Number);
  divergence = { ahead, behind };
}
const changed = git('status', '--porcelain=v1', '--untracked-files=all').split(/\r?\n/).filter(Boolean);
const branchChanged = mergeBase ? git('diff', '--name-only', mergeBase, 'HEAD').split(/\r?\n/).filter(Boolean) : null;
const fingerprint = name => existsSync(resolve(root, name)) ? createHash('sha256').update(readFileSync(resolve(root, name))).digest('hex') : null;
const snapshot = {
  branch, head, originMain, originMainIsLocalRef: true,
  shallow: git('rev-parse', '--is-shallow-repository') === 'true',
  divergence, dirty: changed.length > 0, changedPaths: changed, branchChangedPaths: branchChanged,
  node: process.version, ciNodeMajor: 22,
  installed: { phpDependencies: existsSync(resolve(root, 'vendor/autoload.php')), nodeDependencies: existsSync(resolve(root, 'node_modules')) },
  lockfiles: { 'composer.lock': fingerprint('composer.lock'), 'package-lock.json': fingerprint('package-lock.json') },
};
if (action === 'status') {
  if (options.has('--json')) console.log(JSON.stringify(snapshot, null, 2));
  else {
    console.log(`Branch: ${branch}\nHEAD: ${head}\nWorking tree: ${snapshot.dirty ? 'has local changes' : 'clean'}`);
    console.log(`origin/main: ${originMain ?? 'not available'} (local ref; fetch before planning against current main)`);
    console.log(divergence ? `Compared with that ref: ${divergence.ahead} ahead, ${divergence.behind} behind` : 'Ancestry comparison unavailable; do not infer that work is merged.');
    console.log(`Node: ${process.version} (CI major: 22)`);
    console.log(`Dependencies present: PHP=${snapshot.installed.phpDependencies}, Node=${snapshot.installed.nodeDependencies}`);
    console.log('Presence is not proof of lockfile compatibility; reinstall only when needed.');
    if (changed.length) console.log(changed.join('\n'));
  }
} else {
  const line = value => String(value).replace(/[\r\n]+/g, ' ');
  console.log(`# Handoff: ${line(options.get('--task'))}`);
  console.log(`- Updated (UTC): ${new Date().toISOString()}`);
  console.log(`- Task record: ${line(options.get('--record') ?? '[link existing PR/task; search before creating another]')}`);
  console.log(`- Active writer: ${line(options.get('--device'))} [name/session; release before another writer starts]`);
  console.log(`- Branch: ${branch}\n- HEAD: ${head}\n- Last fetched main ref: ${originMain ?? 'unknown'}`);
  console.log(`- Local state: ${snapshot.dirty ? 'UNCOMMITTED changes present; HEAD does not include them' : 'clean'}`);
  console.log('- Pushed commit / remote availability: [verify exact SHA on GitHub; local HEAD alone is not a handoff]');
  console.log('- Goal and acceptance: [one outcome]\n- Completed / already on main: [brief]\n- Changed paths (review private names before sharing):');
  console.log(`  - Committed on branch since merge base: ${branchChanged === null ? '[ancestry unavailable; inspect PR]' : branchChanged.length ? branchChanged.join(', ') : 'none'}`);
  console.log(changed.length ? changed.map(path => `  - ${path}`).join('\n') : '  - No uncommitted tracked/untracked paths');
  console.log('- Validation: [exact command, outcome, SHA/tree tested; list skipped/unknown checks]');
  console.log('- Open findings / conflicts: [include existing PR/reviewer findings; do not reset their status]');
  console.log('- Next action: [one concrete step]\n- Ownership transfer: [current device/session -> next device/session; no concurrent writer]');
  console.log('- Runtime state: source [local/PR/merged SHA]; deployed [verified SHA/unknown]; feature/provider activation [off/verified/unknown]');
  console.log('- Private evidence: [approved private location in private handoff only; no secrets/customer data here]');
  console.log('- Review before publishing; this command changed nothing.');
}
