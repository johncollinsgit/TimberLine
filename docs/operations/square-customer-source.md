# Core Square customer source connection

Source-only candidate. `/workspace/customers/square?tenant=SLUG` lets an active,
verified tenant owner/admin authorize the existing Square application for customer
history. The actor needs the included Customers capability and an active qualifying
tenant membership, including when their global role is `member` or
`platform_admin`. Global Marketing, Teams and Pay access are not prerequisites.

The application requests only `MERCHANT_PROFILE_READ`, `CUSTOMERS_READ`,
`ORDERS_READ` and `PAYMENTS_READ`. Square's Cards List read uses `PAYMENTS_READ`;
no payment, customer, catalog, loyalty or order write scope is requested.
Connecting does not configure a register, enable checkout, change a schedule,
publish rewards, grant consent, send a message or backfill purchase rewards.

## Configuration and callback

Reuse the existing Square application's `POS_SQUARE_APPLICATION_ID` and
`POS_SQUARE_APPLICATION_SECRET`. Configure the dedicated
`CUSTOMER_SQUARE_OAUTH_REDIRECT_URI` as the exact registered HTTPS callback for
`/workspace/customers/square/callback`. `CUSTOMER_SQUARE_ENVIRONMENT` is
`sandbox` or `production`; the configured environment is frozen for first setup,
and reconnects retain the original environment. Configuration alone does not
verify a provider grant. No live connection or permission change was performed
while implementing this candidate.

Setup steps:

1. Register the dedicated HTTPS callback on the existing Square application and
   set the two `CUSTOMER_SQUARE_*` variables shown in `.env.example`. Its callback
   defaults to unset and its initial environment defaults to `sandbox`. Leave the
   existing Pay callback unchanged.
2. Open Core Customers → Square source history as an active, verified tenant
   owner/admin. Review the requested READ scopes, then use Connect Square. A
   registered callback and a real owner-authorized grant remain necessary.
3. For reconnect, refresh the displayed connection state and reconnect the
   original merchant in its original environment. Another merchant cannot replace
   the existing history. A generation conflict requires a fresh page review.

The begin form posts a request UUID and the displayed expected generation. The
callback uses the original session/state tenant rather than the currently selected
workspace. State is bound to the actor, session ID, tenant, original merchant,
application, callback URI, environment, generation and ten-minute expiry. Its
durable setting record is claimed once before token exchange. No HTTP call is
inside its persistence transactions and no token exchange is automatically retried.

After exchange the server introspects the token's client, merchant and granted
read permissions, retrieves the original merchant and validates every returned
location belongs to it. Source onboarding has no USD, country, card-processing or
Pay readiness requirement. These checks authorize reading original data only.

## Storage and identity

The existing tenant-local `square_config` setting retains a stable connection ID,
generation, merchant/environment/location binding, scope metadata and audit actor.
The access/refresh token payload is encrypted using the existing POS encrypter;
credentials, codes and state secrets are excluded from public state and append-only
audit records. Existing plaintext legacy settings remain readable by the separate
source adapter until explicitly reauthorized; a successful bridge reconnect
replaces their plaintext tokens with the encrypted envelope.

Reconnects preserve identity and increment generation. A legacy token without
merchant metadata must prove its original merchant before replacement. An existing
Pay merchant can pin the expected account without changing its payment credentials.
Orphaned Square history without a provable original binding requires review.
Same-tenant reauthorization is allowed; another tenant cannot claim the original
merchant/environment. Core and the existing Pay OAuth persistence path share an
existing-settings mutex and current ownership reads. No duplicate ownership map or
new database schema is introduced. When retail/source settings tables are absent,
the isolated Enterprise Pay path retains its original merchant ownership guards.

Local disconnect increments generation, clears source credentials and retains
merchant identity and original history. It stops source reads in Everbranch; it
does not claim to revoke Square's provider-side grant. A late callback cannot
reactivate a disconnected or subsequently changed connection.

## Source credential renewal

New bridge grants record their confidential authorization-code flow, original
application and credential issue time. Only these encrypted, active source grants
are eligible for renewal. Legacy reader settings and original Pay connections use
their existing authorization paths. Square documents 30-day access tokens and
recommends renewal at least every seven days; this path renews after seven days or
within five minutes of expiry, including when a source refresh finds an expired
access token. The authorization-code flow uses a multi-use refresh token; the
PKCE rotation rules are a separate flow.

