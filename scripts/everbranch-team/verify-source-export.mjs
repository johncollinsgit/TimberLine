#!/usr/bin/env node
import { createHash, randomUUID } from 'node:crypto';
import { readFile, readdir, lstat, realpath, writeFile, rename, access } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const digest = bytes => createHash('sha256').update(bytes).digest('hex');
const has = (value, key) => Object.hasOwn(value ?? {}, key);
const list = value => Array.isArray(value) ? value : [];
const plural = { project: 'projects', task: 'tasks', user: 'users', team: 'teams', section: 'sections', story: 'stories', attachment: 'attachments', tag: 'tags', custom_field: 'custom_fields', project_brief: 'briefs', status_update: 'status_updates', project_template: 'templates', task_template: 'templates' };
const families = [...new Set(Object.values(plural)), 'subtasks', 'dependencies', 'forms', 'rules', 'recurrence', 'historical_revisions', 'deleted_records', 'attachment_bytes'];

export function unwrap(document) {
  const payload = document?.response ?? document?.tool_result?.structuredContent ?? document?.structuredContent ?? document;
  return { payload, request: document?.request ?? null, isError: document?.tool_result?.isError === true || document?.isError === true || !!payload?.errors };
}

function inferredFamily(file, records, sourceTool) {
  const type = records.find(row => row?.resource_type)?.resource_type;
  if (plural[type]) return plural[type];
  if (/projects-page|project-detail/.test(file)) return 'projects';
  if (/task-list|task-detail|subtasks/.test(file)) return 'tasks';
  if (/attachment-list/.test(file) || sourceTool === 'asana_get_attachments') return 'attachments';
  if (/^users/.test(file)) return 'users';
  if (/^teams/.test(file)) return 'teams';
  return null;
}

function groupFor(family, request, file) {
  if (family === 'projects' && /projects-page/.test(file)) {
    const archived = request?.archived ?? (file.startsWith('active-') ? false : true);
    return `projects:archived=${archived}`;
  }
  if (family === 'tasks') return `tasks:parent=${request?.project ?? request?.parent ?? request?.task_id ?? 'unknown'}`;
  return `${family}:parent=${request?.parent ?? request?.project_id ?? request?.task_id ?? 'workspace'}`;
}

export function paginationSummary(pages) {
  if (!pages.length) return { status: 'missing', pages: 0 };
  const starts = pages.filter(page => page.request && !page.request.offset);
  const inferredStarts = pages.filter(page => !page.request && /(?:page-1|^teams\.json$)/.test(page.file));
  const first = starts[0] ?? inferredStarts[0];
  if (!first) return { status: 'unverified_start', pages: pages.length };
  const visited = new Set();
  let current = first;
  while (current) {
    if (visited.has(current.file)) return { status: 'cycle', pages: pages.length };
    visited.add(current.file);
    if (!current.hasNext) return { status: 'unverified_pagination', pages: pages.length };
    if (current.next === null) return {
      status: visited.size !== pages.length ? 'disconnected_pages' : starts.length !== 1 ? 'terminal_chain_unverified_start' : 'complete',
      pages: pages.length, traversed: visited.size,
    };
    if (!current.next?.offset) return { status: 'malformed_next_page', pages: pages.length };
    const matches = pages.filter(page => page.request?.offset === current.next.offset);
    if (matches.length !== 1) return { status: matches.length ? 'ambiguous_continuation' : 'missing_continuation', pages: pages.length };
    current = matches[0];
  }
  return { status: 'missing_continuation', pages: pages.length };
}

async function archiveFiles(root) {
  const files = [];
  async function walk(relative = '') {
    for (const item of await readdir(path.join(root, relative), { withFileTypes: true })) {
      const name = path.join(relative, item.name);
      if (item.isSymbolicLink()) throw new Error('Archive symlinks are unsupported.');
      if (item.isDirectory()) await walk(name);
      else if (item.isFile()) files.push(name.replaceAll(path.sep, '/'));
    }
  }
  await walk();
  return files.sort();
}

