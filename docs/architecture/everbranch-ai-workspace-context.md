# Workspace-aware AI Branch context (version 2)

## Implementation contract

Continue the AI Branch pilot at `ae180bfc`. Use canonical workspace profile,
blueprint, module access, Collins restrictions and integration health services.
Keep all rollout flags and purchases off by default. This phase is read-only;
existing reviewed internal-note actions retain their separate gate. No financial
summary, autonomous workflow, external calendar, voice or phone transport is added.

Implement in three reviewable increments:

1. Add `AiWorkspaceContext`, `AiWorkspacePage`, `AiWorkspaceSources` and
   `AiAttentionBriefing`. Project approved scalar fields only. Validate page
   identity, tenant, active owner/admin membership, global role restrictions,
   module and record access before querying. Add a separate default-off context
   flag and tenant allowlist. Prove isolation, field exclusions and bounded samples.
2. Extend message requests with optional `origin: {kind, id?}` and introduce
   `POST /ai-branch/briefings` with a UUID. Create the private briefing conversation
   and its shared usage reservation in one account-locked transaction. Bind retries
   to user, purpose and origin. Use the existing encrypted storage, billing ledger,
   uncertain-reservation protections and provider adapter. Show context, evidence,
   coverage and timezone in both standard and Website & sales navigation.
3. Exercise revocation, stale data, empty samples, quotas, concurrency and layouts.
   Run all application tests, formatting, build, disposable MySQL checks and Modern
   Forestry protection. Document staging acceptance and keep production disabled
   until a separately named tenant and owner-approved cap are recorded.

## Exact context boundary

The provider sees a version, current workspace identity/configuration, active
workspace role, enabled Branch names, permitted capabilities, a resolved page,
UTC capture time, bounded evidence, connection health, coverage and the ordered
attention list. It does not receive entire model objects, dashboards, search
results, integration objects or metadata. The selected page descriptor contains
only an allowlisted kind and ID. Titles, tenant IDs, URLs and page text supplied
by a browser are rejected. Server routes construct evidence links.

| Family | Approved projection |
| --- | --- |
| Jobs | title, operational status, priority, schedule, permitted assignee name, source link |
| Tasks | title, status, due time, permitted assignee name, parent-job link |
| Follow-ups | open Customer Loop title, status, due time, linked customer display name |
| Customers | selected or work-linked display name and source link |
| Scheduling | existing job schedules and published scheduled classes |
| Activity | generic event type, timestamp, permitted record label and link |
| Connections | known service name, status, successful-sync timestamp, freshness and settings link |

No contact data, addresses, codes, notes, message bodies, money, credentials or
arbitrary metadata is retrieved into the context. Website customer/order records
are a separate unsupported source; they never substitute for legacy records.
Unverified assignees are labeled unavailable. Archived/recycled jobs and
archived/merged customers are excluded, as are closed tasks, completed/dismissed
follow-ups and currently snoozed follow-ups. Collins restrictions apply to all
adapters, including activity and connection health.

Each family is capped at five records; recent activity is one combined family.
A selected record occupies one of its family's five slots and one of twenty
slots overall. Limits are enforced before constructing the provider request.
The existing serialized input ceiling still rejects oversized requests before
reservation/dispatch. Samples are explicitly labeled and may omit records.

## Ordering and time

The server, not the model, orders attention candidates: overdue tasks/follow-ups,
blocked jobs, today's work/classes, work within seven days, then recorded
connection failures. Due timestamps and numeric record IDs break ties; absent
due times sort last. Noncandidates remain contextual evidence only. Model output
is escaped plain text, with no generated clickable record links. The UI displays
the deterministic evidence list and server-produced links separately.

Store a validated IANA timezone at `TenantAccessProfile.metadata.ai_branch.timezone`.
Missing/invalid values read as UTC. Calendar boundaries and display use the
workspace zone; billing windows and stored timestamps remain UTC. Settings save
under the existing account/profile lock and audit, preserving unrelated metadata.

## Refresh, history and failure handling

Capture context afresh for every request; viewing context never dispatches a
provider call. Versioned permission fingerprints include all module states and
capabilities, adapter permissions, workspace/global role restrictions, timezone,
and private memory IDs/content hashes. History and in-flight answers also require
current source-record access. Recheck active account, billing and membership
before returning an answer. Revoked answers retain their incurred usage but are
not returned. Incompatible history shows a restart notice and preserves scoped
deletion, subject to existing pending-usage holds.

Chat and briefing share `ai_branch_chat` usage, monthly response allowance and
provider cap. A UUID replay cannot create another conversation or charge. A
concurrent/pending UUID returns conflict, not another provider attempt. Ambiguous
provider failures remain uncertain with reserved allowance; use the existing
preview-first reconciliation command. No overage billing or automatic refunds.

Content-free `ai_branch.request` metrics record request/tenant/user IDs, purpose,
context version, per-family counts, latency, outcome and verified token/cost usage.
Never log prompts, retrieved labels, model answers, credentials or source content.
