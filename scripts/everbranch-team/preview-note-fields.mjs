import { createHash } from 'node:crypto';
import { mkdir, readFile, realpath, stat, lstat, writeFile } from 'node:fs/promises';
import { resolve, join, dirname, relative, isAbsolute, sep } from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

export const LABELS = Object.freeze([
  'LAST CONTACT DATE', 'LAST ORDER DATE', 'FIRST ORDER DATE', 'PRIMARY CONTACT',
  'PAYMENT METHOD', 'PERSONAL INFO', 'CUSTOM LABELS', 'BARCODES',
  'CONTACT', 'EMAIL', 'PHONE', 'ADDRESS',
]);
export const sha256 = value => createHash('sha256').update(value).digest('hex');
const labelPattern = new RegExp(`^[\\t ]*(?:[-*]\\s+)?(${LABELS.join('|')})(?:[\\t ]*[:=][\\t ]*|[\\t ]*$)`, 'gmi');
const applicationRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/** Unwrap only known preservation envelopes; never inspect unrelated nested objects. */
export function sourceObjects(value) {
  for (let depth = 0; depth < 10; depth += 1) {
    if (Array.isArray(value)) return value;
    if (!value || typeof value !== 'object') throw new Error('Invalid source envelope');
    if (Object.hasOwn(value, 'gid')) return [value];
    if (value.tool_result?.structuredContent?.data !== undefined) value = value.tool_result.structuredContent.data;
    else if (value.response?.data !== undefined) value = value.response.data;
    else if (value.structuredContent?.data !== undefined) value = value.structuredContent.data;
    else if (value.data !== undefined) value = value.data;
    else throw new Error('Unsupported source envelope');
  }
  throw new Error('Source envelope depth exceeded');
}

/** Offsets are UTF-16 code units into the unchanged notes string (String.slice). */
export function extractSuggestions(notes, source) {
  if (typeof notes !== 'string' || notes.length === 0) return [];
  const markers = [...notes.matchAll(new RegExp(labelPattern.source, labelPattern.flags))];
  const occurrences = new Map();
  for (const marker of markers) {
    const label = marker[1].toUpperCase();
    occurrences.set(label, (occurrences.get(label) ?? 0) + 1);
  }
  return markers.map((marker, index) => {
    const label = marker[1].toUpperCase();
    const start = marker.index;
    const end = markers[index + 1]?.index ?? notes.length;
    const passage = notes.slice(start, end);
    const rawValue = notes.slice(start + marker[0].length, end);
    const value = rawValue.trim() === '' ? null : rawValue.trim();
    const reasons = ['explicit_review_required'];
    if (label.endsWith(' DATE')) reasons.push('date_not_parsed_or_inferred');
    if (value === null) reasons.push('missing_value');
    if (occurrences.get(label) > 1) reasons.push('repeated_label_same_object');
    if (value !== null && /\r?\n/.test(value)) reasons.push('multiline_context_requires_review');
    return {
      status: 'pending_review', label, proposed_field: label.toLowerCase().replaceAll(' ', '_'),
      candidate_value: value, candidate_value_type: value === null ? 'null' : 'unparsed_text',
      applied: false, reasons,
      source: { ...source, field: 'notes', passage, start, end, offset_unit: 'utf16_code_units', passage_sha256: sha256(passage) },
    };
  });
}

export function previewSource(rawBytes, sourceFile) {
  const fileHash = sha256(rawBytes);
  const objects = sourceObjects(JSON.parse(rawBytes.toString('utf8')));
  const seen = new Set();
  return objects.map(object => {
    if (!object || typeof object !== 'object' || typeof object.gid !== 'string' || !object.gid.trim()) {
      throw new Error('Every object requires its exact string source gid');
    }
    if (seen.has(object.gid)) throw new Error('Duplicate source gid in one input');
    seen.add(object.gid);
    const originals = {};
    for (const field of ['notes', 'html_notes']) {
      if (Object.hasOwn(object, field)) {
        if (object[field] !== null && typeof object[field] !== 'string') throw new Error('Invalid note field type');
        originals[field] = {
          value: object[field], sha256: object[field] === null ? null : sha256(object[field]),
          hash_encoding: 'utf8',
        };
      }
    }
    const source = { file: sourceFile, file_sha256: fileHash, object_gid: object.gid };
    const suggestions = extractSuggestions(object.notes, source);
    const token = `${fileHash}-${sha256(object.gid)}`;
    const originalFilename = `${token}.original.json`;
    return {
      originalFilename,
      previewFilename: `${token}.review.json`,
      original: { format_version: 1, source, original_fields: originals },
      preview: {
        format_version: 1, mode: 'review_only', applied: false, source,
        original_snapshot: originalFilename,
        before: { notes_sha256: originals.notes?.sha256 ?? null, html_notes_sha256: originals.html_notes?.sha256 ?? null },
        after: { proposed_changes_only: suggestions },
        coverage: typeof object.notes === 'string' ? 'plain_notes_review' :
          typeof object.html_notes === 'string' ? 'html_only_manual_review' : 'notes_not_available',
        warnings: ['No values have been parsed, verified, merged, or applied.', 'Source objects remain independent; cross-object conflicts require administrator review.'],
      },
    };
  });
}

