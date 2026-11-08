# Teams Studio and dedicated app foundation candidate

Implementation increment of the approved Everbranch Teams plan, November 7, 2026.
This is a **draft source candidate**, not the full product or production rollout.
The canonical 643 clauses / 36 sections remain the acceptance backlog, including
all previously deferred capabilities. No existing clause is promoted to complete
merely because this increment has an API, a screen or passing focused tests.

## Interfaces and persistence

- `/team/studio` returns the authorized area, published definition, authority,
  capabilities and (only for owner/admin) cost definitions and their own drafts.
  `/studio/{definition,draft,preview,create,mapping}` share services with
  `/api/mobile/v1/workspaces/{tenant}/team-work/studio/...`.
- Native transport requires actual Sanctum bearer, read/write ability, rollout,
  canonical tenant entitlement/membership and current area/object permissions.
  Native commands require their exact Idempotency-Key. The lease endpoint binds
  user, tenant, device and token session and lasts at most 24 hours.
- The dedicated OAuth client is `everbranch-teams`, with exact callback
  `everbranchteams://auth/callback`, gated by existing native Teams availability.
  It does not acquire payment abilities or reuse customer/POS authentication.
- `/api/mobile/v1/auth/teams-availability` is a public, rate-limited, no-store
  installation check for that fixed client/callback. It reports the existing
  Core Teams/operations/native gates and creates no session, code, token or
  tenant selection. The native client checks it before opening the sign-in
  browser; an old server, disabled rollout or network failure stays in the app
  with an explanation. This check is advisory, never user/tenant authorization.
  The browser authorization route also renders a phone-sized unavailable page
  for a disabled exact Teams pair, preserving JSON validation errors and all
  other client/callback guards. This prevents a failed native attempt from
  redirecting into an unrelated remembered web workspace.
- Tenant-owned `blueprint` and `cost_policy` definitions, private `event_draft`,
  frozen `event_budget` and reviewed `event_link` records reuse existing encrypted
  operations records, attempts and revisions. No migration is introduced.
  Generic operations feeds exclude these internal types. Financial/policy/draft
  writes are currently owner/admin-only; broader delegated roles remain work.
- Area row locks serialize draft commits. Tenant/user command IDs bind exact
  input and replay original results. Published policies are immutable. Budget
  inputs, policy version/hash and preview freeze together. Creation cannot run
  payroll, mark payments, create stock or duplicate a sales ledger.

## Cost calculation contract

Integer currency amounts; bounded condition trees, explicit priorities and
unknown qualification facts. Hourly/setup and commission/minimum calculations,
one qualifying per diem allowance per person/date (highest or explicit priority), explicitly eligible
trip legs, room count/nights, credited deposits/remaining balances, known costs
and unresolved totals. Expected sales are optional; commission stays provisional
without them. A known commission does not bypass an unknown minimum.

Modern Forestry's written criteria are an editable draft. Publication refuses
its unresolved setup-hour/commission-minimum precedence discrepancy until the owner resolves the rule. The written setup allowance and workbook formulas differ;
the draft effective date also requires confirmation rather than being presented
as a workbook fact. Other
tenants receive their own blank policy unless an explicit tenant template is
configured. No actual pay history was changed or program published.

Further cost-policy work includes employee pay-profile administration, richer
ceilings/overrides and qualification review UX, offline preview parity and complete business acceptance. Estimates
must remain separate from quotes, reported deposits/payments and actual expense.

## Event/source integrity

`EventSchedule` reads explicit full ranges from title/notes independently of
source start/due fields. Conflicting/absent ranges stay unconfirmed. Source task
keys already include tenant, workspace, kind and GID, so two project memberships
remain one event. Original notes, source version and independent task deadline
are retained. Exact source passages remain suggestions until reviewed.