The existing scheduler runs `customer-sources:renew-square --limit=200` daily at
00:40. It inspects oldest-issued active Core grants first and skips disconnected,
claimed or unconfirmed grants. The bounded command can also be run for one tenant:

```sh
php artisan customer-sources:renew-square --tenant-id=TENANT_ID --limit=50
```

This is credential maintenance only; it does not connect an account, import source
records, or enable checkout, rewards or messages. Scheduler health and a suitable
batch capacity remain operational prerequisites. The default batch covers at most
200 grants per day; increase execution frequency as the number of grants grows.

A durable per-generation claim commits before the one token request. No provider
HTTP runs inside the persistence transaction. Renewal verifies the returned
merchant, application and READ scopes, retains the stable connection identity and
advances generation so older import responses cannot persist. An uncertain
response or failed verification retains the original credentials and any received
candidate encrypted, blocks further automatic attempts and exposes
`reconnect_required`; a process interruption can leave a claimed renewal requiring
the same original-merchant reconnect. Never clear the claim to blindly replay a
refresh. No automatic retry or provider revocation occurs.

Customer imports, ambiguous identity review, card metadata, consent observations
and source-history refresh are owned by the existing Square source services.
They must not treat contact/card similarity as wallet ownership or paid evidence.
Original Square order/payment/refund and Everbranch Pay records remain distinct;
reviewed source links and exact original IDs suppress duplicate representations.

## Focused acceptance

The OAuth boundary suite owns a disposable SQLite `:memory:` schema without an
outer test transaction, so fake token/merchant/location transport checks the real
transaction boundary:

```sh
php -d memory_limit=768M vendor/bin/pest -c phpunit.team.xml tests/Integration/CustomerSquareOAuthTest.php
```

After the existing guarded MySQL schema bootstrap, run ownership races with the
explicit local disposable database environment and both opt-ins:

```sh
TEAM_WORK_DISPOSABLE_MYSQL=1 POS_LIVE_DISPOSABLE_MYSQL=1 php -d memory_limit=768M vendor/bin/pest -c phpunit.customer-profile-mysql.xml tests/Integration/CustomerSquareOwnershipMySqlTest.php
```

The MySQL file does not reset or delete retained rows. It uses independent
REPEATABLE READ connections for both Core-first and Pay-first acquisition orders,
plus same-tenant reconnect. Provider transport is faked; these checks do not prove
a merchant's real permissions or live source completeness.