export async function inspectArchive(source) {
  const root = await realpath(source);
  const files = [], ids = Object.fromEntries(families.map(family => [family, new Set()]));
  const primaryIds = Object.fromEntries(families.map(family => [family, new Set()]));
  const nestedIds = Object.fromEntries(families.map(family => [family, new Set()]));
  const references = Object.fromEntries(['users', 'teams', 'projects', 'tasks', 'tags', 'custom_fields', 'dependencies'].map(family => [family, new Set()]));
  const nestedObservations = [];
  const occurrences = Object.fromEntries(families.map(family => [family, 0]));
  const lists = new Map(), workspaces = new Set(), membershipEdges = new Set(), taskPageEdges = new Set();
  const issues = [], expected = new Map(), sourceBindings = [];
  let plannedWorkspace = null, declarations = [], failedCaptures = 0;
  let provenance = {};
  const fileNames = await archiveFiles(root);
  if (fileNames.includes('capture-provenance.json')) {
    try {
      const sidecar = JSON.parse(await readFile(path.join(root, 'capture-provenance.json'), 'utf8'));
      if (sidecar.version !== 1 || sidecar.authority !== 'reviewed_coordinator_request_log' || !sidecar.captures || typeof sidecar.captures !== 'object' || Array.isArray(sidecar.captures)) throw new Error('Invalid sidecar');
      provenance = sidecar.captures;
    } catch { issues.push({ file: 'capture-provenance.json', code: 'invalid_capture_provenance' }); }
  }
  function record(family, row, nested = false) {
    if (!family || !row?.gid) return;
    ids[family]?.add(String(row.gid));
    (nested ? nestedIds : primaryIds)[family]?.add(String(row.gid));
    occurrences[family]++;
  }
  function reference(family, row) { if (row?.gid) references[family].add(String(row.gid)); }
  for (const file of fileNames) {
    const bytes = await readFile(path.join(root, file));
    const entry = { file, bytes: bytes.length, sha256: digest(bytes), format: file.endsWith('.json') ? 'json' : 'binary' };
    files.push(entry);
    if (!file.endsWith('.json')) continue;
    let document;
    try { document = JSON.parse(bytes.toString('utf8')); }
    catch { entry.parse_error = true; issues.push({ file, code: 'invalid_json' }); continue; }
    if (file === 'capture-provenance.json') continue;
    if (file === 'export-plan.json') {
      plannedWorkspace = document.workspace_gid ? String(document.workspace_gid) : null;
      for (const project of document.projects ?? []) if (project.gid && Number.isInteger(project.expected_memberships)) expected.set(String(project.gid), project.expected_memberships);
      declarations = Array.isArray(document.limitations) ? document.limitations : [];
    }
    const { payload, request: originalRequest, isError } = unwrap(document);
    let request = originalRequest;
    const originating = provenance[file];
    if (originating) {
      if (originating.sha256 !== entry.sha256 || !originating.request || typeof originating.request !== 'object' || Array.isArray(originating.request)) issues.push({ file, code: 'capture_provenance_mismatch' });
      else if (originalRequest && Object.entries(originating.request).some(([key, value]) => has(originalRequest, key) && JSON.stringify(originalRequest[key]) !== JSON.stringify(value))) issues.push({ file, code: 'capture_provenance_conflict' });
      else { request = { ...originating.request, ...originalRequest }; entry.request_origin = originalRequest ? 'capture_with_reviewed_sidecar' : 'reviewed_sidecar'; }
    }
    entry.captured_at = document.captured_at ?? null;
    entry.wrapper = document.response ? 'response' : document.tool_result ? 'tool_result' : 'direct';
    if (isError) {
      failedCaptures++;
      const status = Number(document.http_status ?? payload?.status ?? document.status);
      issues.push({ file, code: status === 403 ? 'inaccessible' : status === 402 ? 'plan_blocked' : status === 404 ? 'unresolved_object' : 'capture_error', http_status: Number.isFinite(status) ? status : null });
      continue;
    }
    if (file !== 'export-plan.json' && (document.tool_result || document.response || document.source_tool)
      && !has(payload, 'data')) {
      issues.push({ file, code: 'unrecognized_response_shape' });
      continue;
    }
    const rows = Array.isArray(payload?.data) ? payload.data : payload?.data?.gid ? [payload.data] : [];
    const family = inferredFamily(file, rows, document.source_tool);
    entry.family = family;
    entry.returned_records = rows.length;
    for (const row of rows) {
      if (!row || typeof row !== 'object' || Array.isArray(row) || !row.gid) { issues.push({ file, code: 'malformed_source_record' }); continue; }
      record(family, row);
      if (['projects', 'tasks'].includes(family) && row.gid) sourceBindings.push({ file, family, gid: String(row.gid), workspace_gid: row.workspace?.gid ? String(row.workspace.gid) : null });
      for (const workspace of list(row.workspaces)) if (workspace?.gid) workspaces.add(String(workspace.gid));
      if (row.workspace?.gid) workspaces.add(String(row.workspace.gid));
      for (const section of list(row.sections)) record('sections', section, true);
      for (const user of [row.assignee, row.created_by, row.completed_by, row.owner, ...list(row.followers), ...list(row.members)]) reference('users', user);
      reference('teams', row.team);
      for (const tag of list(row.tags)) reference('tags', tag);
      for (const field of list(row.custom_fields)) reference('custom_fields', field);
      for (const setting of list(row.custom_field_settings)) reference('custom_fields', setting?.custom_field);
      for (const project of list(row.projects)) reference('projects', project);
      reference('tasks', row.parent);
      for (const nestedField of ['comments', 'stories', 'subtasks', 'attachments']) {
        if (!has(row, nestedField)) continue;
        const values = row[nestedField];
        const observedFamily = nestedField === 'comments' ? 'stories' : nestedField;
        const limit = originating?.capture_family_limits?.[nestedField] ?? request?.[`${nestedField}_limit`]
          ?? (nestedField === 'comments' ? request?.comment_limit : null) ?? null;
        nestedObservations.push({ file, parent_gid: String(row.gid), family: observedFamily,
          returned_records: Array.isArray(values) ? values.length : null, coverage: 'observed_only', pagination: 'unverified',
          declared_limit: Number.isInteger(limit) && limit > 0 ? limit : null,
          limitation: values === null ? 'null_not_evidence_of_empty_family' : Array.isArray(values) ? 'nested_array_may_be_capped' : 'unrecognized_nested_shape' });
        if (Array.isArray(values)) for (const nested of values) {
          record(observedFamily, nested, true);
          if (nestedField === 'subtasks') reference('tasks', nested);
          reference('users', nested?.created_by);
        }
      }
      if (family === 'projects' && Number.isInteger(row.task_counts?.num_tasks)) expected.set(String(row.gid), row.task_counts.num_tasks);
      if (family !== 'tasks' || !row.gid) continue;
      if (request?.project) taskPageEdges.add(`${request.project}|${row.gid}`);
      for (const membership of list(row.memberships)) if (membership?.project?.gid) { membershipEdges.add(`${membership.project.gid}|${row.gid}`); reference('projects', membership.project); }
      for (const dependency of list(row.dependencies)) if (dependency?.gid) { references.dependencies.add(`${row.gid}|${dependency.gid}`); reference('tasks', dependency); }
      for (const dependent of list(row.dependents)) reference('tasks', dependent);
    }
    if (family && Array.isArray(payload?.data)) {
      const group = groupFor(family, request, file);
      if (!lists.has(group)) lists.set(group, []);
      lists.get(group).push({ file, request, hasNext: has(payload, 'next_page'), next: payload.next_page });
    }
  }
  const pagination = [...lists].map(([group, pages]) => ({ group, ...paginationSummary(pages) }));
  const observedByProject = new Map();
  for (const edge of taskPageEdges) {
    const project = edge.split('|')[0];
    observedByProject.set(project, (observedByProject.get(project) ?? 0) + 1);
  }
  const projectCoverage = [...expected].map(([gid, count]) => ({ project_gid: gid, expected_memberships: count, captured_memberships: observedByProject.get(gid) ?? 0, count_matches: count === (observedByProject.get(gid) ?? 0), pagination: pagination.find(group => group.group === `tasks:parent=${gid}`)?.status ?? 'missing' }));
  const resources = Object.fromEntries(families.map(family => [family, {
    distinct_records: ids[family].size,
    primary_snapshot_records: primaryIds[family].size,
    nested_observed_records: nestedIds[family].size,
    observed_occurrences: occurrences[family],
    coverage: ids[family].size ? 'partial' : 'missing_or_unverified',
    inaccessible_count: null, unsupported_count: null,
  }]));
  const mismatches = sourceBindings.filter(record => record.workspace_gid && plannedWorkspace && record.workspace_gid !== plannedWorkspace);
  issues.push(...mismatches.map(record => ({ file: record.file, code: 'source_workspace_mismatch', source_gid: record.gid })));
  return { manifest_version: 1, created_at: new Date().toISOString(), source_read_only: true, files,
    report: { status: 'partial_source_archive', selected_workspace_gid: plannedWorkspace, observed_workspace_gids: [...workspaces].sort(), workspace_selection_observed: plannedWorkspace ? workspaces.has(plannedWorkspace) : false,
      workspace_reference_observed: plannedWorkspace ? workspaces.has(plannedWorkspace) : false,
      source_binding: { status: mismatches.length ? 'mismatch' : sourceBindings.some(record => !record.workspace_gid) ? 'unverified_per_record' : sourceBindings.length && plannedWorkspace ? 'verified_explicit_records' : 'unverified',
        explicit_mismatches: mismatches.length, records_without_workspace: sourceBindings.filter(record => !record.workspace_gid).length,
        caveat: 'A user workspace reference does not establish workspace ownership of every exported object.' },
      resources, pagination, project_coverage: projectCoverage, unique_task_count: ids.tasks.size,
      inventory_references: Object.fromEntries(Object.entries(references).map(([family, values]) => [family, { distinct_references: values.size, coverage: 'references_only_not_full_snapshots' }])),
      nested_family_observations: nestedObservations,
      captured_project_task_memberships: taskPageEdges.size, referenced_project_task_memberships: membershipEdges.size,
      failed_capture_count: failedCaptures, issues, declared_limitations: declarations,
      completeness_warning: 'Hashes prove archived bytes only. Unknown permissions, fields, pagination, missing families and uncopied attachments prevent a complete-migration claim.' } };
}

