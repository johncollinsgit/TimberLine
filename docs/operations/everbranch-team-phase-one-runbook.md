# Everbranch Team phase-one preservation runbook

Date: 2026-10-11. This runbook covers the implemented local source tools and the setup needed to finish source preservation. It does not authorize deployment, source mutations, live billing/AI, invitations or cutover.

## Current delivery boundary

The coordinator has frozen and sealed a restricted partial source capture through the development connector and verified a ZIP restore. The private manifest/report is the authority for actual counts and coverage; full graph/history/file preservation remains incomplete. A connector result is evidence, not a production authorization grant.

Implemented foundations:

- `scripts/everbranch-team/verify-source-export.mjs`: raw-file SHA-256/size inventory, wrapper parsing, pagination continuity, source-binding/coverage limitations, sealed-file verification and detection of extra unsealed files. A complete integrity result proves the frozen local files match their manifest; it does not establish a complete Asana export.
- `scripts/everbranch-team/asana-preservation-client.mjs`: source-only injected client, fixed Asana GET endpoints for project tasks and task subtasks/stories/attachment metadata, tenant/connection/workspace/active verified owner-admin envelope, actor-bound checkpoint identity, revalidation before each request, bounded retries/cancellation, raw capture before checkpoint and sanitized gaps. It has **no CLI, live credential lookup, Laravel route, queue worker, persistent sink, OAuth refresh integration, attachment-byte copier or destination importer**.
- `scripts/everbranch-team/preview-note-fields.mjs`: explicit local review-only note suggestions, immutable original note/HTML snapshots, source and passage hashes, UTF-16 offsets, unparsed values, null/zero distinction and counts-only output. It is **not an application schema, accepted transformation, AI feature or native Team UI**.
- `scripts/everbranch-team/Test-PrivateArchiveAccess.ps1`: read-only recursive Windows ACL check rejecting unexpected owners/allow grants and reparse points. Node `mode: 0600` does not establish Windows ACLs.

No complete migrated vertical slice is asserted. No attachment bytes were copied by these foundations. Comments/subtasks observed nested in connector results are partial observations, not proof of all endpoint pages or historical revisions. Modern Forestry's current account plan/cutoff and production Asana grant scopes remain unverified unless separately evidenced by the coordinator.

## Production Asana authorization prerequisites

The existing application already has `AsanaWorkflowConnectionService`, encrypted `IntegrationConnection` records and `ProviderAccessTokenService`. Source defaults request `projects:read tasks:read workspaces:read`. The current callback is the canonical landlord URL plus `/marketing/providers-integrations/workflow-automations/asana/callback`; confirm the effective production host/config without exposing credentials.

Before using production tenant authorization for preservation:

