# Enterprise priorities walkthrough

The priorities overview belongs to the existing Enterprise renderer and guided
tour. It is not a second campus application. Its source is the attributed Gemini
Quick Notes described in the [traceability register](../architecture/enterprise-requirement-traceability.md),
plus the owner's explicit October 22 product instructions. The notes are not a
transcript. Andrew must still validate the problems, process owners, exceptions
and acceptance criteria. The words below describe proposed procedures, not
college-approved policies.

## Problem to procedure to screen

| Priority / evidence | Simplest proposed procedure: owner, trigger, handoff, completion | Actual screen and action | Acceptance evidence | Capability boundary |
| --- | --- | --- | --- | --- |
| Shared visibility / A1, A2 and owner's explicit shared-paper/spreadsheet concern | At intake, a coordinator records one reference, owning department, accountable person, current handler and next action. Authorized teams use the same record; the coordinator checks closure against any existing source ticket. | Requests -> triage -> project/task; Operations & projects -> owner, current handler, checklist, status and notes. Explicit cross-department handoff proposal and acceptance retain the original request. | `EnterpriseOperationsTest`: same current native task/version/checklist across owner, assigned worker and authorized reader; reader mutation and unrelated department/tenant denied. Existing handoff tests prevent duplicate destination work and implicit grants. | Persistent source implemented. Public browser state is fictional simulation. No Maintenance Care, IT or spreadsheet synchronization or replacement is asserted. |
| Flexible crews and interruptions / A2, A3 | A supervisor names a lead/current handler during the daily huddle. On interruption, capture a safe-site checkpoint, owner and next action. Review unresolved work at shift end; explicitly resume, reassign, reschedule or close with a reason. | Project task operational controls and Roll Call resumption queue. A person or team can hold the ball; accountable ownership remains separate. | Operations and canonical workflow tests: checkpoint/progress retained; emergency completion does not close original work; version-bound actions and manager completion verification; browser interrupted retry/reload. | Persistent source implemented. Public simulation. Named simultaneous helper allocation, capacity and actual time accounting are not implemented. No permanent pair or location tracking assumption. |
| Work windows and conflicts / A4 | Scheduling owner checks authoritative classes/athletics calendars with the department contact, records restrictions and an agreed window, resolves conflicts explicitly and records return-to-service evidence. | Buildings/Assets/Calendar -> exclusive reservation and conflict record -> reasoned resolution. | `EnterpriseResourcesTest` plus resource browsers: overlaps/adjacency, timezone/DST, explicit resolution, exact retry and tenant/department denial. | Persistent internal reservations implemented; sample appointments simulated. Live campus calendar feeds and cross-workspace physical-resource deduplication unavailable. A clear internal window is not proof the college calendar is clear. |
| Reporting / A5 | Reporting owner agrees fields and period, reconciles monthly, reviews source-linked factual export, retains the approved submission reference. | Roll Call -> Period reports -> explicit calendar quarter or date range/timezone -> preview -> fresh CSV/JSON. Meeting PDF is a separate current one-page snapshot. | `EnterpriseReportExportTest`, report/certificate browsers: authorization renewed at download, bounded current records versus recorded period events, explicit as-of, DST, formula-safe CSV and private-field exclusions. | Persistent factual export implemented; public simulation. Exact required template, fiscal-quarter definition, recipient/signoff and campus acceptance unknown. No historical quarter-end reconstruction, AI prose or automatic submission. |
| Contractor renewals / A6 | Register owner records accepted expiry, source-document reference, renewal contact and agreed warning/review dates. Assigned manager explicitly reviews replacement metadata; unresolved items stay visible internally. | People & contractors -> Contractor certificates -> propose/review replacement -> internal attention queue/history. | `EnterpriseContractorCertificatesTest` and procedure browsers: accepted expiry remains active while replacement pending; manager-only reads; encrypted revisions; exact retries; no GET writes or provider dispatch. | Persistent metadata/review/internal queue implemented; public simulation. External alerts, dedicated private certificate-file lane, college coverage standards and stop-work authority unavailable. |
| Invoice/check handoffs / A7 | Packet owner links original invoice, project, amount, fund/source references and next reviewer. Reviewer requests changes or records internal routing review; existing Finance/AP process executes its approvals/payment. Assigned owner records an external outcome reference. | Finance packets -> draft -> submit -> assigned reviewer decision/handoff -> external outcome reference/history. Editing a reviewed packet requires reopening and invalidates the old review. | `EnterpriseFinancePacketsTest`: encrypted evidence, current owner/admin authority, assigned decision maker, exact receipts/stale versions, foreign bindings, role loss and forbidden payment fields. Browser lifecycle/retry checks are required before calling UI accepted. | Bounded persistent source under a default-off gate. Public simulation. Internal review is not approval to pay. No check execution/signing, banking, payment provider, accounting sync or assumed institutional approval sequence. |
| Restricted funds and budget codes / A8 | Finance supplies authoritative code and restriction/source references; packet reviewer verifies them in that process, records review and reconciles with the authoritative ledger. | Finance packets -> fund code, restriction/source references, current reviewer and linked project/amount; history preserves review/reopen evidence. | Finance packet tests reject asserted `fund_verified`/`paid` fields, immutable cross-project bindings and unauthorized financial access. | Manual references only; no validated campus fund catalog, donor-policy engine, allocation ledger, commitment totals, remaining balance or availability claim. |