export async function verifyManifest(source, manifest) {
  if (manifest.manifest_version !== 1 || !Array.isArray(manifest.files)) throw new Error('Unsupported manifest format.');
  const root = await realpath(source), actual = new Set(await archiveFiles(root));
  const failures = [], declared = new Set();
  for (const entry of manifest.files) {
    if (typeof entry.file !== 'string' || path.isAbsolute(entry.file) || entry.file.includes('\\') || entry.file.split('/').some(part => part === '..' || part === '' || part.includes(':'))) throw new Error('Unsafe manifest file path.');
    if (declared.has(entry.file)) throw new Error('Duplicate manifest file path.');
    declared.add(entry.file);
    if (!actual.has(entry.file)) { failures.push({ file: entry.file, code: 'missing_file' }); continue; }
    const bytes = await readFile(path.join(root, entry.file));
    if (bytes.length !== entry.bytes || digest(bytes) !== entry.sha256) failures.push({ file: entry.file, code: 'integrity_mismatch' });
  }
  const unsealed = [...actual].filter(file => !declared.has(file));
  const subsetVerified = failures.length === 0;
  return { verified: subsetVerified && unsealed.length === 0, snapshot_verified: subsetVerified && unsealed.length === 0,
    verified_sealed_subset: subsetVerified, sealed_files: declared.size, failures, unsealed_files: unsealed, checked_at: new Date().toISOString() };
}