1. Resolve the actual Modern Forestry tenant by reviewed ID/slug and accessible source workspace GID. Resolve an active, verified owner/admin and the exact tenant-owned Asana connection. Record the reviewed object/private-project scope and roster; no name-only identity merge or automatic membership creation.
2. Have the Asana developer-app owner inspect the registered application, exact redirect URL allowlist, requested scopes, distribution and any approval/review requirements. Record non-secret application/registration evidence. The local source audit does not prove app registration or distribution readiness. Consult [official OAuth setup](https://developers.asana.com/docs/oauth) and [scope definitions](https://developers.asana.com/docs/oauth-scopes).
3. Compare the required inventory with the existing grant. Read-scope candidates are `workspaces:read`, `projects:read`, `tasks:read`, `users:read`, `teams:read`, `team_memberships:read`, `stories:read`, `attachments:read`, `tags:read` and `custom_fields:read`; request template scopes only for used templates. Do not assume every endpoint has a matching fine-grained scope. Sections, briefs and status endpoints require an actual authorized probe or documented fallback; broad Full permissions requires specific owner review, never automatic escalation. See `docs/architecture/everbranch-team-migration-contract.md`.
4. Obtain required owner approval before creating credentials, registering/changing redirects or expanding access. The owner enters the client secret through the existing secure server configuration/Forge environment mechanism; never paste keys, secrets, PATs, refresh tokens, browser cookies or sessions into chat, source, browser code, logs or reports. Shared OAuth application credentials are permissible; each tenant's customer grant remains separate.
5. Complete a browser authorization flow as that customer. Asana authorization is distinct from signing into Everbranch. Reuse S256 PKCE and one-use state, but first integrate Team-specific active membership/role/entitlement checks into start/callback/resume; the legacy workflow callback alone is not the Team permission contract. Verify the returned actor/connection binding and a bounded selected-workspace read. Credential presence is not connection health.
6. Resolve the grant exclusively from the chosen tenant-owned `IntegrationConnection`. Never call the legacy workflow `effectiveCredentials()` fallback for a Team import: it can supply global Asana PAT/access/refresh tokens. A missing, disconnected, expired or inaccessible grant stops the run with a visible gap. After disconnect, queued reads must halt; retained private evidence remains available to authorized reviewers.

The source client currently expects the caller to supply a token and a trusted revalidation callback. It cannot verify a bearer token's real workspace scope by itself. Envelope/parent claims and checkpoints must come from trusted backend records, never directly from customer request JSON. Its checkpoint hash binds content; it is not an authentication signature.

## Remaining implementation before a complete preservation run

The coordinator must integrate an authorized backend adapter and durable private sink, then test them before live reads:

- Derive actor/tenant/module/import/project permissions server-side. Revalidate cancellation, membership, selected grant and object visibility before every request and resume. Keep the Team rollout and import gates fail-closed.
- Preserve the exact OAuth client/refresh-token pair. Serialize refresh per connection, handle rotation, sanitize errors and share provider rate budgets with existing workflows. The injected client reports 401 reconnect-required; it does not perform refresh. Do not disturb existing Asana-to-calendar workflow credentials/cursors when adding migration authorization.
- Implement a fixed-origin HTTPS GET transport with explicit redirect policy and bounded responses/timeouts. Implement transactional/durable raw capture and checkpoint commits, private ACL/encryption, retention, restore and concurrency leases. An injected sink promise alone is not production durability evidence.
- Enumerate active/completed/archived projects and all accessible sections, task memberships, recursively nested subtasks, stories, fields, dependencies, contributors and used auxiliary objects. Follow every opaque page continuation; expired offsets restart with source-ID/version deduplication. `observedRecordOccurrences` includes repeated observations and is not a unique record count.
- Implement immediate authorized attachment downloads into durable private storage with MIME/size/checksum evidence. Preserve third-party file links and permission requirements when copy is unavailable. Metadata and a signed/expiring URL are not copied bytes. [Attachment metadata](https://developers.asana.com/reference/getattachment), [attachment listing](https://developers.asana.com/reference/getattachmentsforobject).
- Record unsupported/inaccessible/plan-blocked/failed objects and manual preservation routes separately. Current API reads cannot promise deleted records or every historical revision. [Pagination](https://developers.asana.com/docs/pagination), [rate limits](https://developers.asana.com/docs/rate-limits), [stories](https://developers.asana.com/reference/getstoriesfortask).

Production raw evidence must use the future tenant/object access contract, with access at least as restrictive as the source. The current local folder ACL is a single-operator preservation boundary; it does not implement guest/private-project access for customers.

## Local freeze, seal and restore

Use the actual reviewed private paths recorded in the restricted coordinator report. Keep source, manifest/report, review previews and restored copy as separate directories/files. The final bundle root must be an ACL-restricted directory outside **every** Git checkout, with operator/SYSTEM-only access. The private report records the completed copy/seal/ZIP/restore status and exact location. Original discovery captures remain separately retained under an ignored restricted discovery parent inside the outer checkout. Copy frozen source to the final private bundle; do not weaken the verifier's outside-Git guard or put private records in application source.

1. Finish the chosen capture scope, stop capture writes, record the freeze time and known omissions, then freeze `source`. Do not run note previews or add reports inside this sealed source directory.
2. Run the synthetic source-tool tests. They do not make provider calls:

   ```powershell
   node --test scripts/everbranch-team/asana-preservation-client.test.mjs scripts/everbranch-team/verify-source-export.test.mjs scripts/everbranch-team/preview-note-fields.test.mjs
   ```

3. Verify private access on the entire discovery parent, covering source and sibling artifacts:

   ```powershell
   & './scripts/everbranch-team/Test-PrivateArchiveAccess.ps1' -LiteralPath '<absolute-private-discovery-parent>'
   ```

   Expect `verified: true`, zero unexpected owners/allow grants and zero reparse points. Retain count-only evidence and independently inspect `icacls` if needed. Re-run after adding final artifacts/restored copy. Do not print raw source files for verification.

4. Use fresh manifest/report filenames outside `source`; sealing refuses manifest overwrite. The standard outside-every-Git form is:

   ```powershell
   node scripts/everbranch-team/verify-source-export.mjs seal --source '<absolute-frozen-source>' --manifest '<private-parent>/source-manifest.json' --report '<private-parent>/source-coverage.json'
   node scripts/everbranch-team/verify-source-export.mjs verify --source '<absolute-frozen-source>' --manifest '<private-parent>/source-manifest.json' --report '<private-parent>/source-integrity.json'
   ```

   These verifier commands require paths outside every Git checkout. Retain the manifest hash in separate trusted operator evidence. The coverage report remains `partial_source_archive` even when byte integrity passes.

5. Create the ZIP backup only from the frozen source and retain it under the same restricted final bundle parent. Restore the sealed files from that backup into a **new** restricted sibling directory; refuse an existing destination. A direct directory-copy check can precede the backup restore, but is not ZIP round-trip evidence. The commands below demonstrate the direct-copy integrity check; for the actual ZIP round trip, replace `Copy-Item` with the coordinator's restricted archive extraction into the new directory:

   ```powershell
   $taskFrozenSource = '<absolute-frozen-source>'
   $taskRestoreDirectory = '<absolute-new-private-restore-directory>'
   if (Test-Path -LiteralPath $taskRestoreDirectory) { throw 'Choose a new restore directory.' }
   Copy-Item -LiteralPath $taskFrozenSource -Destination $taskRestoreDirectory -Recurse
   node scripts/everbranch-team/verify-source-export.mjs verify --source $taskRestoreDirectory --manifest '<private-parent>/source-manifest.json' --report '<private-parent>/restore-integrity.json'
   & './scripts/everbranch-team/Test-PrivateArchiveAccess.ps1' -LiteralPath '<absolute-private-discovery-parent>'
   ```

   Expect both `snapshot_verified` and `verified_sealed_subset` true, no missing/mismatched/unsealed files. Any extra file fails full snapshot verification. Restore proves the local sealed bytes can be recovered, not that every accessible Asana object was captured or an off-device encrypted backup exists.

6. Run note review only on reviewed source files and write to a restricted sibling preview directory. Source/Git/link guards apply. When the outer checkout is verified ignored, the explicit note acknowledgment is required:

   ```powershell
   node scripts/everbranch-team/preview-note-fields.mjs --review-only --allow-ignored-private-output --source '<private-source-project-json>' --source '<private-source-task-json>' --output '<private-sibling-note-preview-directory>'
   ```

   Omit the ignored-private option outside Git. Preserve source/original hashes, verify each suggestion's passage against `[start,end)` UTF-16 offsets, and review ambiguous/repeated dates/contacts. Candidates remain unparsed text; empty candidate is null, literal zero remains text. Nothing is applied to Asana or application data.

## Approval and deployment hold

The next reviewable delivery is complete permitted preservation coverage with explained gaps, followed by a real end-to-end migrated project in staging. The current tools do not supply a Team schema, native daily UI, destination import, guest/private-project policy, complete reconciliation or verified cutover.

Required owner decisions are narrowly scoped: developer-app/credential/redirect/scope approval when needed; roster/invitation approval before sending; Team pricing before live charges; production AI budget/data-handling approval before live usage; representative project/permissions review and deployment/cutover approval after staging reconciliation. No team-wide invitation, source deletion, Asana cancellation, unplanned two-way sync or live charge follows from capture permission.

No production deployment is required for the local phase-one tools. Keep application/schema/feature/billing/AI activation on hold until the coordinator's reviewed vertical slice and required security/migration/build gates pass. Any eventual release uses existing GitHub authority, Forge atomic releases and `/ready`; it must include a rollback plan that preserves evidence and keeps Asana available.