Predictable fees, broad employee access, training and pilot adoption remain
commercial/adoption constraints to confirm with the owner and Andrew. They are
not billing activation or permission grants. Geofencing, proximity tracking and
AI-generated reports remain optional proposals, not Andrew acceptance criteria.

## Technician app and paper Roll Call

The owner explicitly recalls printed operational Roll Call tickets being handed
to technicians. This clarification is owner-provided meeting recollection, not a
verbatim Gemini note. Roll Call is work dispatch and unresolved-job review, not
attendance. The My assigned daily work shortcut selects the existing signed-in
person scope. Its source links open the same authorized task details, status,
notes/photos and interruption/resumption controls. Manager dispatch and exact
approval controls remain separate permissions. Printed meeting one-pagers remain
optional; the current bounded meeting PDF is not claimed as a complete duplicate
of the college's existing printed technician ticket layout.

The current practical first app is the responsive Enterprise web application,
using its existing persistent server, mobile/iPad layouts and file picker.
The default-off installable web-app foundation supplies a trusted manifest,
standalone display, existing Everbranch icons and installation/update controls;
see [the app contract](enterprise-web-app.md). No service worker or native
Enterprise application is implemented or installed. A native wrapper would not by itself supply reliable offline work,
background uploads or permission-safe caching. Device installation, app-store
publishing and offline behavior require separate requirements and acceptance;
the word app alone does not commission those actions. Existing browser-based
flows should first pass hosted and actual-device acceptance with fictional
records. No real users, device access, installation or publishing occurs here.

## Guided and exploration modes

The priorities overview identifies the current priority and lets reviewers open
the actual screen. Compact top-bar controls retain the selected step. Hide or
Explore removes the spotlight so the normal workspace remains usable; Show or
Continue resumes guidance. Overview, replay and dismissal do not submit forms,
change selected records, clear composers, send messages or incur model charges.
Missing or unauthorized targets must explain their unavailability rather than
substituting sample records in the private runtime.

Public sample transport remains browser-only and visibly labeled. It uses the
same presentation while making no operational HTTP/provider requests. Private
screens resolve current tenant/department/role permissions on the server. A
simulated role switch is explanatory UI, never authentication or a real grant.
The Open proposal control stays honestly unavailable while the separate
non-binding Proposal workspace is in planning; no signing or broken URL is added.

## Acceptance and release truth

Feature and browser acceptance uses isolated fictional records and no external
provider calls. Record completed checks, screenshots and exact commit in the PR;
do not label an unrun check passed. Check desktop, phone and iPad portrait and
landscape; keyboard/focus/Escape/reopen, reduced motion, missing targets,
scroll/resize, back/forward/reload, retained drafts and exact interrupted retries.
Viewport simulation is not physical iPad testing.

Source persistence, sample simulation, hosted acceptance and live delivery are
separate statuses. As of the October 22 17:05 UTC HTTP check, the old campus
preview was public but `/enterprise-campus` returned 404. No persistent hosted
campus tenant, live provider acceptance, college records or operational backup
has been established. The protected review/release stack and concrete hosting
actions remain in the [workspace proposal](enterprise-persistent-workspace-approval.md).
Backup and searchable deleted-item recovery remain plan-first.
