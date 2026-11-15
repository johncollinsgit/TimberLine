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
prove complete history. Source reconnection, changed source dates or changed
allocation requires renewed review. Never write stable IDs into the reference
workbook merely to prepare a report.

Net sales exclude tax and tips and reflect known returns. Customer spending that
includes tax must be labeled separately. Workbook amounts are comparison evidence
and must never be added to current revenue. Once Square evidence is complete,
use its matched sales for benchmarks and show workbook differences to managers.
Keep unknown refunds, unknown coverage and unassigned sales visible. No rows is
not proof of zero sales. Do not manufacture daily figures by dividing a workbook
total evenly across dates.

Pay's Sales screen includes imported purchases within the same reviewed Square
location/date scopes as event history. New arrivals in those scopes do not need
another per-order selection. Original Pay mirrors and explicit order selections
are counted once. An incomplete import may show known sales with partial coverage
and the actual Square refresh time; it cannot establish a complete benchmark.

Historical defaults include verified completed occurrences in prior years.
Average event sales divides eligible sales by occurrences. Average daily sales
divides eligible sales by verified selling days. Display denominators and years.
Event-day and weekday comparisons are separate views. An unfinished selling day
is partial; a same-time pace comparison requires original historical timestamps.
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
Filtered reads admit the full set of events contributing rows, off-page totals,
customer totals and original operator metrics. Small selections use fresh exact
source checks; broader cohorts use fresh project inventories. Final membership,
source and customer-identity checks run after provider requests and before the
response. This is not a shared authorization cache.

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
