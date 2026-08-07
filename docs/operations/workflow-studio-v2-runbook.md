# Workflow Studio v2 Operations Runbook

## Purpose

This is the operating contract for Everbranch Workflow Studio schema v2. It
covers the builder API, executable component catalog, scheduler and queue
behavior, run-item recovery, schema-v1 compatibility, tenant rollout, rollback,
and the production smoke test.

Workflow Studio is a shared, tenant-neutral Branch. Modern Forestry is the first
allowlisted workspace, not a product-specific runtime.

## Access and rollout gates

The Workflows, Runs, Connections, and Templates navigation requires:

- an authenticated user with the `admin`, `manager`, or
  `marketing_manager` role;
- active membership in the resolved tenant;
- the tenant's existing `workflow_automations` module access.

Opening or mutating a schema-v2 draft, loading the component catalog, testing,
publishing, resuming, retrying, scheduling, or executing v2 work additionally
requires the schema-v2 feature gate for that tenant. Runtime checks re-resolve
both the feature gate and module entitlement; a queued job cannot rely on the
access state that existed when it was dispatched.

The v2 gate is configured by:

```dotenv
AUTOMATION_WORKFLOWS_V2_ENABLED=true
AUTOMATION_WORKFLOWS_V2_TENANT_IDS=1
AUTOMATION_WORKFLOWS_POLL_INTERVAL_MINUTES=10
```

`AUTOMATION_WORKFLOWS_V2_TENANT_IDS` is an explicit comma-separated allowlist.
The checked-in default is tenant `1`. An empty allowlist means every entitled
tenant, so production must not make it empty during the pilot.

The route/module gate and the v2 allowlist are independent. A tenant must pass
both. Never broaden the allowlist merely to diagnose a single workspace.

An entitled tenant outside the v2 allowlist keeps the compatible schema-v1
template and builder screens; those links must never enter a v2 service and
must not return a server error. If a workspace that already owns a schema-v2
draft is removed from the allowlist, its workflow screen becomes read-only
with pause and history access until rollout is restored. Studio JSON endpoints
return HTTP 403 while the workspace is outside the allowlist.

## Studio and definition contract

`GET /workflows/new` renders an unsaved canvas. It must not create a database
row. The Studio opens the step picker immediately; selecting the first trigger
sends the create request and replaces the browser URL with
`/workflows/{workflow}`.

A canonical saved definition has this shape:

```json
{
  "schema_version": 2,
  "trigger": {
    "id": "stable ULID",
    "kind": "trigger",
    "component_key": "provider.component.event",
    "connection_id": 123,
    "config": {}
  },
  "steps": [],
  "settings": {
    "poll_interval_minutes": 10,
    "max_items_per_poll": 100
  }
}
```

The compiler, not the browser, is authoritative. It validates and canonicalizes:

- one trigger followed by ordered Action, Filter, Delay, or Paths steps;
- stable, unique ULIDs for steps and branches;
- component availability and kind;
- required configuration and publish readiness;
- tenant-owned connection IDs, provider match, connected state, and scopes;
- typed mappings to `trigger.output.*` or a reachable earlier
  `steps.<id>.output.*`;
- reachability and path order;
- no more than 100 total steps;
- no more than ten branches per Paths step;
- no more than three nested Paths levels;
- Paths as the terminal step in its sequence; and
- delays between one minute and 30 days.

Arbitrary expressions, executable code, handler class names supplied by the
client, loops, webhooks, and AI-generated execution are not valid definition
inputs.

Draft saves carry `draft_revision`. A successful save increments it and clears
test results made against the prior definition. A stale save returns HTTP 409
with the current and expected revision. Definition failures return HTTP 422
with field-addressable errors. The client must surface either result rather
than silently replacing server state.

Publishing creates a new immutable `automation_workflow_versions` row. Every
step must have a passing test for the exact current definition hash before
publish succeeds.

Testing a stable step ID without browser-held sample data polls one real
trigger sample, then dry-runs only earlier step IDs referenced by that step's
typed mappings. The resulting upstream outputs feed the selected test; unrelated
Paths branches are not executed. A missing source sample or unavailable mapped
output fails the test instead of producing a decorative pass.

Pause, resume, release-held, and discard-held responses update operational
status only in the browser. They must not replace the local workflow name,
definition, test state, or draft revision, because pausing remains available as
a safety action even while an autosave is pending.

## Executable launch catalog

`WorkflowComponentCatalog` is the sole registry. Its public payload includes
labels, provider identity, icon key, configuration fields, input/output schema,
connection requirements, required scopes, availability, and test policy. It
does not expose private handler classes.

