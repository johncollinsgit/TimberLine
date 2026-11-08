# Customer event value and register recommendations

November 7, 2026 source candidate. These projections reuse canonical customers,
original purchases and existing source links. They create no order, payment,
wallet or attribution ledger and perform no provider writes. Protected release,
reviewed real mappings and customer/device acceptance remain required.

## Shared customer and event reads

`CustomerEventValueService::forProfile($profile, $tenantId)` accepts an already
authorized customer. It reloads the tenant-scoped canonical identity and retained
aliases. The oldest valid `pos_event_capture.captured_at` supplies the first
recorded contact event. Missing/ambiguous timing, unresolved identities and
bounded capture history withhold first-touch selection. Event title/date
similarity never creates an association. Stable event identity comes from
reviewed `commerce_stock_event_links`; one event can have both Asana and Teams
source IDs. This does not change Modern Forestry's Asana authority during 2026.

New captures retain `capture_evidence_version: 1` and `record_origin: created|selected`.
Only the explicit register-create path records created provenance. Linking an
existing customer records selected provenance. Legacy `register_customer_created`
labels were ambiguous and remain captured-contact/origin-unknown evidence; there
is no historical inference or backfill. The legacy Pay source report separately
counts proven new records, selected existing records and unknown record origins.

The payload separates `acquisition`, `purchase_journey[].purchase_event` and
`membership_conversion`. Pay purchases use their original frozen receipt pool.
Other supported original purchases need an explicit owner-reviewed source-link
annotation before receiving a purchase event. Chronology alone returns correlation
with `causal_credit: null`. A captured contact is not a confirmed paid buyer, and
a zero-paid rewards purchase never fabricates a charge or paid spend.

An earlier original paid purchase establishes returning status, including a
subsequently refunded original purchase. Absent prior records remain unknown.
A current authorized owner/admin may review prehistory to classify the first
capture as new or returning. This is a recorded attestation with an evidence
reference, not automated proof that all channels are connected.

`windows` covers 30/90/365 UTC elapsed days, including capture and excluding the
ending instant. Distinct original purchases use the canonical commerce snapshot's
Pay/Shopify/Square duplicate suppression. Repeat means a later timestamp than the
first observed original positive-paid purchase. This is an observed repeat metric,
not a claim that incomplete history contains the customer's lifetime first sale.
Currencies are separate. Totals are paid amounts including tax after recorded
refunds; unknown refunds withhold net amounts and retain a labeled known subtotal.
COGS, shipping, processing and rewards costs are unadapted: contribution margin
remains unknown. `time_matured` describes elapsed time independently of source
coverage, which remains incomplete.

`forEvent($tenantId, $commerceEventId, $page = 1)` reuses `EventCustomerCohort`
capture/verified-purchase definitions. A page has at most 100 customers. Monetary
summaries and classification/maturity denominators explicitly cover that page
only (`summary_scope: this_page_only`). `recorded_event_customers`, `page`,
`per_page`, `has_more`, cohort review items and bounded source windows must be
displayed with the summary; do not present one page as a complete event total.
Captured contacts and confirmed event purchasers have separate denominators.

`TeamCustomerEventValueController` additionally requires owner/admin financial
access, the requested area and record, current Customers capability and a reviewed
commerce event association. Responses are private/no-store. The caller must
preserve equivalent ACL when presenting the profile projection elsewhere.
Identity drift during a profile/history or event-page read returns409 for a
refresh. A merged donor cannot present its survivor's history under the donor ID
or add the survivor twice to a page total. Normal retained aliases resolve at the
start of a new request.

## Evidence review command

`CustomerEventAttributionReview::review($actor, $tenantId, $profile, $input)` and
`CustomerEventAttributionReviewController` support an owner/admin review of existing
source links. The command runs under the existing tenant customer identity fence,
checks the current canonical identity and optimistic source-link revision, and
records before/after evidence in `MarketingIdentityReview`. A request UUID binds
the exact actor/input and replays the original result. No migration is introduced.

Every request supplies `request_id`, `source_link_id`, `expected_revision`, `kind`,
`reason` (8–1000 characters) and `evidence_reference` (3–500 characters).

- `acquisition`: first valid capture only; `classification: new|returning` and
  explicit `prehistory_reviewed: true`. The server rejects a new classification
  contradicted by an earlier paid purchase, incomplete retained purchase windows
  or unresolved identity evidence. Approval freezes the capture timestamp/event.
  `classification: unknown` clears the review.
- `purchase_event`: an explicitly linked confirmed original `order`,
  `square_order`, `square_payment` or `website_order`, plus a reviewed `event_id`.
  It cannot remap a frozen Pay pool or manufacture payment confirmation.
- `membership_conversion`: the first capture, `event_id` and exact `joined_at`
  returned by verified original subscription history. Existing membership tags,
  native billing activation and stale/unconnected source generations cannot
  establish a new conversion. A separate owner review annotates attribution;
  causal credit remains unknown.

`clear: true` clears a retained review even if its evidence is no longer currently
eligible. `review_options.links` supplies source IDs/revisions, `allowed_kinds`,
`allowed_clear_kinds`, original join timestamps and current reviews. `events`
supplies reviewed choices. Review controls must be restricted to owner/admin;
the server rechecks authorization on every command.

## Register recommendations

`PosCustomerRecommendations::forCustomer($register, $profileId)` reuses `PosAccess`
catalog access: current user, nonexpired payment/mobile read token, active tenant
membership, assigned device/register and POS/Customers capability. The canonical
customer is explicitly selected within that tenant. The endpoint takes no
client-supplied catalog, stock pool or tenant override.

