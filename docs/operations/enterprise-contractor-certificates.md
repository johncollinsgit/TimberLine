# Contractor certificate metadata and internal reminders

This source-only slice addresses the Andrew-attributed certificate-expiry alert
need in [requirement traceability](../architecture/enterprise-requirement-traceability.md).
It reuses existing same-workspace contractor contacts and encrypted operation
records. It does not import college records, grant access, validate coverage,
establish a stop-work policy or send a notification. External notifications and
a dedicated private document upload lane remain future work.

`enterprise.certificates_enabled` defaults off. Reads require a current active,
verified tenant owner/admin or department coordinator. The tenant must already
have Enterprise/Teams access and rollout authorization. Ordinary shared-area
editors, viewers, requesters and contractor contacts cannot see certificate
metadata, renewal contacts, reminder counts or history. Reads remain available
to authorized managers while Enterprise writes are paused. All mutation and
receipt replays recheck current authority and immutable tenant/area/contractor
bindings. An archived record remains readable but cannot be edited.

## Record and review lifecycle

The encrypted `ent_contractor_cert` operation record contains an immutable
contractor ID; a managerial label; manually entered renewal contact/reference;
nullable warning lead days and review due date; accepted metadata; a separate
pending replacement; and the latest review. A document reference is text for a
record held in an already approved private process. It is never fetched, linked
to a broad task attachment, or treated as verified file bytes.

A new record starts with a pending proposal and no accepted certificate.
Incomplete expiry/reference information may be recorded as unknown, but an
accept decision requires both fields. An existing accepted expiry remains in
effect for reminder calculations while a future replacement is pending. Reject
preserves accepted metadata. Accept records the authenticated current manager
ID, server UTC timestamp and required reason; the client cannot assert a
reviewer identity or review timestamp. This records a human metadata review,
not an automated insurance authenticity or coverage determination. Proposers
can review their own proposal if already authorized; two-person review has not
been asserted as institutional policy.

Each command runs under the existing tenant lock with an expected record
version, actor-scoped request UUID receipt and encrypted before/after revision.
Same-body retries return the current authorized record without creating another
revision. A changed body using an old request UUID or a stale record version is
rejected. A candidate's replacement UUID must also match at review. Archive
requires an explicit reason and confirmation, preserves accepted/pending
metadata/history and removes that archived record from the derived reminder
queue; it does not change contractor status or permission to work.

## Date and queue semantics

`enterprise.certificates_timezone` is a per-deployment client calendar seam,
defaulting to `America/New_York` for the commissioned plan. It must be a valid
IANA timezone; invalid configuration fails closed. APIs expose `timezone` and
`as_of_on`; review/audit timestamps remain UTC. These are calendar labels, not
an interpretation of when legal insurance coverage begins or ends.

Warning lead days are explicitly entered per certificate. Null means not yet
configured, not a silently chosen institutional default. Zero is allowed.
0–3650 is a technical input bound, not a college warning policy.

Reminder reads derive the following reasons from current persistent metadata:

- `unreviewed`, or `awaiting_replacement_review` for a pending replacement of
  accepted metadata;
- `document_reference_missing`, `expiry_unknown`, `renewal_contact_missing`,
  `warning_not_configured`, or `review_not_scheduled`;
- `review_due` when the entered review date is today or earlier;
- `expired` when accepted expiry is before the client-calendar date,
  `expires_today` when equal, or `expiry_warning` when within explicitly entered
  warning days.

Pending replacement dates never clear accepted-expiry reminders. Reads produce
no writes, queue jobs, acknowledgments, sends or scheduler activity. The
internal queue is the current permission-filtered projection of persistent
certificate records; acknowledgment/snooze/escalation workflows are not included
in this bounded slice. Counts cover only returned records, at most 200 matches
from a scan of at most 1000. Nonmatching records are filtered before the 200-item
return cap. The response always identifies bounded coverage and includes up to
200 existing same-area contractor choices. No complete-campus totals are claimed.

## API contract

All endpoints resolve the existing authenticated tenant context and return
private, no-store responses. Commands require JSON; unexpected body fields,
including asserted reviewers, task file IDs, sends and stop-work flags, are
rejected. A routing `tenant` query parameter never becomes command authority.

| Endpoint | Purpose |
| --- | --- |
| `GET /enterprise/certificates?area_id=...&contractor_id=...` | Manager-only bounded register, optional existing contractor filter and same-area contact choices. |
| `GET /enterprise/certificates/reminders?area_id=...` | Current derived attention records; no GET mutations. |
| `GET /enterprise/certificates/{id}` | Current authorized metadata and reminder reasons. |
| `GET /enterprise/certificates/{id}/history` | Latest 100 immutable encrypted revisions, reauthorized now. |
| `POST /enterprise/certificates/commands` | Explicit versioned commands below. |

Every command supplies `action`, `id`, `area_id`, `request_id` UUIDs and
`expected_version`. `action` is one of:

| Action | Action-specific fields |
| --- | --- |
| `certificate.propose` | `replacement_id`, `document_reference` and `expires_on` (latter two present but nullable). New ID/version 0 also requires existing same-area active `contractor_id` and `title`; optional `renewal_contact`, `warning_lead_days`, `review_due_on`. An existing record proposal cannot edit settings or replace an already-pending proposal. |
| `certificate.metadata` | `title`, `renewal_contact`, `warning_lead_days`, `review_due_on`, all present and nullable except title. Does not change accepted or pending evidence/expiry. |
| `certificate.review` | `replacement_id`, `decision` (`accept`/`reject`), required `reason`, `confirmed:true`. Actor/time are server-derived. |
| `certificate.archive` | Required `reason`, `confirmed:true`. |

Read DTOs include ID, area, title/status/version, current contractor
ID/title/status, reminder settings, accepted/pending/latest-review objects,
`reminder_reasons`, `expiry_state`, date/timezone, current `can_manage` and update
timestamp. Reviewers are stable identity IDs rather than retained display names.
An inactive contact's existing history remains readable; new proposals for an
inactive contact are rejected. No record is moved to another contact or area.

## Verification and remaining acceptance

[`EnterpriseContractorCertificatesTest`](../../tests/Feature/Enterprise/EnterpriseContractorCertificatesTest.php)
exercises encrypted persistence, exact receipts/version conflicts, current
reviewer identity, accepted-versus-pending behavior, explicit missing settings,
date boundaries/configured timezone, no GET writes, private shared-area roles,
demotion/revocation/unverified membership, foreign bindings, archive/history,
write freeze and unexpected HTTP body fields. It prevents stray provider HTTP.
The UI/browser milestone and final combined checks remain integration-owner
gates. A disposable test pass does not establish a hosted client deployment,
actual document review, a configured policy or delivered external reminders.