The dated-calendar increment adds explicit local sessions for setup, selling,
teardown and travel, sparse days, person colors and 1/2/3-day client views.
Fresh note/comment evidence can verify individual phase/date facts; no task due
date or generic range verifies intervening days. See `teams-event-schedule.md`
for the complete calendar, source-proof and frozen-budget consistency contract.

Task changes additionally support explicitly hash-fenced complete-note edits,
verified workspace assignee IDs and separately scoped comments. Durable original
requests serialize per source task. An uncertain comment is not posted again.
Attachment responses omit temporary signed download/view links.

This is **not** the complete automatic Asana bridge. Project/field/membership/
attachment writes, approved structured field/note-section mappings, webhooks,
echo suppression and complete conflict/reconciliation UX remain required. Current
scheduled reconciliation and live reads are retained. A newly created event in
an Asana-authoritative area remains a draft awaiting existing source creation;
the wizard does not automatically dispatch that handoff in this increment.

Modern Forestry's preset retains Asana authority and disabled bulk migration/
cutover. Reaching 2027 exposes no automatic switch. Work-area migration windows,
verified history/files/permissions, final delta, pending-change drain, acceptance
and rollback preservation are still unimplemented production gates.

## Comparison and weather contract

Reviewed series/occurrence/source mappings are required. A financial total cannot
be mapped to two occurrences. Source-version changes flag figures for review.
Recorded profit is recorded net sales minus recorded total expense; COGS and
other included costs are not subtracted again. Payroll remains owner/admin-only.
Unknown costs/refunds/daily sales remain unknown. Normalization requires reviewed
selling-day/hour inputs. Current events are partial and excluded from completed
YoY comparisons; multiple occurrences in one year do not produce a false
single-occurrence YoY. Annual totals combine reviewed accessible occurrences, retain unknown refunds/costs
and normalization inputs, suppress duplicate source IDs and compare only reconciled
consecutive years. They explicitly exclude unmapped or inaccessible history.

Weather uses occurrence coordinates/timezone and local show dates, separates
historical reanalysis/recent estimates/forecasts, and returns show-hour samples.
Precipitation intervals use their ending timestamps. Missing venue/date/timezone
or commercial configuration is a visible gap. Reviewed mappings freeze dates and historical venue/timezone/coordinates; subsequent
source date changes require identity review. A mapped occurrence with missing
coordinates cannot fall back to a later venue. Historical venue setup and
comparison-weather UI for every year remain required; this branch does not claim
that Macon's live workbook/history/weather has been configured or reconciled.

## Paired browser/native package and release

The shared React source is in `johncollinsgit/everbranch-mobile`, dedicated entry
`src/teams`. Authenticated `/teams/studio` serves the paired bundled package,
using current tenant/user and CSRF boot context as escaped inert JSON with private/no-store responses. Browser
requests carry the current boot viewer/workspace fence; the client discards late
responses after account or workspace changes.
`/teams` retains the existing full browser work surface and links to Studio.
Public compiled assets contain no tenant boot data. Private API responses and
source notes are not put in a browser service-worker cache.

Source unit/browser builds, an unsigned iOS simulator build and an Android debug
APK are engineering evidence only. Native availability remains default-off;
store registration/signing/distribution, complete privacy disclosures, push,
accessibility/performance and real restart/offline/account-switch/revoked-access/
upload acceptance remain open. The native recovery engine is tested, but offline
startup, file handling, full session/legacy recovery UI and device leases require
completion before rollout. No production setting, provider grant, customer send,
financial transaction or migration was activated here.

Run normal protected GitHub/Forge gates before any production rollout. Read
`provider-terms-and-publishing.md`; never replace live files directly. This draft
must not be merged as a declaration that the requested full Teams product is done.

## Local verification

- Existing Teams suite and new calculator/wizard/source/transport tests.
- Disposable MySQL versions of the new Studio/comparison/transport tests, gated
  by `phpunit.team-studio-mysql.xml` and an explicit local `codex_teams_` database.
  The dedicated Teams Studio workflow repeats these and the two-process races
  without replacing the existing protected release checks.
