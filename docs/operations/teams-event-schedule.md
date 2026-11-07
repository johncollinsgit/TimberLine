# Explicit event calendars

Teams uses the existing encrypted operations records, drafts, command receipts and revisions. No schema migration or second event store is needed. Source dates and frozen budgets keep their original meanings.

## Shared contract

Wizard logistics and native event data accept:

```json
{
  "timezone": "America/New_York",
  "calendar_color": "#315A44",
  "schedule_complete": true,
  "sessions": [
    {"id": "1d7c2630-a935-4e3d-bfa7-64d386533210", "kind": "setup", "date": "2026-11-05", "opens": "10:00", "closes": "19:00"},
    {"id": "66af5200-cfaa-4b95-b486-305e06bc94c5", "kind": "selling", "date": "2026-11-06", "opens": "10:00", "closes": "18:00"},
    {"id": "bdb54d6c-ced9-4489-b8ec-37eb725e48aa", "kind": "selling", "date": "2026-11-08", "opens": "11:00", "closes": "17:00"}
  ]
}
```

Each UUID identifies one session on one explicit local date. Kinds are setup, selling, teardown and travel. Date may be null in incomplete schedules. Hours are both present or both unknown; they use the selected IANA timezone. Nonexistent daylight-saving local hours are rejected. Same-day closing follows opening; split overnight activity into separately dated sessions. No gap date becomes a selling day.

Legacy starts_on/ends_on retain selling/occurrence bounds. A complete explicit schedule derives missing legacy bounds from its distinct selling dates and rejects inconsistent supplied bounds. Operational overall_starts_on/overall_ends_on separately include every dated phase. Legacy ranges alone have no invented sessions or selling-day denominator. Known dates, complete date coverage and known hours are separate facts.

Responses expose data.event_schedule with explicit sessions, phase-date lists, overall and legacy bounds, known_selling_day_count, nullable selling_day_count and operating_minutes, needs_review and provenance. Multiple sessions count once per selling date. Overlapping windows count once; elapsed minutes respect timezone transitions. These are available/show windows, never staff attendance, paid minutes, per diem eligibility or automatic crew assignments.

## Review, identity and source authority

POST /operations/records/{id}/review retains the existing request_id, expected_version and reviewed envelope. action=schedule accepts schedule with the contract above. Current authorized area editors can review this supplement; this grants no finance access. An immutable receipt rejects conflicting reuse; stale versions reject. No source field or provider write is issued.

Reviews live in _teams_enrichment.calendar with reviewer, review time and observed source version. Source refresh preserves them and flags source_changed_since_review. source_schedule preserves Asana start/due fields independently. A due date is one task anchor, never evidence for setup or a full event span. Two project memberships retain one canonical task identity.

Owners receive event_schedule.budget_review_items when a complete reviewed calendar has different selling bounds or a distinct selling-day count from the frozen budget. This is a separate budget-revision review item; current amounts, original event columns and paid inputs stay unchanged.

calendar_people returns only current authorized native assignments/staff IDs and exact source Asana assignee IDs with provider/workspace namespaces and available source names. No display-name, budget-person or pay-row identity inference occurs. Calendar colors are presentation metadata. Feed pagination, freshness and partial coverage remain in force.

The legacy authenticated Events calendar also consumes only
`data.event_schedule.sessions`. Month and 1/2/3-day views leave gap days empty;
setup uses a lighter phase treatment, while event/crew color modes use the
saved event color or tenant/provider/namespaced identity. Crew labels remain
event assignments, not attendance or shifts. Presentation choices stay in
page memory per tenant/area and survive a data refresh; event data is not cached
there. Legacy bounds and undated sessions are listed separately. Page/source
coverage and unknown timezone/hours remain explicit. Event details show the
dated schedule and independent Asana deadline, then link to authenticated Teams
Studio for the existing review workflow. They do not add a second editor.

