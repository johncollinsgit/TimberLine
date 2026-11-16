# Events: shared browser and Pay reporting

The browser Events workspace is the shared reporting home inside Teams, reached
directly from Everbranch navigation. Events, team members, customers and weekends
are views of the same original evidence. Pay opens the same occurrence history.
These views do not create another customer master, sales ledger or source cutover.

## Sources and meaning

Modern Forestry's event identity and assignments remain in Asana. Its Event Team
Member Compensation workbook supplies reported sales and historical staffing.
Square supplies original in-person order/payment/refund evidence; confirmed Pay
and cash receipts remain in their original services. Use the canonical customer
ID from `CanonicalMarketingProfileResolver`, the shared commerce snapshot and
event-cohort services. Customer identity, captured contact, event purchase,
marketing permission and membership conversion remain different relationships.

Reviewed series and occurrence links distinguish venue/season editions and retain
each source revision. Multiple payroll-week segments can describe one continuous
event; multiple staff rows can repeat the same revenue. Neither row count nor
matching names proves another occurrence or another sale. Historical staff can
remain named after leaving the business. An inactive Square location is valid
historical evidence, not an instruction to activate a register.

Location/date matches are review candidates. Admit only reviewed exact merchant,
location and date scopes with completed order/payment import coverage. Retain
checkpoint/run evidence; one completed page or a recent-sync timestamp does not
prove complete history. Original first-page queries must begin after the event's
72-hour offline-delivery allowance. A later terminal resumption cannot establish
coverage for locations read before that cutoff. Source reconnection, changed
source dates or changed allocation requires renewed review. Never write stable
IDs into the reference workbook merely to prepare a report.

Older immutable import intents can predate the recorded `initial_cursor` field.
Their first page must still be proved by the exact original null-cursor intent
hash. MySQL may reorder stored JSON object keys; only a projection reconstructed
from the known original builder, with equal bound values, may restore that
serialization. It must still match the original hash and recorded page size.
Neither an EOF marker nor a manager review substitutes for first-page evidence.

Net sales exclude tax and tips and reflect known returns. Customer spending that
includes tax must be labeled separately. Workbook amounts are comparison evidence
and must never be added to current revenue. Once Square evidence is complete,
use its matched sales for benchmarks and show workbook differences to managers.
Keep unknown refunds, unknown coverage and unassigned sales visible. No rows is
not proof of zero sales. Do not manufacture daily figures by dividing a workbook
total evenly across dates.

A separate Square return-only record can be recognized as an already represented
refund only through its exact original purchase, payment and refund identity,
with matching money and source evidence. It creates neither another purchase nor
another subtraction. Unresolved or duplicate return claims remain review items.

Pay's Sales screen includes imported purchases within the same reviewed Square
location/date scopes as event history. New arrivals in those scopes do not need
another per-order selection. Original Pay mirrors and explicit order selections
are counted once. An incomplete import may show known sales with partial coverage
and the actual Square refresh time; it cannot establish a complete benchmark.

Historical defaults include verified completed occurrences in prior years.
Average event sales divides eligible sales by occurrences. Average daily sales
divides eligible sales by verified selling days. Display denominators and years.
Event-day and weekday comparisons are separate views. An unfinished selling day
is partial; an elapsed day also remains partial while source coverage or its net
amount is unverified. A same-time pace comparison requires original historical timestamps.
Weekend defaults are Friday through Sunday, with editable date boundaries; use
actual local selling dates to split long shows and retain full-event totals.

An event's overall performance describes the team that worked it. It is not
individual sales credit. Individually attributed sales require an original
operator or reviewed original location/time assignment. Later customer purchases
describe observed follow-on activity, not causal credit to a show or employee.

## Access and responsiveness

Assigned team members may read approved sales-only summaries for their events.
Owners/admins can compare across events. Existing source admission, active tenant
membership, area access and customer permissions still apply. Sharing a summary
does not expose raw financial sheets, wages, expenses, private customer data,
source credentials or another tenant's history.