### Triggers

- Everbranch — Customer created
- Everbranch — Job created
- Everbranch — Job status changed
- Everbranch — Task completed
- Asana — Task created or updated
- Shopify — Order created or updated
- Square — Order created or updated

### Actions

- Everbranch — Send email
- Everbranch — Prepare Customer Loop draft (review-only; never sends or publishes)
- Everbranch — Create job task
- Everbranch — Add job note
- Everbranch — Change job status
- Google Calendar — Create or update event

### Flow controls

- Filter
- Delay For
- Delay Until
- Paths

Only registry entries with an executable handler are selectable. Gmail, Google
Sheets, Squarespace, Wix, Loop, Formatter, Webhook, Schedule, Code, and AI are
not launch components. A provider may be described in Connections as roadmap
work, but that state must never create a workflow step.

Templates are optional definitions generated from this same registry. The
launch starters include calendar automations plus review-only completed-job and
Shopify-order Customer Loop drafts; they are not a separate preview-only
implementation. Customer Loop templates create a draft queue item only. A
person must still review and use the appropriate approved communication surface
to deliver any message.

## Authenticated JSON endpoints

All paths below inherit the workflow role, tenant, and module middleware.
Route-model resources are checked against the active tenant and foreign IDs
fail closed.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/workflows/component-catalog` | Public handler-free component and template catalog |
| `POST` | `/workflows` | Create a blank or template draft |
| `GET` | `/workflows/{workflow}/builder` | Load the current editable definition and Studio state |
| `PUT` | `/workflows/{workflow}/draft` | Save the complete definition using `draft_revision` |
| `POST` | `/workflows/{workflow}/steps/{step}/test` | Test one stable step ID |
| `POST` | `/workflows/{workflow}/test-run` | Test the current draft from trigger through configured steps |
| `POST` | `/workflows/{workflow}/actions/publish` | Compile and publish the tested revision |
| `POST` | `/workflows/{workflow}/actions/pause` | Pause polling and hold pending/delayed items |
| `POST` | `/workflows/{workflow}/actions/resume` | Resume polling, optionally releasing held items |
| `POST` | `/workflows/{workflow}/actions/discard-held` | Explicitly discard held items |
| `POST` | `/workflows/runs/{run}/actions/retry` | Requeue failed v2 run items |

Asana and Google Calendar OAuth state retains only a validated local return
path. Commerce callbacks return to the workflow Connections surface. Do not
accept an arbitrary external redirect or trust a workflow/step ID from OAuth
state without resolving it again inside the active tenant.

Each connection also stores the exact OAuth client ID and secret used for that
authorization through encrypted, hidden model fields. Token refresh, rotation,
and provider revocation must use that captured pair. Pre-migration connections
may use their recorded legacy/global credential source only when it resolves
unambiguously; incomplete or cross-client credentials fail closed and require
reconnection.

## Persistence map

Schema v2 adds or extends these records:

| Record | Operational purpose |
| --- | --- |
| `automation_workflows` | Draft schema version, optimistic revision, next poll time, and current published version |
| `automation_workflow_versions` | Immutable published definition and hash |
| `automation_workflow_domain_events` | Tenant-scoped native Everbranch event outbox |
| `automation_workflow_runs` | One test, manual, scheduled, or cutover run |
| `automation_workflow_run_items` | One durable, version-pinned trigger event and its checkpoint |
| `automation_workflow_run_steps` | Actual per-item step/branch attempt history |
| `automation_workflow_action_receipts` | Idempotency reservation and confirmed external/native result |
| `automation_workflow_links` | Destination identity per workflow step and source record |
| `automation_workflow_states` | Polling cursor and provider run state |
| `automation_workflow_audit_events` | Draft, test, publish, pause, resume, retry, discard, and cutover evidence |

Run-item `payload`, `context`, and `execution_stack`, domain-event `payload`,
and action-receipt `result` use Laravel encrypted casts and are hidden from
ordinary serialization. Do not select or print these columns in logs, support
screenshots, or routine diagnostics.

Native outbox retention runs daily through
`automation:prune-domain-events`. The command first marks events older than 30
days as consumed only when every relevant published workflow for that tenant
and native trigger has advanced beyond the event. It deletes an acknowledged
event only after a further seven-day grace period. A paused workflow with an
older cursor therefore keeps the rows it still needs. A blank, malformed, or
non-numeric cursor on an existing relevant workflow also blocks retention; only
a genuinely absent state uses the native trigger's bootstrap-at-latest
semantics. `consumed_at` is a global safe-through acknowledgement, not a
per-workflow delivery receipt. Use `--dry-run` and `--tenant-id=<id>` for scoped
diagnostics; the windows can be changed with
`AUTOMATION_DOMAIN_EVENT_RETENTION_DAYS` and
`AUTOMATION_DOMAIN_EVENT_CONSUMED_GRACE_DAYS`.

Run-step input/output summaries are deliberately redacted and bounded. Keys
that commonly contain tokens, secrets, passwords, messages, notes,
descriptions, email, phone, addresses, recipients, or raw payloads are replaced.
Error messages redact long credential-like values and email addresses and are
length-limited.

## Scheduler and queue behavior

Laravel schedules both workflow jobs every minute:

1. `automation:dispatch` selects active, published workflows whose
   `next_run_at` is due. For v2, it first verifies current rollout and module
   access. An inaccessible v2 workflow is left due without advancing its
   cadence; eligible workflows atomically advance `next_run_at`, then dispatch
   one overlapping-protected workflow job. Schema-v1 dispatch remains
   unaffected by the v2 rollout gate.
2. `ReleaseDueAutomationWorkflowRunItemsJob` finds pending or delayed v2 items
   whose `available_at` is due and dispatches overlapping-protected item jobs.

The minute scheduler is only a wake-up cadence. Each published definition's
`settings.poll_interval_minutes` controls source polling, with ten minutes as
the default and a supported range of one minute through one day.

Required production processes:

- Laravel scheduler running every minute;
- queue workers processing the application's default workflow queue;
- cache locks available;
- application encryption key configured; and
- current database/cache connectivity reported by `/ready`.

Useful diagnostic commands:

```bash
php artisan schedule:list
php artisan automation:dispatch --sync
```

Use `--sync` only for an approved diagnostic against a known safe workflow. It
can poll and execute active published work; it is not a read-only command.

## Run-item lifecycle

For each accepted trigger event, the v2 engine:

1. creates a unique run item pinned to the published version;
2. persists its encrypted payload and execution stack;
3. advances the source cursor only in the transaction that persisted accepted
   events;
4. executes the item under a queue lock;
5. checkpoints output and the next stack position after every successful step;
   and
6. completes the parent run only after its items reach terminal states.

The unique version/event key prevents duplicate acceptance. Each action uses an
idempotency key derived from workflow version, event identity, and stable step
ID. A confirmed receipt returns the stored result on replay instead of sending
the action again. Native job actions execute with their receipt transaction;
Google Calendar uses upsert/link behavior for safe replay.

Retryable provider failures receive bounded backoff at 60 seconds, five
minutes, and 15 minutes, with at most four job attempts. A retry resumes at the
failed step because completed outputs and the execution stack are checkpointed.
HTTP 429 and provider 5xx failures are retry candidates.

Email or another non-idempotent external action whose outcome is uncertain is
marked `held`; it is not sent again automatically. An operator must verify the
provider before deciding how to proceed.

### Filter, Paths, and Delay

- Filter supports AND/OR groups and text, number, date, boolean, existence,
  empty, and collection/line-item comparisons.
- Paths evaluates matching branches sequentially in editor order. More than one
  custom/always branch may run. Fallback runs only when no earlier branch
  matches.
- A branch failure is recorded against its parent and branch identity; other
  selected branches may continue. The item finishes as a partial failure when
  any selected branch failed.
- Delay For and Delay Until persist `available_at` and return the worker. They
  never keep a process sleeping. A delay inside a path pauses the entire run
  item, so later selected branches wait for the checkpoint to resume.
- Delay Until uses the configured explicit behavior when its mapped/fixed date
  is already past.

## Pause, hold, resume, discard, and retry

Pausing a v2 workflow clears `next_run_at` and moves pending/delayed items to
`held`, preserving their prior state. No due customer communication is released
merely because the workflow is later turned on.

On resume, the operator chooses one of two behaviors:

- resume polling while leaving held items untouched; or
- explicitly release held items back to their preserved pending/delayed state.

Held items can instead be explicitly discarded. Discard is terminal, audited,
and may finish the parent run as discarded. Failed v2 run items can be retried
from Run History; successful items are not requeued.

Before release or discard:

- inspect the run, item, failed step, action receipt status, and provider;
- verify whether an external effect already occurred;
- preserve customer communication safety over automatic recovery; and
- record the incident/operator reason outside any secret-bearing payload.

## Schema-v1 compatibility and cutover

Published schema-v1 workflows continue on the existing monolithic runner.
Opening one in Studio converts its editable definition in memory. The v2 draft
is persisted only on the first save; the published v1 version, cursor, links,
and current runtime remain unchanged. Ordinary Studio publication refuses to
replace a published v1 version.

Legacy links are backfilled with `step_key=action`. New v2 links include the
stable action step key, allowing more than one destination-writing action for
the same source record.

There are two distinct legacy transitions:

- `automation:cutover-legacy` moves the pre-product tenant marketing setting
  onto the existing schema-v1 product workflow. It does not publish schema v2.
- `automation:promote-legacy-v2` is the only supported schema-v1 to schema-v2
  promotion path.

For an active schema-v1 Asana-to-Google Calendar product workflow:

1. Confirm current tenant-owned Asana and Google Calendar connections, the
   preserved workflow cursor, and existing destination links.
2. Run one read-only parity comparison:

   ```bash
   php artisan automation:promote-legacy-v2 <tenant-id-or-slug> --shadow
   ```

3. Repeat `--shadow` until three consecutive comparisons have the same source
   selection, mapped outputs, expected create/update counts, source cursor, and
   candidate definition evidence. A mismatch or changed signature resets the
   qualifying streak.
4. Do not promote if any comparison differs, a provider connection is
   unhealthy, runtime access is disabled, a run is active, or duplicate
   protection cannot be demonstrated.
5. In an approved low-traffic window, run:

   ```bash
   php artisan automation:promote-legacy-v2 <tenant-id-or-slug> --confirm
   ```

`--confirm` is fail-closed until the three-comparison gate passes and performs
one additional matching shadow under the same workflow execution lock. It then
atomically creates an immutable schema-v2 version, preserves the source cursor,
remaps every legacy `step_key=action` destination link to the stable v2 action
ULID, rewrites duplicate-protection fingerprints, switches the published
version, and records hashed rollback evidence. A failure rolls back that whole
database transaction.

The immutable v1 version and prior link metadata remain available for audited
behavioral rollback. A Forge code rollback does not reverse workflow database
state or automatically select that version.

### Behavioral rollback after a completed cutover

If a newly active v2 workflow is unsafe:

1. Pause it immediately. Pending and delayed items become held.
2. Review external action receipts and destination links before retrying,
   releasing, or discarding anything.
3. Restore the preserved immutable v1 version and its recorded link metadata
   only through an approved, audited operator change; never run v1 and v2
   concurrently against the same source.
4. Verify the preserved cursor and links before turning legacy polling back on.
5. Record the rollback and incident, then keep the v2 workflow paused until a
   corrected immutable version is tested and published.

Do not delete workflow versions, cursors, links, run items, action receipts, or
audit events as part of rollback.

## Security checklist

- [ ] Active tenant resolved from membership and host/session context.
- [ ] `workflow_automations` entitlement and v2 tenant allowlist both pass.
- [ ] User role is admin, manager, or marketing manager.
- [ ] Every workflow, run, and connection ID belongs to that tenant.
- [ ] Connection provider, status, and required scopes pass compiler checks.
- [ ] OAuth return path is local and validated.
- [ ] Public catalog contains no handler class names or credentials.
- [ ] Definition contains no executable code or arbitrary expression.
- [ ] Run payload/checkpoint and receipt result remain encrypted at rest.
- [ ] UI/logs use redacted summaries rather than encrypted raw payloads.
- [ ] Unsupported components cannot be selected or published.

## Pre-deploy verification

Use the protected PR/CI path. The workflow migrations are additive and must run
through Forge's atomic release process.

At minimum, run:

```bash
php -d memory_limit=1G ./vendor/bin/pest \
  tests/Feature/WorkflowStudioV2FoundationTest.php \
  tests/Unit/WorkflowV2ExecutionPrimitivesTest.php \
  tests/Feature/WorkflowV2RunItemExecutionTest.php \
  tests/Feature/WorkflowStudioV2EndToEndTest.php \
  tests/Feature/WorkflowStudioV2RegressionTest.php \
  tests/Feature/WorkflowStudioServerSupportTest.php \
  tests/Feature/WorkflowStudioRuntimeAccessTest.php \
  tests/Feature/WorkflowProviderDisconnectSafetyTest.php \
  tests/Feature/WorkflowLegacyV2PromotionTest.php \
  tests/Feature/WorkflowV2ProviderOperationsTest.php \
  tests/Feature/WorkflowV2RunHistoryTruthfulnessTest.php \
  tests/Feature/WorkflowProviderCredentialSourceTest.php \
  tests/Feature/WorkflowDomainEventRetentionTest.php \
  tests/Feature/WorkflowCommerceConnectionsTest.php \
  tests/Feature/WorkflowLegacyCutoverTest.php