Run `node tests/e2e/team-operations-calendar.browser.mjs` after building assets.
Its disposable SQLite/real Laravel HTTP fixture checks sparse selling dates,
lighter setup, month/1/2/3-day controls, stable crew colors across refresh,
unknown/legacy labels, the source anchor and Studio link at desktop/phone widths.
It performs no provider or operation writes.

Copied occurrence sessions are suggestions. schedule_complete=true with copy_from requires explicit confirm_copied_schedule=true after reviewing the new dates. Old dates cannot silently shift or contradict new occurrence bounds. Historical budgets are never recalculated.

## Date evidence

Existing Asana task detail and comments pages return date_evidence candidates, each binding task GID, source version, literal passage/section, candidate kind and dates to a server-computed ID. Generic ranges verify boundaries only. A short explicit range plus a specific named selling-weekday list can support those listed dates; longer ranges are not expanded. Setup month/day may use the unambiguous explicit event year, with its evidence retained. Due dates never supply a missing year. Unknown or conflicting source text remains a review item.

A review may include evidence_selections [{session_id,evidence_id,comment_cursor?}]. The server rereads the current connected task and, when needed, the selected opaque comments page. At most five selected pages are read per review. Exact candidate kind and date must match; stale, forged or conflicting selections reject. Only live connected reads can receive source_verified_at_review. Its verified_fields are kind/date; hours_verified is false. The badge describes date evidence at review, not a complete calendar or verified attendance.

attestations [{session_id,passage,url?}] record staff-reviewed linked documents or other evidence as staff_attested. Attachment metadata and links never imply OCR or content verification. A session cannot have both evidence types. No client-supplied verified flag is accepted. Unreferenced sessions remain unconfirmed. Later source changes mark saved evidence stale; fresh task detail also checks the current task version. Partial comments cannot establish full calendar coverage.

## Historical and release boundaries

A reviewed occurrence mapping may explicitly freeze calendar plus its sessions/completeness, alongside the existing reviewed venue, timezone, daily hours and denominators. Scalar denominators that contradict a complete explicit calendar reject. Omitting calendar records not_reviewed; it does not copy today's mutable event calendar. Weather uses frozen historical inputs and excludes explicit setup/travel/gap dates from selling-day reports.

Wizard budgets retain manually reviewed inputs, policy version and frozen preview. Calendar review cannot assign staff pay or alter past budgets. Source adoption preserves native sessions/color/staff and original selling bounds when adding the original returned Asana identity. When source sync has already projected that exact returned GID, the canonical event stays unchanged and the native draft is withdrawn from active calendars with its original calendar and frozen budget intact. A bounded private related_native_proposals reference lets owners inspect that proposal; source_adoption reports needs_review and the canonical projection ID. No calendar merge, budget retargeting or source financial-reference substitution occurs.

Focused source tests use fictional fixtures and fake provider HTTP. The expanded
Studio cohort passed 119 tests / 953 assertions on disposable MySQL, including
dated weather and source-create semantics. Real source reconciliation, hosted
checks, supported-device acceptance and production release remain separate.
This candidate changes no live source history, Asana task, register, device or
provider configuration.

## Original Asana event creation

The review composer freezes the exact outgoing task name, projects, notes and
optional `asana_due_on` before dispatch. The suggested due date is the first
explicit selling date, not the event end; staff may edit it. Setup/selling gaps
remain explicit note entries and do not create an automatic continuous source
date range. Older prepared commands without this frozen payload require a new
review rather than acquiring new semantics on retry.

Dispatch claims the original event before provider I/O. Competing command UUIDs
cannot bypass an executing/unknown/accepted original. Provider task creation
runs outside a database transaction. A confirmed returned GID survives local
adoption failure with `adoption_status=needs_review`; reconciliation retrieves
that exact GID and exact standalone creation marker without issuing another
POST. Source sync winning the projection race retains one canonical event and
the withdrawn original proposal/budget for review.

Four independent-process MySQL tests / 57 assertions cover competing creates,
expired tokens, unknown outcomes and confirmed identity recovery. These checks
use the real admission/provider/adoption services with fake HTTP transport and
do not create a task in Asana.