Background jobs build indexed, rebuildable projections; HTTP reads must not run
historical imports or walk every customer's lifetime purchases. Publish a coherent
reporting generation, retain its freshness/coverage, and invalidate or rebuild
after refunds, identity associations, allocation or source changes. Page-level
rows have separate pagination; totals always cover the entire permitted cohort.
Financial revisions cover the report's reviewed Square windows, explicit order
allocations and original receipt ancestry. Unrelated historical imports must not
restart a coherent event report. If customer identities change during a build,
publish unchanged event totals while withholding customer facts until their next
verified generation. Source admission and customer identity checks remain fresh.
New customer generations use a private event-cohort dependency frame, rather than
requiring the entire tenant customer directory to stop importing. Before reading
facts, discover exact reviewed order/window, original native receipt, capture and
reviewed attribution anchors. Include fresh canonical merge chains and incoming
aliases, full relevant link metadata, competing source-key claimants, original
financial rows and suppressed native/provider mirrors. Relevant competing event
allocations include other Teams areas. Changes to reviewed selectors or effective
commerce associations invalidate this scope; unrelated customer inserts and event
notes do not. The small selector and seed descriptor is encrypted with the
snapshot, and no identity metadata is exposed to staff summaries.

Each frame uses one owned local repeatable-read transaction. Full build frames
also read the cohort's original purchase witnesses within that view; the view
therefore lasts for the full witness pass. No provider request occurs within it.
Existing MySQL transactions, including raw PDO transactions, are rejected before selectors are
read because their view could predate the request. Initial selector revision,
scope selection and original dependency rows share that frame's read view.
After commit, a separate short coherent selector view checks the fresh effective
revision. Standalone selector revision reads use the same short view outside a
caller transaction and preserve the caller's isolation inside one. The one-shot
isolation setting does not change the session default. The MySQL view is enforced
READ ONLY. Only its exact tenant, PDO and active transaction scope may omit the
shared Square readers' implicit row locks; ordinary writers keep those locks.
After commit, a new coherent view compares all attempted authority inputs and
freshly re-admits every successful Square connection, policy and actor check,
including current permission inputs and credential expiry. Stable inadmissible
sources retain their existing exclusion behavior. Any changed authority bytes
reject the frame. This is private
request evidence, never an authorization cache. Ordinary metadata locks still
last until the view commits. Full ciphertext witnesses force fresh decoding
after a same-version source rewrite; changes only to observation metadata do not change
the effective selector. Changed allocations, reviewer/source authority and
commerce membership continue to invalidate it.
Builds validate emitted source/customer/event, original money/date and rendered
labels against the pre-fact frame using the shared confirmed-purchase readers.
The frame is checked again after assembly, after fresh provider admission and
before response. Unsupported, truncated or changing evidence withholds all private
customer facts and queues a refresh while permitted financial totals can remain
explicitly stale. Older generations keep the global directory fence until their
atomic refresh. The frame does not grant customer access or replace original
source admission, canonical resolution, purchase or lifetime readers. Native card
witnesses use the journal's explicit read-only reporting projection with the same
stored-term and binding validation; ordinary journal reads/recovery retain their
committed transaction boundary. Dependency rewards reads omit wallet row locks,
while ordinary wallet callers keep their existing locks.

Filtered reads admit the full set of events contributing rows, off-page totals,
customer totals and original operator metrics. Small selections use fresh exact
source checks; broader cohorts use fresh project inventories. Final membership,
source and customer-identity checks run after provider requests and before the
response. This is not a shared authorization cache.

MySQL financial and customer content revisions use covering indexes over invisible
virtual SHA-256 columns. Imports and direct SQL updates maintain those hashes in
the same transaction; no reporting worker or update-time convention supplies the
revision. The read combines the full 256-bit content hash with original row IDs
and timestamps, retaining same-second edits, typed JSON changes and row
replacement detection without rehashing large source documents. The generated
field set is immutable. New fields, incomplete schema and other database engines
use the complete content-digest fallback. Algorithm-versioned revision keys also
include current source records, reviewed scopes and receipt ancestry. MySQL checks
that ancestry freshly in one tenant-scoped, cycle-safe recursive query with a
10,000-receipt bound. These financial revision caches never replace fresh provider
or customer admission.

Pay's current Sales summary reuses the same register/event/source gates and
confirmed-sales reader without rebuilding unused budget and history details.
Teams' full event report retains those details. A blank reviewed workbook total
stays unknown without being mislabeled as a changed source row.