async function optionalLstat(path) {
  try { return await lstat(path); } catch (error) {
    if (error.code === 'ENOENT') return null;
    throw error;
  }
}

async function rejectLinkedPath(path) {
  let current = resolve(path);
  while (true) {
    if ((await optionalLstat(current))?.isSymbolicLink()) throw new Error('Linked source/output paths are forbidden');
    const parent = dirname(current);
    if (parent === current) break;
    current = parent;
  }
}

async function rejectGitOutput(path, allowIgnoredPrivateOutput) {
  if (isWithin(applicationRoot, path)) throw new Error('Application source-tree output is forbidden');
  let current = resolve(path);
  while (true) {
    if (await optionalLstat(join(current, '.git'))) {
      if (!allowIgnoredPrivateOutput) throw new Error('Git checkout output requires explicit ignored-private acknowledgement');
      const ignored = spawnSync('git', ['check-ignore', '--no-index', '--quiet', '--', resolve(path)], { cwd: current, windowsHide: true });
      if (ignored.status !== 0) throw new Error('Git output is not verified ignored');
    }
    const parent = dirname(current);
    if (parent === current) break;
    current = parent;
  }
}

function isWithin(parent, path) {
  const normalize = value => process.platform === 'win32' ? resolve(value).toLowerCase() : resolve(value);
  const child = relative(normalize(parent), normalize(path));
  return child === '' || (!isAbsolute(child) && child !== '..' && !child.startsWith(`..${sep}`));
}

async function validateExistingArtifact(path, bytes) {
  const info = await optionalLstat(path);
  if (!info) return;
  if (info.isSymbolicLink() || !info.isFile()) throw new Error('Linked/non-file output artifacts are forbidden');
  if (await readFile(path, 'utf8') !== bytes) throw new Error('Existing artifact differs');
}

async function writeImmutable(path, bytes) {
  try {
    await writeFile(path, bytes, { flag: 'wx', mode: 0o600 });
  } catch (error) {
    if (error.code !== 'EEXIST') throw error;
    await validateExistingArtifact(path, bytes);
  }
}

export async function writePreviews({ sources, output, reviewOnly, allowIgnoredPrivateOutput = false }) {
  if (reviewOnly !== true || !sources?.length || !output) throw new Error('Explicit review-only inputs required');
  // Caller must select an access-controlled directory; Windows inherits its parent ACL.
  // POSIX creation modes restrict newly created directories/files to the owner.
  const requestedOutput = resolve(output);
  await rejectLinkedPath(requestedOutput);
  await rejectGitOutput(requestedOutput, allowIgnoredPrivateOutput);
  const sourcePaths = [];
  for (const source of sources) {
    await rejectLinkedPath(source);
    const sourceFile = await realpath(resolve(source));
    if (isWithin(dirname(sourceFile), requestedOutput)) throw new Error('Source archive output overlap is forbidden');
    if (!sourcePaths.includes(sourceFile)) sourcePaths.push(sourceFile);
  }
  const prepared = [];
  for (const sourceFile of sourcePaths) {
    if ((await stat(sourceFile)).size > 64 * 1024 * 1024) throw new Error('Source file exceeds bounded preview limit');
    prepared.push(...previewSource(await readFile(sourceFile), sourceFile));
  }
  // Validate every input and existing output artifact before any directory/artifact write.
  const artifacts = [];
  for (const item of prepared) {
    for (const [filename, value] of [[item.originalFilename, item.original], [item.previewFilename, item.preview]]) {
      const target = join(requestedOutput, filename);
      if (sourcePaths.includes(target)) throw new Error('Output would overlap source');
      const bytes = `${JSON.stringify(value, null, 2)}\n`;
      await validateExistingArtifact(target, bytes);
      artifacts.push([target, bytes]);
    }
  }
  await mkdir(requestedOutput, { recursive: true, mode: 0o700 });
  await rejectLinkedPath(requestedOutput);
  await rejectGitOutput(requestedOutput, allowIgnoredPrivateOutput);
  for (const [target, bytes] of artifacts) await writeImmutable(target, bytes);
  return {
    source_files: sourcePaths.length, source_objects: prepared.length,
    suggestions: prepared.reduce((count, item) => count + item.preview.after.proposed_changes_only.length, 0),
    original_snapshots: prepared.length, review_previews: prepared.length, applied_changes: 0,
  };
}

function argumentsFrom(argv) {
  const options = { sources: [], reviewOnly: false, output: null, allowIgnoredPrivateOutput: false };
  for (let index = 0; index < argv.length; index += 1) {
    const flag = argv[index];
    if (flag === '--review-only') options.reviewOnly = true;
    else if (flag === '--allow-ignored-private-output') options.allowIgnoredPrivateOutput = true;
    else if (flag === '--source' && argv[index + 1] && !argv[index + 1].startsWith('--')) options.sources.push(argv[++index]);
    else if (flag === '--output' && argv[index + 1] && !argv[index + 1].startsWith('--')) options.output = argv[++index];
    else throw new Error('Invalid arguments');
  }
  return options;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    const counts = await writePreviews(argumentsFrom(process.argv.slice(2)));
    process.stdout.write(`${JSON.stringify(counts)}\n`);
  } catch {
    process.stderr.write('Note preview failed; no source records were changed.\n');
    process.exitCode = 1;
  }
}