export async function outputIdentity(target, platform = process.platform) {
  const resolved = path.join(await realpath(path.dirname(target)), path.basename(target));
  return { resolved, key: platform === 'win32' ? resolved.toLowerCase() : resolved };
}

async function outsideGit(target) {
  let current = path.dirname(target);
  while (true) {
    try { await access(path.join(current, '.git')); throw new Error('Private archive output must be outside a Git checkout.'); }
    catch (error) { if (error.code !== 'ENOENT') throw error; }
    const parent = path.dirname(current);
    if (parent === current) return;
    current = parent;
  }
}

async function privateWrite(target, value, exclusive = false) {
  const actualParent = await realpath(path.dirname(target));
  const actualTarget = path.join(actualParent, path.basename(target));
  await outsideGit(actualTarget);
  try { if ((await lstat(actualTarget)).isSymbolicLink()) throw new Error('Output symlinks are unsupported.'); }
  catch (error) { if (error.code !== 'ENOENT') throw error; }
  if (exclusive) return writeFile(actualTarget, JSON.stringify(value, null, 2) + '\n', { flag: 'wx', mode: 0o600 });
  const temporary = `${actualTarget}.${randomUUID()}.tmp`;
  await writeFile(temporary, JSON.stringify(value, null, 2) + '\n', { flag: 'wx', mode: 0o600 });
  await rename(temporary, actualTarget);
}