The first permitted comparison requests an issuer-scoped snapshot. The existing
scheduler queues `team:refresh-event-performance` and drains the dedicated
`event_performance` connection / `event-performance` queue each minute. Its
600-second worker timeout stays below the 660-second retry lease. Requests never
fall back to synchronous historical work. Keep the normal Laravel scheduler
running; there is no separate manual reporting worker setup.
The scheduled worker's `--stop-when-empty` is a bare flag. Its generated command
must parse against the actual queue worker definition; assigning `=1` prevents
the worker from taking any jobs. Before the first generation is available, the
browser shows pending totals and dashes rather than zero sales.

Managers review source suggestions in an event's Sales history. Choose the
recurring series, each occurrence's original dates and selling days, and stable
staff records. Mark workbook rows as additive segments or reference copies
explicitly. Select the original Square location and completed order/payment runs
for each date scope. Suggestions do not allocate data until the reviewed mapping
is saved. Original import pagination, merchant binding and offline-arrival
coverage still determine eligibility for historical averages.

The browser preserves date filters, selections and back navigation across linked
event, person and customer views. Refresh keeps current content visible. Search
and details reject stale responses after a newer selection or changed session.
Readable tables, accessible chart alternatives, restrained styling and stable
loading states follow the existing Everbranch UI system.

Performance acceptance targets are p95 useful initial stats within two seconds,
warm filters within 500 ms and detail navigation within one second on normal
broadband with representative Modern Forestry data and twice that volume.
Record measured results separately from targets; do not remove source or tenant
admission checks to meet them.

After the guarded input-key join, the November 16, 2026 disposable MySQL
whole-frame fixture measured five warm `witnesses=false` dependency captures at
468 ms p95 for 1,442 canonical customers,
18 selected events, 1,070 inventory events, 151,000 links, 25,000 original orders
and 63,000 payments; doubling each population measured 1,083 ms p95. Exact cohort,
original-byte and label invalidation checks passed. A separate one-customer
`witnesses=true` correctness sample retained the doubled imported directory.
These are local dependency-frame measurements, not HTTP, browser or full-cohort
build latency, and do not establish the 500 ms warm-filter target. The existing
250 ms one-customer/doubled-unrelated-directory and original-payment component
test caps remain unchanged. Retain counts-only timings and query-plan evidence
with release acceptance artifacts; protected CI and actual endpoint acceptance
remain separate checks.

The final source/access/collection/statistics/acceptance regression suite passed
129 tests with 918 assertions; the Modern Forestry guard passed 170 tests with
910 assertions on PHP 8.4 and SQLite. Focused structured-tag coverage passed
17 tests with 221 assertions, with Applications rendering checked in Chromium
and WebKit. These correctness checks, formatting and diff checks do not replace
protected CI, exact-release readiness or deployed timing acceptance.

The browser event dialog initially requests the admitted record and basic
`essentials?include_sales=0` only. Updates, Sales, History and Customers load when
expanded. Sales first requests `confirmed-sales?summary=1`; Transactions explicitly
requests `summary=0&page=N`, returning at most twenty original purchases at a time.
Neither compact mode loads unused budget, profitability or annual-history detail.
The unflagged legacy endpoints and full/native service behavior remain compatible.
Closed sections stop their requests and polling; source/identity denial removes
private content. Reopening re-admits current access. Discussion drafts remain
scoped to their original actor, tenant and event, and stay sealed until readmitted.
Upcoming current occurrences keep verified prior history visible and distinguish
not-started, in-progress and elapsed incomplete source coverage.

## Delivery and acceptance

Use the protected PR, migration and Forge release gates, then verify the exact
merged `/ready` SHA. A deployed reporting feature does not by itself establish
that every historical source is connected or every mapping has been reviewed.
Confirm real source coverage and representative current/prior-year comparisons
before claiming the Modern Forestry history is complete. Native source checks
also do not prove that a newly installed signed Pay build is accepted.

Acceptance includes inactive locations and former staff; multiweek and repeated
staff rows; Pay/Square mirror deduplication; late and partial refunds; unknown
coverage; daylight-saving/local-date boundaries; staff assignment withdrawal;
customer/tenant isolation; whole-cohort totals across pagination; and identical
event figures across browser and Pay.

No campaigns, charges, refunds, rewards recalculation or stock adjustments are
part of viewing or rebuilding these reports. Provider-derived reporting is not
an AI training, memory or prompt source.