npm run build
git diff --check
```

Before merge:

- [ ] Verify the production v2 allowlist contains only approved tenant IDs.
- [ ] Confirm a current database backup.
- [ ] Confirm scheduler and queue-worker health.
- [ ] Confirm provider OAuth callback URLs and requested scopes.
- [ ] Confirm no unsupported component is selectable.
- [ ] Review additive migrations for MySQL-safe identifiers.
- [ ] Merge only after required CI checks pass.

Forge builds a new release, runs migrations before activation, activates it
atomically, and restarts queues. Follow
`docs/operations/forge-atomic-release-runbook.md`; do not deploy by editing the
active release.

## Production smoke test

Perform this in the allowlisted Modern Forestry workspace with a deliberately
safe source record and destination. Do not use a real customer email action for
the first smoke test.

### Health and routing

```bash
curl -fsS https://app.theeverbranch.com/up
curl -fsS https://app.theeverbranch.com/ready
```

- [ ] Both endpoints return success and `/ready` identifies the intended
  release.
- [ ] Sign in through the production workspace host.
- [ ] Confirm an authorized user can open `/workflows`.
- [ ] Confirm a non-entitled or foreign-tenant context cannot load workflow
  JSON or IDs.

### Build and publish

- [ ] Open `/workflows/new`.
- [ ] Confirm the picker opens and no workflow row exists before selecting a
  trigger.
- [ ] Select a real launch trigger and confirm the URL becomes
  `/workflows/{id}`.
- [ ] Select a tenant-owned account when the trigger requires one.
- [ ] Add Filter.
- [ ] Add either Delay or Paths. Keep a smoke Delay close to one minute.
- [ ] Add two safe actions. Google Calendar plus a reversible native job action
  is preferred; avoid customer email.
- [ ] Configure mappings only from the trigger or earlier step outputs.
- [ ] Save and confirm the revision/save state updates.
- [ ] Test every step and verify visible tested state.
- [ ] Run Test run and verify per-step test history.
- [ ] Publish and confirm an immutable schema-v2 version is active.

### Execute and inspect

- [ ] Create one uniquely identifiable source event after publish.
- [ ] Confirm the next scheduler minute dispatches the workflow when
  `next_run_at` is due.
- [ ] Confirm exactly one run item is accepted for the source event.
- [ ] Confirm the item is pinned to the published version.
- [ ] Confirm each action has a distinct stable step key and run-step row.
- [ ] If a Delay is present, confirm the item becomes `delayed`, has
  `available_at`, and resumes without repeating prior actions.
- [ ] If Paths is present, confirm matching branches execute left-to-right and
  fallback does not run when another branch matched.
- [ ] Confirm the expected Calendar/native destination exists exactly once.
- [ ] Confirm Runs shows actual step start/finish state and redacted summaries.
- [ ] Re-present the same source identity and confirm duplicate acceptance or
  duplicate destination writes do not occur.

### Pause safety

- [ ] Pause the workflow.
- [ ] Confirm `next_run_at` clears and due pending/delayed items are held.
- [ ] Confirm resume does not release held items unless explicitly requested.
- [ ] Choose release or discard only after reviewing external outcomes.
- [ ] Leave the smoke workflow paused unless it is the approved production
  workflow.

## Incident triage

| Symptom | First checks | Safe response |
| --- | --- | --- |
| Studio returns 403/404 | Host tenant, membership, role, module entitlement, v2 allowlist | Correct access context; do not broaden tenant scope |
| Save returns 409 | Browser revision versus current workflow revision | Reload/merge the current draft; do not overwrite blindly |
| Publish returns 422 | Field errors, connection status/scopes, required tests | Correct and retest the current definition |
| Workflow did not poll | Status, published version, `next_run_at`, scheduler heartbeat | Restore scheduler/queue health; avoid duplicate manual runs |
| Item remains delayed | `available_at`, workflow status, due-item scheduler | Confirm clock/timezone and active status |
| Item remains held | Pause state or uncertain action receipt | Verify provider outcome, then explicitly release/discard |
| Repeated 429/5xx | Provider health and run-step attempts | Allow bounded backoff; retry failed items only |
| Duplicate external object suspected | Event key, step ID, receipt, workflow link | Pause, inspect provider, and do not force replay |
| Branch partial failure | Branch key, failed step, later branch results | Correct and explicitly retry failed item |

Never paste encrypted payloads, OAuth tokens, message bodies, customer contact
data, or raw provider responses into tickets or deployment logs.
