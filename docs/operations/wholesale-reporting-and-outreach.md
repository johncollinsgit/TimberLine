# Wholesale reporting and Monday outreach

Wholesale sales cards use the dedicated store's ShopifyQL `sales` reporting.
They must not be calculated by adding payment totals or by filtering Shopify
creation dates. Imported orders can have new creation dates and old sales dates.

## Reconciliation and privacy

The owner’s selected native Analytics report was reconciled exactly through
ShopifyQL, including gross sales, discounts, reversals, net sales, shipping, tax,
orders and average order value. Private amounts and order references remain in
the task’s local audit evidence; do not publish them in this public repository.

Creation-date filtering included historical retail/wholesale migration copies
as new-year sales. Original processed dates corrected that comparison. Sample
originals were verified against the retail store. These are existing copies of
past sales, not new transactions; never combine both stores’ representations as
additive revenue. An intermediate creation-date/payment sum was withdrawn as an
invalid sales comparison.

The operational import omitted closed history and was not a reporting ledger.
Negative repeat-order intervals and duplicate/stale recommendations were also
confirmed. Source fixes and live acceptance remain separate release steps.

## Separate reporting from production work

`WholesaleReportingSnapshot` reads all dedicated-store pages and confirms their
unique count against Shopify before and after retrieval. Pagination preserves
the provider's cursor and filters; passing an empty query array to an absolute
next-page URL had silently dropped them.

The snapshot reads each order's payment facts and three ShopifyQL sales windows
(month, year, trailing twelve months). Publication to the private cache is atomic
and occurs only after all reads, currencies and amounts validate. Failure retains
the prior snapshot. No Shopify orders or production work records are created,
copied, updated, fulfilled or paid. In-memory Order/OrderLine instances are read
models and must never enter mutation services.

Overview sales, order count and average order value use ShopifyQL. These cards show Unavailable until their reporting snapshot is verified. Customer
history and reorder dates use `processed_at`, preserving original sale dates for
imports. Customer lifetime “net collected” uses provider payments received less
refunds; it is a different measure from Shopify sales reporting. Product history
shows ordered units and item value before refunds, excluding tax and shipping.
Date windows use Eastern time and do not overlap at their prior/current boundary.

Dedicated-store history excludes test and canceled orders from relationship
history. Pending/authorized/partially paid orders block reorder outreach; a latest
fully refunded order or a newer canceled attempt requires review. Name-only
matches never merge unknown buyers. Server tenant ownership and explicit
`retail_only` decisions remain effective. Other-store legacy records require
duplicate/payment review and are not added to a verified dedicated-store total.

## Refresh and suggestion lifecycle

`wholesale:refresh-reporting --tenant=modern-forestry` refreshes the private
snapshot and reconciles automated suggestions. The scheduler runs this for
confirmed wholesale workspaces every thirty minutes. A source older than ninety
minutes is visibly stale and cannot generate new recommendations.

Reorder intervals are absolute, positive elapsed intervals. One-order accounts
use an explicitly estimated 120-day window; limited repeat history defaults to
90 days. Due/at-risk/lapsed rules remain heuristics, not purchase probabilities.
The displayed history evidence score and prospect evidence-completeness score are not calibrated conversion likelihoods.

Only one current reorder suggestion is retained per customer/latest order.
Timing-category changes, refunds or historical enrichment do not bypass a staff
dismissal or snooze. Obsolete pending/snoozed advice becomes `superseded`; decisions
and follow-up history remain. Existing accepted/review/handled decisions are not
rewritten. Suggestion decisions lock their parent row before creating a follow-up
so replay/concurrent decisions use the current state.

## Monday email

The owner requested `info@theforestrystudio.com`, Monday 8 a.m.,
`America/New_York`. `wholesale:send-outreach-digest` refreshes history first, then
sends up to ten eligible accounts to that internal inbox. Candidates prioritize
at-risk accounts, then due accounts, then lapsed accounts, followed by trailing
wholesale value and elapsed time; each buying inbox appears once. Snoozes,
handled/review decisions, future follow-ups, follow-ups completed in the past
14 days, invalid emails and matched do-not-contact flags suppress recommendations.

Each “Review email draft” button opens Gmail with the recipient, subject,
latest original order date and up to five ordered product/quantity lines.
It does not send customer outreach. Review the sending account and current
relationship before sending. Record/complete or snooze the follow-up in Backstage
to keep the next list current; opening a Gmail draft does not prove it was sent.

The weekly tenant/recipient key is reserved in `marketing_email_deliveries`
before provider HTTP, under a cache lock. A repeated or uncertain send is not
automatically replayed. Missing/incomplete history sends one internal attention
notice with no recommendations. Reconcile delivery evidence before any manual
retry; do not delete uncertain records to force another email.

Preview with `wholesale:send-outreach-digest --tenant=modern-forestry
--email=info@theforestrystudio.com --dry-run`. This reads/refreshes reporting
cache but sends no mail and creates no delivery/order records.

## Release and acceptance

Run the wholesale/reporting/outreach and label suites, formatting and normal
GitHub/Forge gates. Verify the exact deployed SHA through `/ready`. Then run the
refresh command once, compare the displayed period to native Shopify Analytics,
inspect the suggestion reconciliation and verify the Monday schedule/timezone.
Do not send a customer or test email as part of acceptance. Shopify metadata
lookups and reporting cache refreshes are separate from order imports.