- `scripts/everbranch-team/studio-mysql-concurrency.php`: explicit isolated
  local `codex_teams_` MySQL only; two processes replay one command to one event,
  or yield one success/one version conflict for competing commands. Budget revision
  races freeze one new revision and retain the original event. Never resets
  or drops a database. Provide the testing DB/env explicitly.
- `node tests/e2e/team-studio-live.browser.mjs` creates its own disposable SQLite
  database, logs in through Fortify, saves and recovers a real wizard event and
  saves a task/comment through shared commands. It verifies stale viewer refusal,
  stops its local server and removes only its own temporary database. No provider
  connections or writes are used. The existing protected workflow runs this check.
- Backend build and zero-change migration lint; mobile typecheck/unit tests,
  paired bundle build and intercepted Chromium wizard/lost-response acceptance.

Full clause-specific, backend-wide CI, MySQL release/recovery, real source/history
reconciliation and supported physical-device acceptance remain release gates.

## Shared Profit Panel and customer lookup (source candidate, November 7)

This focused release includes the read-only shared customer-history and event-value
projections used by Teams. Customer preference editors, personal outreach, POS
recommendations and Square source onboarding/import remain separate unreleased work.

- Teams and the native Pay register read one `EventCommerceReport`. An owner
  reviews an existing tenant stock/Pay event association; title/date similarity
  cannot create that association. One explicit canonical occurrence owns its
  budget reference. Retried link commands retain the original ID and input.
- The Profit Panel uses original confirmed live Pay receipts and their frozen
  stock pool, tax address and operator. Moving the register cannot move old sales.
  Quotes and test payments are excluded. Partial refunds without reviewed item/
  tax allocation leave net revenue/profit unknown; no proportional split is made.
- Saved event policies include tenant-owned named cost types, quantities, rates,
  applicability, overrides and reasons. Meals, gifts, hotel, parking, shipping,
  percentage COGS and processing are editable examples, with no invented default
  COGS/processing rates. Company mileage rules remain company-specific. New global
  policies do not recalculate old events; edits freeze a new budget revision.
  Owner-reported costs remain distinct from payroll/payment evidence.
- Break-even uses the frozen fixed/percentage cost model, commission minimums and
  a conservative penny-rounding bound. Unknown categories, conflicting rules or
  unsupported sales-dependent qualifications leave the target unknown. Workbook
  net-minus-recorded-expense comparisons remain separate from current Pay sales.
- Both clients have name/phone/email lookup, optionally restricted to a reviewed
  event, with original linked purchase products/locations and rewards. Captured
  contacts and verified buyers are labelled separately. Viewing never creates a
  sale, merges identities, grants consent or authorizes reward redemption. Customer
  summaries page 20 at a time; the current purchase-history window remains bounded
  to the shared customer service, with incomplete coverage explicitly labelled.
- Teams requires the current tenant customer capability and an editable work-area
  role. Financial reports require owner/admin. Pay retains its assigned-register,
  device, workspace and token permissions. New Pay event controls additionally
  require `POS_EVENT_STATS_ENABLED` (default false); it is not enabled by this PR.
  Account/workspace/register changes discard late customer/stat responses.
- Report coverage is current-runtime Everbranch Pay, not all historical Square,
  cash or online revenue. Receipt scans stop at 10,000 tenant rows and mark totals
  incomplete beyond that window; capture and customer-history windows are also
  explicit. Indexed large-history reporting and full customer-history pagination
  remain follow-up acceptance work before a claim of complete history coverage.
- Source verification includes SQLite and disposable MySQL, immutable original
  evidence/refund/tenant tests, intercepted browser acceptance, Swift contract
  tests and unsigned simulator compilation. Production rollout, actual Macon
  mappings/rates and supported physical-device acceptance remain outstanding.