export async function main(args) {
  const [mode, ...options] = args;
  const opts = {};
  for (let i = 0; i < options.length; i += 2) {
    if (!['--source', '--manifest', '--report'].includes(options[i]) || !options[i + 1] || options[i + 1].startsWith('--')) throw new Error('Usage: seal|verify --source DIR --manifest FILE --report FILE');
    opts[options[i].slice(2)] = path.resolve(options[i + 1]);
  }
  if (!['seal', 'verify'].includes(mode) || !opts.source || !opts.manifest || !opts.report) throw new Error('Usage: seal|verify --source DIR --manifest FILE --report FILE');
  const root = await realpath(opts.source);
  await outsideGit(path.join(root, '_archive_boundary_check'));
  const outputPaths = await Promise.all([opts.manifest, opts.report].map(target => outputIdentity(target)));
  if (outputPaths[0].key === outputPaths[1].key) throw new Error('Manifest and report must use different files.');
  for (const { resolved } of outputPaths) {
    const relative = path.relative(root, resolved);
    if (!relative || (!relative.startsWith(`..${path.sep}`) && relative !== '..' && !path.isAbsolute(relative))) throw new Error('Manifest/report must be outside the source archive.');
  }
  if (mode === 'seal') {
    const manifest = await inspectArchive(root);
    await privateWrite(opts.manifest, manifest, true);
    await privateWrite(opts.report, manifest.report);
    process.stdout.write(JSON.stringify({ status: 'sealed_partial_archive', files: manifest.files.length, unique_tasks: manifest.report.unique_task_count }) + '\n');
    return 0;
  }
  const verification = await verifyManifest(root, JSON.parse(await readFile(opts.manifest, 'utf8')));
  await privateWrite(opts.report, verification);
  process.stdout.write(JSON.stringify({ status: verification.verified ? 'integrity_verified' : 'integrity_failed', sealed_files: verification.sealed_files, failed_files: verification.failures.length, unsealed_files: verification.unsealed_files.length }) + '\n');
  return verification.verified ? 0 : 2;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  main(process.argv.slice(2)).then(code => { process.exitCode = code; }).catch(() => {
    // Provider/archive content and filesystem errors can contain private source data.
    process.stderr.write('Source archive operation failed; check arguments, permissions and existing manifest. No source content was printed.\n');
    process.exitCode = 1;
  });
}