`PosCustomerRecommendationController` returns ordered current catalog IDs,
per-item scores/reasons, available reviewed scents, scope IDs and a catalog
fingerprint. `POS_CUSTOMER_RECOMMENDATIONS_ENABLED` defaults false. Root routing
uses the existing mobile workspace/register read middleware and scope fences.

Distinct confirmed purchases without refunds rank first; units break ties, then
the original catalog order. Counts span linked online/in-person sources after
original-source duplicate suppression. Partial/full/unknown refunds, unconfirmed
and future/undated history are excluded. Exact tenant stock identity can recommend
a product; reviewed scent identity can connect its current sizes. Product titles,
card data, arbitrary matching SKUs and legacy-native hex IDs never establish
stock or scent identity. Shopify matching requires an active explicit tenant/store
mapping to an owned active stock item. Unmapped legacy catalogs retain original
order. Bundles use the canonical normalized component units from purchase history.

The canonical `CustomerPreferenceService` projection supplies declared avoided
scents. An exact declared-name match to a reviewed candidate scent leaves that
product visible but unranked with `reason: customer_avoids_scent`. Product titles
cannot establish this match. Declared favorites do not override frequency order,
and gift/purchase observations never update declared favorites. Coverage includes
`preference_version`; a changed canonical preference identity rejects the read
with `customer_selection_changed`, as does a changed snapshot customer identity.

Sold-out, count-required and review-required products are not promoted. Untracked
quantity remains null. Every returned ID comes from the current assigned
register catalog, and unobserved/unavailable products retain their relative
original order. This read changes no stock, prices, basket, reward, quote or payment.
Native clients must discard stale customer/workspace/register/catalog responses.

## Verification

Focused SQLite `:memory:` suites are `TeamCustomerEventValueTest`,
`TeamPosCustomerRecommendationsTest` and the existing `TeamEventCommerceTest`,
through `phpunit.team.xml`. They cover canonical aliases/stale models, tenant and
role/token/device/register isolation, reviewed mappings and optimistic replay,
original/refunded/unknown purchase evidence, time boundaries/currencies, original
membership timing, cross-channel mirrors, frequency/units/catalog-order ranking,
stale scent mappings, missing coverage and absence of domain mutations. HTTP
authentication may update its normal access-token usage timestamp. SQLite fixture
verification does not establish MySQL concurrency, deployment, live source
completeness, real customer attribution or physical Pay acceptance.

`phpunit.customer-profile-mysql.xml` runs the combined preferences/staff,
outreach policy/admission/review, attribution/recommendation and shared profile
integration/safety suites. Its `TeamsMySqlBootstrap` requires `APP_ENV=testing`,
`DB_CONNECTION=mysql`, `TEAM_WORK_DISPOSABLE_MYSQL=1`, a local host and an explicit
`codex_teams_` disposable database before RefreshDatabase may run. Supply the
authorized disposable database/credentials explicitly; never point it at another
environment.

`scripts/everbranch-team/customer-outreach-mysql-concurrency.php` uses that same
guard and existing schema. It creates fictional fixtures but never creates,
resets, truncates or drops a database. Email and SMS provider interfaces are local
recording fakes, with HTTP prevented and mail/queues faked. Two independent PHP
processes exercise MySQL REPEATABLE READ for each case:

- `cross_channel_cap`: competing Email/SMS admissions produce one original
  delivery and one fake handoff, and the accepted record consumes the daily cap.
- `same_email_recipient`: competing calls for one recipient hand off once.
- `email_unsubscribe_deadlock` and `sms_unsubscribe_deadlock`: an actual legacy
  profile-first consent writer forces an admission deadlock and retry. Committed
  revocation leaves no provider handoff or delivery row, and only one committed
  blocked-decision audit. Provider fakes verify handoff is outside transactions.

The default runs all four; `--scenario=NAME` isolates one known case. Synchronization
and local acceptance files are temporary and removed on exit. Fixture rows remain
in the authorized disposable database. Admission must acquire its MySQL mutex
before any consistent SELECT: even an information-schema table probe can create
a stale REPEATABLE READ view before the lock wait. Missing fence schema still
fails closed. These cases establish only their listed source concurrency
boundaries, not live provider acceptance or all rewards/payment races.

The existing `OwnedStockCheckoutTest` MySQL cases additionally exercise both
acquisition orders for policy-publication versus native descriptor issuance and
conflicting identity import versus customer QR issuance. Its zero-tender cases
verify one original wallet purchase and one restoration with no Square charge.
The POS test helpers retain their existing MySQL/testing/localhost/explicit POS
opt-in guards; local `codex_teams_` names additionally require
`TEAM_WORK_DISPOSABLE_MYSQL=1`. This allowance changes only disposable test guards.

`tests/Integration/CustomerAccountFenceAdmissionMySqlTest.php` requires both
disposable flags. It proves a mutex waiter sees the committed writer under
REPEATABLE READ and that absent MySQL fence schema returns the existing 503,
does not run work or leak a transaction, and preserves all retained rows when
the test restores the temporarily renamed table. Run it independently of the
default customer suite, on the same explicitly authorized local database.

Nested source writers can already have a REPEATABLE READ view before `mutate` is
called. Their existing older-schema write fallback remains unchanged. Mapping
decisions therefore use current locked claims, account profiles, source links,
external identities and every canonical-alias hop through
`CanonicalMarketingProfileResolver::lockedById`. Ordinary profile reads retain
their existing resolver behavior. The direct fence suite covers both acquisition
orders with nested/plain conflicting imports after a reviewed account restore,
and an alias-chain merge while an importing transaction waits. A prior snapshot
cannot hide a newly active verified account or reject a now-valid retained alias.