Scope references: [Square OAuth permissions](https://developer.squareup.com/docs/oauth-api/square-permissions),
[Cards List](https://developer.squareup.com/reference/square/cards/list-cards),
[token introspection](https://developer.squareup.com/reference/square/o-auth-api/retrieve-token-status),
[ObtainToken and confidential-code refresh behavior](https://developer.squareup.com/reference/square/o-auth/obtain-token),
[Square token management and seven-day renewal guidance](https://developer.squareup.com/docs/oauth-api/receive-and-manage-tokens).


## Source refresh, review and historical boundaries

Core Customers → Square source connection supports keeping Square as checkout while using Everbranch customer profiles, Website and reviewed rewards tools. It does not require Everbranch Pay or a global Marketing role. A verified active tenant owner, tenant_owner or administrator with Customers access manages the source connection and identity decisions. Isolated Soccer workspaces cannot use this retail flow.

## Existing authorities reused

- `TenantMarketingSetting.square_config` retains the original connection ID, merchant, environment, encrypted source OAuth credentials and generation. Original Pay connections and a merchant-proved existing legacy reader remain supported source readers; a missing tenant connection never falls back to a global company token.
- `SquareMarketingSyncService`, `SquareClient`, `SquareCustomer`, `SquareOrder`, `SquarePayment`, and `MarketingImportRun` remain the source/import pipeline and checkpoint audit. Cards reuse `CustomerExternalProfile` under a tenant + merchant + environment namespace.
- `MarketingProfile`, `MarketingProfileLink`, canonical aliases and `MarketingIdentityReview` remain customer identity authorities. No customer, payment or rewards ledger is duplicated.
- New Core source OAuth reuses the application's existing Square client configuration, encryption and audit. It requests only `MERCHANT_PROFILE_READ`, `CUSTOMERS_READ`, `ORDERS_READ`, `PAYMENTS_READ`. Square ListCards uses `PAYMENTS_READ`. Pay-only grants can lack customer-directory read access without being invalid for Pay.

## Owner workflow

1. Open `/workspace/customers/square` in the current tenant. Existing Integrations links to the same controls.
2. Choose the original merchant/environment connection. Preview a bounded customer, card-reference, order or payment source batch. Customer `since` searches updated dates; order/payment dates retain provider semantics. Provider history completeness stays unknown.
3. Import only after reviewing the explicit action. The optional `create_unmatched` checkbox authorizes separate source-ID customer profiles when a fresh fenced candidate check finds no current tenant email, phone, name or provider-card candidate. Collisions always stage review. Preview persists only an import audit.
4. Review candidate associations independently, or keep them separate. Associating a source never grants portal account ownership, wallet access, marketing approval or rewards. Contact editing still requires existing verification flows.
5. Resume the recorded original page/query, mode, merchant, environment and connection generation. Complete provider pages are processed, even when the provider exceeds the requested advisory batch target. Item failure stops the page before its cursor advances; already saved source rows can safely replay by source ID. A failed first page has an explicit retry marker even though its initial cursor is null. Changed content/request UUID or a rotated connection cannot reuse the original intent.

New Core source OAuth grants carry an internal customer-history purpose that callers cannot downgrade. Existing scheduled/CLI data-import entry points skip those grants unless a current owner explicitly requests the refresh; connecting does not enroll a tenant into old global import schedules. Original Pay and legacy operational readers remain available for explicitly reviewed source policies; no reader is enrolled automatically. All mutations check current membership, Customers capability, connection generation and canonical identity under the tenant customer fence. Provider HTTP remains outside locks. A late provider response after membership/capability removal, disconnect or connection rotation cannot write sources. Core OAuth's bounded credential renewal is separate from source imports, never runs inside `assertCurrent`, and invalidates older generations; uncertain renewal requires reconnect review.

## Consent, cards and historical purchases

`email_unsubscribed=true` is a provider unsubscribe observation. `false`, visible contact details, a receipt request or a payment is not affirmative marketing permission. An unsubscribe suppresses only its exact normalized current contact; a stale or missing provider contact remains a source observation requiring review. Independent current-contact permissions on other channels remain unchanged. Older Square-inferred positive flags remain visible as review-required unless current-contact affirmative evidence after the latest revocation is available. Imports do not mass-repair existing permissions.

Card records contain only actual provider card IDs/fingerprints, brand/last four and source provenance when returned. They are merchant scoped, never card/name matching authority, account/wallet authorization or permission to charge. PAN, CVV, BIN, expiry, billing and cardholder fields are removed. Full card references remain in verified owner/admin source views, outside shopper, QR and AI draft payloads.

Historical order imports durably record `_everbranch_source_import.historical_only=true`. Later refreshes preserve that marker, and `CandleCashRedemptionReconciliationService` skips these rows before extracting reward codes or touching redemption state. The manual import path also bypasses immediate event/conversion/redemption attribution. No historical import earns or consumes rewards, changes stock, creates a payment or changes original financial amounts. Original source labels, quantities, money, statuses and recorded location IDs can be displayed privately without guessing scents or physical events.

There is currently no native Square confirmed-purchase rewards earn dispatcher or verified unmodified-Square-POS redemption integration in this change. A future adapter needs an explicit policy/effective start, original tenant/merchant/environment/customer/payment identity, refunds and Pay mirror deduplication. Existing raw code-text reconciliation is not proof of a paid discount. Staying on Square is supported as a source/customer coexistence workflow; it does not imply those future payment integrations work.

## Read-only original merchant assessment

On November 7, 2026 an authorized bounded production read proved that the existing legacy reader belonged to the original Modern Forestry merchant. Its customer-directory request succeeded; the original Pay grant lacked `CUSTOMERS_READ` but could read cards. Provider directory count was 31,429; cached customers numbered 30,127 and explicit customer links 4,053. These are separate counts, not an ID set difference or a count of new customers. The first 100 provider rows had `email_unsubscribed=false`; none thereby proved opt-in. A bounded payment probe read 200 rows with more pages remaining, and returned recorded Square locations. Sanitized private snapshots were saved outside Git with mode 0600. No production database writes, scope changes, sends, payments, reward operations or feature activation were performed.

## Validation and release

Focused SQLite coverage lives in `SquareCustomerSourceReviewTest`, `SquareCustomerRefreshTest`, updated `SquareSyncAndIdentityIntegrationTest` and `MarketingProfileSyncPipelineTest`. It includes owner-with-global-member access, tenant/namespace isolation, stale generation and membership/capability changes, review/run replay, exact contact unsubscribe, revoked/unknown legacy permission proof, protected card stripping, historical reward exclusion, failed-page retry, original item escaping and private HTML. OAuth/merchant ownership have separate integration and real-MySQL coverage. Protected release must verify the existing provider-terms runbook, revocation/retention duties and connection callback/scopes; source tests do not activate a provider or establish legal approval.

## Private cached purchase-history pages

Core customer profiles now offer **More cached orders** and **More cached payments**
after the first 50 eligible source rows. The same authorized owner/admin can open
`/workspace/customers/square/profiles/{profile}/history?tenant=SLUG&kind=square_order`
(or `square_payment`) as HTML or JSON. Every request rechecks active verified
tenant membership, Customers capability and the Soccer-workspace exclusion.
No Pay or global Marketing role is required.

The encrypted continuation binds the tenant, canonical customer and aliases,
record kind and original source associations. It expires after 30 minutes;
changed identities or namespaces require restarting rather than reusing a page
under another customer. New cache rows are outside the original page boundary.
Rows sort by immutable local cache ID, newest first, so refreshing an older row
does not move it between pages. Recorded purchase dates remain separate from
cache order and freshness. This is a current cache view, not a frozen export.

Pages scan at most 50 rows plus one lookahead. Conflicting customer claims remain
withheld; their scanned rows still advance the cursor, including a completely
withheld page. Coverage shows the actual displayed/scanned counts and the bounded
cache count. Missing rows, deleted cache records and incomplete provider imports
cannot establish complete lifetime history. Restart includes later imports.

Original item labels, quantities, money, location IDs and refund observations keep
their existing meanings. Continuation payloads omit card references and consent
settings. These are private cached reads, not provider fetches, identity reviews,
new imports, customer-account links, marketing permission or rewards operations.
The existing bounded shared shopper/Teams/Pay snapshot is unchanged.

`SquareCustomerHistoryTest` covers multi-page order/payment traversal, refreshed
rows, new imports, withheld pages, expired/forged/cross-kind/cross-tenant cursors,
alias/namespace drift, membership/capability refusal, escaped original labels and
private JSON/HTML. It uses fictional source rows and prevents provider transport;
it is also registered in the existing guarded customer-profile MySQL cohort.

## Reviewed ongoing source reads and external event updates

The source connection screen now offers an explicit owner-reviewed ongoing refresh policy.
`TenantMarketingSetting.square_source_refresh` freezes the original connection ID,
merchant, environment, exact sorted locations, approving actor, recent lookback and
unmatched-contact choice. It defaults off. Connecting/reconnecting Square alone
never enrolls a tenant. Original provider objects remain authoritative; this policy
only permits historical/source cache and explicit association maintenance.

```sh
# Preview current policy and public original-reader bindings; no credentials are printed.
php artisan customer-sources:configure-square-refresh TENANT_ID --actor=OWNER_ID
# Apply using the reviewed current version and original connection from that preview.
php artisan customer-sources:configure-square-refresh TENANT_ID --actor=OWNER_ID --connection-id=ORIGINAL_ID --expected-version=POLICY_VERSION --lookback-days=14 --create-unmatched --apply
php artisan customer-sources:sync-square --tenant-id=TENANT_ID --limit=500
```

The scheduler runs `customer-sources:sync-square --limit=500` every minute only for
enabled reviewed policies, replacing the unusable unscoped Square schedules.
Each source type reads at most the advisory bounded batch before resuming its
original cursor next minute. Orders and payments use `UPDATED_AT` source filters
so older purchases changed or refunded recently can refresh. Same-minute requests
replay their durable original run. A policy, location or merchant change blocks
old work; normal credential renewal may advance generation while retaining the
same original merchant/location policy, and each actual read freezes its current
generation. Disabled/disconnected or inactive-reviewer policies stop reads.

External signed Square notifications use the same policy through the separate
default-off `pos_live.external_sales_ingest_enabled` gate and durable
`event-sales-history` queue. Webhook admission resolves enabled exact original
merchant/environment/location bindings locally without provider HTTP. A worker
retrieves the exact original payment/order/customer ID, rechecks the frozen policy
after HTTP and admits source-only writes under the existing tenant customer fence.
The same event request replays once; a failed read keeps its audit and retries only
that original object. Source revisions/timestamps cannot move backwards, including
completed state or refunds. Staff identities, event allocation and financial
confirmation remain independently reviewed original evidence. No matching dates,
source label, contact or card infer physical event ownership.

Square ListPayments must specify each original location; omitting location reads
only the main location. Its default begin time is one year ago, so a full historical
payment import needs an explicit reviewed business-start date. Multiple-location
pagination retains an opaque cursor bound to the exact locations, original since
value and query mode. SearchOrders date filtering and sort fields match; a
`closed_at` search includes Square's required completed/canceled state filter.
Repeated provider cursors stop with the original checkpoint retained for review.
See [Square SearchOrders date filters](https://developer.squareup.com/reference/square/objects/SearchOrdersDateTimeFilter),
[SearchOrders rules](https://developer.squareup.com/docs/orders-api/manage-orders/search-orders)
and [ListPayments parameters](https://developer.squareup.com/reference/square/payments/ListPayments).

## Reconciled customer workspace and event audiences

`/workspace/customers/reconciliation?tenant=SLUG` shows owned original source link
counts, retained historical associations, exact proven ownership batches, unresolved
records and recent import runs. A reviewed batch fingerprint rejects stale changes.
Only existing canonical customer plus original source ownership can restore a
retained link; conflicting claims and unknown/manual ownership remain visible.
Shopify order source IDs are original `store_key:shopify_order_id` pairs, distinct
from the internal `order` row ID. Proven restoration preserves source metadata,
canonical identity, saved groups, contact permissions, suppressions and rewards.

```sh
php artisan marketing:reconcile-customer-sources TENANT_ID --actor=OWNER_ID --limit=500 --after-id=0
php artisan marketing:reconcile-customer-sources TENANT_ID --actor=OWNER_ID --limit=500 --after-id=0 --apply
php artisan marketing:import-system-audiences TENANT_ID --actor=OWNER_ID
php artisan marketing:import-system-audiences TENANT_ID --actor=OWNER_ID --apply
```

Carry the reported `after_id` across batches. Nine exact unchanged published
legacy starter definitions can be copied as tenant-owned drafts with provenance.
The originals remain unowned and unchanged; arbitrary custom definitions without
ownership proof are never assigned by name. Repeated copies reuse the same draft
and audit. No campaign is created. Tenant-scoped `marketing:sync-profiles
--tenant-id=TENANT_ID` retains unowned Square source rows and counts them for review
rather than guessing their workspace or failing the entire backfill.

The newer Marketing desk's Audiences view links saved groups/rules, source review,
customer history and the typed event audience builder. Dynamic occurrence/series,
product/scent and previous-show-or-nearby rules evaluate the same confirmed purchase
projection as Customers and Teams. Exact identifier operators prevent event 123
matching 1234. Unproven money/order counts remain unknown, not zero. Audience previews
show reasons and eligible channel counts after current permissions, legacy permission
review and suppressions; saving or attribution never sends. Shared customer history
has paginated older purchases and private JSON exposes only scoped contact summaries
and commerce data, excluding raw profile notes or tenant/model relations.

New and explicitly linked existing customers can receive coherent missing saved
location fields from the exact original **Customer API address**. Country/ZIP are
never inferred from a card or billing address. Provenance records the original
source row and address hash on the explicit customer link; differing existing
canonical fields remain unchanged with a visible saved-location review issue.
Saved US country plus ZIP supports the editable approximate Census 50-mile audience.
Unknown or conflicting location/contact permissions stay visible as missing coverage.

Focused regression also includes `SquareSourceRefreshPolicyTest`,
`CustomerSourceWorkspaceTest`, `CustomerSourceOwnershipReconciliationTest` and
`ConnectedEventAudiencesTest`: opt-in and current ownership, late policy revocation,
exact frozen webhook binding, failed-read retry, monotonic refunds, coherent saved
locations, original-page resume, legacy draft-copy replay, older history privacy,
post-sale audience membership and tenant isolation. Actual import count/financial
reconciliation and physical event acceptance remain post-release operations.
