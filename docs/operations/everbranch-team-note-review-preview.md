# Everbranch Team note review preview

Date: 2026-10-11. Public technical runbook for a local Phase 1 preservation/review tool, not a Team application schema, migration engine, native UI, AI integration or cutover. The restricted coordinator report contains actual source counts, account/plan details and private preview coverage.

`scripts/everbranch-team/preview-note-fields.mjs` reads selected preserved project/task JSON files and emits original snapshots plus pending suggestions into an explicitly selected access-controlled directory. It makes no network, server, database, Asana or AI calls and never writes source files. `--review-only` is mandatory. The coordinator must resolve source scope/permissions before choosing files.

## Run

```powershell
node --test scripts/everbranch-team/preview-note-fields.test.mjs
node scripts/everbranch-team/preview-note-fields.mjs --review-only --source '<private-project-json>' --source '<private-task-list-json>' --output '<restricted-preview-directory>'
```

Do not use a public website directory, source-control directory, unrestricted shared folder or tenant-accessible organization-wide directory for private source output. This local tool inherits its Windows parent ACL; Node creation modes alone do not establish Windows authorization. The coordinator-approved discovery directory already has a restricted parent ACL. On POSIX, newly created directories/files use owner-only modes. Existing directory permissions are the operator's responsibility.

Output anywhere under this application source tree or under any selected source file's directory is rejected before artifacts or directories are written. Linked source/output paths (including detectable Windows junctions) and preexisting artifact links are rejected. Other Git checkout outputs are rejected by default. The approved discovery output is outside the application but inside the outer Jarvis Git checkout and ignored through `everbranch-team/.gitignore`. For such an explicitly approved private destination, add `--allow-ignored-private-output`; the tool also verifies `git check-ignore` at every Git ancestor. This acknowledgement does not establish or replace the directory's private ACL.

Standard output contains only object/suggestion/artifact counts and zero applied changes. Errors omit source text. Original snapshots and review files include private source paths, identities and notes; review them only within the same restricted access boundary as their source objects.

## Preserved evidence and interpretation

- Known envelopes: raw object/array, `response.data`, `tool_result.structuredContent.data`, `structuredContent.data`, and `data`, including nested known envelopes. Unknown/error envelopes fail before artifact emission. Source GIDs must remain exact strings; blank task names do not discard a task.
- Each `.original.json` retains every present `notes`/`html_notes` value unchanged, including nulls, rich text, whitespace and line endings, plus SHA-256 hashes of UTF-8 string bytes. Missing fields remain missing. The raw archive file remains the complete object preservation record.
- Each `.review.json` records the absolute source file, source-file SHA-256, object GID, original snapshot reference and pending before/after suggestions. It does not create accepted fields. Source-content and GID hashes determine filenames; a changed archive version produces separate artifacts. Repeated identical previews keep byte-identical output; modified existing artifacts cause a failure instead of replacement.
- Each suggestion links to `field: notes`, the exact passage, its SHA-256 and `[start,end)` character offsets. Offsets are JavaScript UTF-16 code units into the unchanged plain notes string; they are not UTF-8 byte offsets. `notes.slice(start,end)` must exactly reproduce the passage, including labels and whitespace.
- Explicit line-start labels are `LAST CONTACT DATE`, `LAST ORDER DATE`, `FIRST ORDER DATE`, `CONTACT`, `PRIMARY CONTACT`, `EMAIL`, `PHONE`, `ADDRESS`, `CUSTOM LABELS`, `BARCODES`, `PAYMENT METHOD`, and `PERSONAL INFO`. A label may use a colon/equal separator or occupy its own line, optionally after a simple list bullet. The value is the original block up to the next recognized label; multiline context always needs review.
- Empty/whitespace values become a null candidate; literal zero remains text `"0"`. Every nonempty candidate remains unparsed text. Dotted, two-digit, narrative and even apparently clear dates require explicit review. No date/contact/number/currency validation or inferred value occurs.
- Repeated labels stay separate and are flagged. Different project/task objects remain separate; the tool does not choose the newest passage, merge contacts or replace historical facts. Unlabeled narrative is preserved without extracted suggestions. HTML-only notes require manual review; HTML is preserved as inert data rather than rendered or parsed.

## Private preview verification

Coordinator-approved private project/task snapshots were previewed into the restricted discovery `note-preview` directory, outside the application repository. Source-file hashes, unchanged original note values and passage hashes/offsets were verified. Blank-name tasks are retained. Actual source/preview counts, source IDs, customer/account identities and note values remain only in the restricted coordinator report and private artifacts. The tool applies no changes.

Historical project evidence and much newer task evidence remain independent. Their date/contact proposals are review items, not a selected current value. No representative project has been imported into the application. This does not prove that all comments, attachment bytes, source revisions or source relationships have been exported or that complete history has been preserved.

The coordinator reports 32 passing synthetic tests in the combined Phase 1 suite. The note-preview tests cover wrapper/blank-name/GID handling; explicit labels and null-versus-zero; separate historical/current/repeated-date evidence; Unicode/CRLF offsets and unchanged untrusted HTML; no narrative inference and missing/null/HTML-only distinctions; opt-in/counts-only/unchanged-source/idempotent output; fail-before-emission plus immutable-artifact tamper rejection; source/application overlap refusal; outer Git explicit acknowledgement and verified ignored path; and linked source/output/artifact refusal.

## Limits

The tool has no application tenant/object authorization surface: access is the selected private source/output directory's ACL. It cannot be exposed as a customer API without the Team permission contract. It does not identify cross-object conflicts automatically, approve facts, provide undo of accepted application mappings, generate native activity, or run AI. Label matching is a conservative deterministic proposal aid; unknown labels or unlabeled text require manual review. Keep the full original beside every future accepted field and obtain administrator review before a transformation.
