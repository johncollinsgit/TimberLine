# Everbranch POS: Square iPhone pilot

Source candidate, collection disabled by default. Alpha Printing and Modern
Forestry are the first intended merchants. No merchant, entitlement, staff,
device, tax rate or production credential is seeded by this implementation.
The fictional public demonstration and older simulator remain separate.

The native CI job creates and fully boots its own iOS 26.2 iPhone simulator,
then runs the sandbox launch test without parallel simulator clones. Startup
and testing have explicit time bounds; the XCTest result bundle is retained
for three days for diagnosis. A timeout is a failed gate, not payment evidence.

## Implemented composition

- `native/everbranch-pos-ios`: dedicated iOS 17.1+ SwiftUI target, bundle
  `com.everbranch.pos`, Square Mobile Payments SDK exactly 2.7.0. Native Square
  payment UI stays inside this application. The field application's deployment
  target is unchanged. Simulator and unsigned device builds are engineering
  evidence only.
- Independent canonical `pos` entitlement. Explicit current membership, verified
  email, explicit payment token abilities, register assignment and install ID
  gate native access. Administrative browser sessions cannot authorize native
  API requests. Refunds require a manager assignment and appropriate membership.
- `pos_live_*` runtime/tenant-scoped encrypted sale snapshots, immutable merchant
  bindings, unique requests/source claims, location stock holds, audit records
  and durable outbox. The retained MySQL payment journal is authoritative.
- The server owns item prices, reviewed integer tax, quote revisions, totals,
  SDK attempt UUID and separate stable Square reference. The native callback
  supplies a hint; fresh original-account Square retrieval confirms payment.
- Receipts are printable/shareable. Original-account full/partial refunds use
  durable request IDs and the journal's serialized original-payment budget.
  Refunds do not automatically restock. No Everbranch processing markup.
- Independent Enterprise composition is opt-in. It imports an explicit pinned
  schema list into its own database, with its own ENV, key, session, cache,
  storage and callback configuration. It does not load retail routes/providers
  or Shopify/Website tables. It supports native location stock, register/device
  assignments, audited adjustments and restricted/consolidated reports.

## Online-only and interrupted payment recovery

Collection requires connectivity. Offline card collection, holding card details
and submitting a charge later are deferred to a future release, as confirmed
2026-10-24. That release also needs an explicit provider-level idempotency contract
for offline submission and retries. Existing online duplicate-payment protection
remains mandatory in this pilot. Android, Stripe, browser readers, Dejavoo, cash, deposits, split
payments, tips, gift cards and postage remain deferred.

Persist only recovery identifiers in device-only Keychain before requesting a
quote, payment descriptor or refund. Never persist a descriptor/access token or
card data in that journal. A descriptor is issued at most once. After termination
or a lost response, inspect/reconcile the original sale; do not request a new
charge. A quote with no provider intent or journal operation can be discarded or
expired. A missing payment is not proof of cancellation: unknown outcomes keep
stock held and require reconciliation. SDK cancellation alone is not unpaid
provider evidence. Do not release stock or force a paid/failed state by editing
the journal to bypass uncertainty.

The minute worker selects unresolved operations and outstanding effects without
allowing historical completed sales to starve recovery. Effects retry with
backoff; after eight failures they require review. Existing payment recovery and
original-account authorized refunds remain available when collection is paused.
Revoked staff cannot service payments; the separately scoped recovery worker
can recover existing operations, but cannot initiate a collection or refund.

## Merchant workflows

Alpha Printing uses a real, explicitly reviewed native register catalog and
existing issued/accepted Website printing quotes belonging to its workspace.
The quote picker shows bounded approved invoices. Issued terms are frozen;
collect the complete approved total once. Online and counter checkout serialize
on the same site/order locks. A pending online payment blocks counter collection;
a counter claim blocks online checkout. Tracked merchandise reserves before
collection and commits once; printing services consume no merchandise stock.
No Managed Website entitlement is granted by POS.

Modern Forestry retains its workspace and tenant-owned Shopify connection. Bind
one reviewed store, physical fulfillment location and at most 200 variants.
Shopify supplies live prices, tax and availability. The pilot requires USD,
exclusive reviewed tax and tracked variants with inventory policy DENY. Tax
inclusive configurations fail closed for a later implementation/review.
Before payment, create one correlated PENDING order with
DECREMENT_OBEYING_POLICY. Validate its total, tax, quantities and assigned
fulfillment location. Commit creation intent before HTTP. After ambiguity,
recover by the original unique correlation; never create a replacement order.
Only after verified Square payment, mark that order externally paid, verify its
new manual transaction and complete counter fulfillment. Cancel/restock only
with definitive unpaid evidence. Unknown payment/commerce phases retain stock.
Square refunds precede manual Shopify refund bookkeeping; no automatic restock.

No Square catalog/inventory mapping is created. Shopify and Website orders retain
their own reporting lanes. Exact store/order correlations exclude pending POS
Shopify stock commitments until payment is confirmed. Native POS adds one operational channel; exact Square
order/payment correlations suppress its duplicate provider representation in
consolidated reporting. Operational totals are not additional accounting revenue.

## Readiness and owner setup

1. Reconcile protected CI and migration recovery checks against current main.
   This branch retains the tested contracts from draft PRs #465/#469 and merges
   current main; it is not a production activation.
2. Confirm the real Alpha workspace/owner; preserve demo history separately.
   Confirm Modern Forestry's existing workspace/store/location and seller.
   Enable the independent POS entitlement through the normal audited commercial
   workflow. Verify each merchant's actual Square rate; published pricing is not
   evidence of an individual account's contract. Add no subscription or markup.
3. Create/register the Square application and its exact production bundle/team
   signature. Owner OAuth is CSRF/session/state-bound at POST `/pos/setup/square`
   and GET `/pos/setup/square/callback`; the manager page exposes Connect Square.
   It requests merchant profile, payment/in-person and payout-read permissions.
   Credentials are encrypted per original connection. Reauthorization preserves
   that connection identity. Expired/revoked authorization blocks provider calls;
   reconnect the same account through the owner flow before recovery resumes.
   Never replace it with another merchant account.
4. Verify Apple developer ownership, Tap to Pay development AND distribution
   approvals, the real iPhone/OS, connectivity, location access, installation
   route and Square seller permissions. A Square app already on the phone does
   not authorize the Everbranch bundle. A source entitlement file is not approval.
5. Set the explicit `POS_*` values in `config/pos_live.php`. Use a stable unique
   `POS_RUNTIME_ID`, MySQL and a shared lock-capable store. Keep collection off.
   Configure the exact HTTPS OAuth and webhook URLs and verified signing secret.
   Webhooks verify the raw signature and enqueue recovery only. Verify real
   signature delivery before setting its readiness flag. Never put OAuth secrets
   or merchant tokens in the Xcode project or a review document.
6. With an existing verified owner and private mode-0600 reviewed register JSON:
   `php artisan pos:provision /private/register.json --actor=OWNER_ID` previews;
   add `--apply` for the reviewed configuration. It never grants entitlement or
   enables collection. Fields are tenant_id, register_id (32 lowercase hex),
   connection_id, location_id, location_name, name, timezone, catalog_source,
   configuration, assignments and optional native items. Assignments contain
   user_id, device_id and cashier/manager/admin role. Configuration includes
   catalog_reviewed, tax_reviewed and tax_valid_until. Alpha additionally binds
   website_site_id. Shopify binds store_id, shop_id, shop_domain, location_id,
   variant_ids and reviewed tax_address inside configuration.shopify; mark
   shopify_acceptance_verified only after its sandbox/stock acceptance passes.
   Native items contain sku, title, kind, unit_amount_minor, tax_bps, tax_basis,
   tracked and quantity. Quantities cannot be silently reset by reprovisioning.
7. `php artisan pos:readiness --tenant=TENANT_ID` is saved evidence only. It
   identifies configuration blockers and saved physical acceptance; it does not
   inspect a phone or independently establish current Apple/provider approval.

## Exact build and controlled physical acceptance

Use a unique CFBundleVersion for each release; never reuse an accepted build ID.
Xcode settings are POS_API_ORIGIN (canonical HTTPS host, optional isolated
Enterprise mount), POS_SQUARE_APPLICATION_ID (public ID),
POS_PAYMENT_ENVIRONMENT, CURRENT_PROJECT_VERSION and the approved signing team.
The SDK is pinned through SwiftPM. Its official setup phase embeds its frameworks;
ad-hoc signing exists only for local CODE_SIGNING_ALLOWED=NO checks.

Archive using the approved distribution route. Run
`scripts/qa/everbranch-pos-archive.py ARCHIVE --team TEAM_ID --square-application PUBLIC_ID --build BUILD_ID`.
It verifies local codesigning, profile expiration, distribution, bundle, team,
entitlement and public application configuration. It does not verify Square's
external signature registration or perform a physical payment.

`pos:activate TENANT_ID REGISTER_ID --actor=OWNER_ID --review=/private/review.json
--build=BUILD_ID` previews, then `--apply` activates only if readiness passes.
Review JSON binds tenant_id, merchant_id, location_id, actual_processing_rate,
everbranch_markup_minor=0 and paid_square_subscription_added=false.
Before initial acceptance, additionally use `--acceptance-device=INSTALL_ID`,
`--acceptance-minutes=60` and `--acceptance-cap=500`. A session is restricted to
that assigned owner/device/build, at most two hours and $10 total lifetime
collection for that register; declines/cancellations do not consume the cap.
Changing these flags does not create any Apple or Square permission.

Perform, separately for each merchant, a consented physical iPhone sale, receipt,
original-account refund, stock/commerce inspection and settlement inspection.
Exercise decline/cancel, duplicate submission, app/network interruption before
and after SDK collection, missing callbacks and key rotation. Verify Shopify
online/POS contention, delayed creation/fulfillment and correct location. Verify
Alpha concurrent online/counter collection. In Enterprise verify two locations,
manager restrictions and complete runtime separation. Retain exact-build proof.

`pos:accept TENANT_ID SALE_ID --actor=OWNER_ID --evidence=/private/evidence.json`
previews; `--apply` records acceptance after current original-account payment,
refund and PAID payout-entry evidence matches the sale/location and all effects
complete. Evidence fields are sale_id, build, physical_iphone_observed,
receipt_shared_or_printed, stock_and_refund_reviewed, signed_archive_sha256
and payout_ids (at most five). Physical observation and archive identity are
owner attestations; payment/refund/payout matching comes from Square retrieval.
Then activate that exact accepted build without the acceptance-device option.
A later build requires its own physical acceptance.

Enable the two pilots separately after their own complete gates. Expand only
after three trading days with no unresolved duplicate-payment, inventory or
reconciliation discrepancies. Do not label transactions completed in the existing
Square app as Everbranch acceptance. Until the gates pass, use the existing
Square checkout for actual show sales. No launch date is committed.

## Operations, pause and release

The provider registers `pos:reconcile --limit=100` every minute only when POS and
servicing are explicitly enabled. The independent Enterprise scheduler must run
in its own runtime. Run bounded `pos:reconcile --all --tenant=ID --limit=500` for
daily original-account review, repeat through the cohort as required, and compare
Square settlements with Shopify/Website orders and the payment journal. Do not
add provider payment records to order revenue a second time.

Monitor `pos.reconciliation_attention`, unresolved payments older than five
minutes, pending/review outbox effects, paid orders awaiting synchronization and
stock conflicts. Wire the log event/nonzero worker result into the existing alert
receiver and prove delivery in acceptance; a log record alone is not proof an
operator was notified. Diagnose original provider/commerce evidence before
reviewing/replaying an effect. Never reset the charge, refund or Shopify creation
claim to force a second money movement/order. No automated queue retry charges a
customer again.

The manager page is `/pos/admin?tenant=SLUG`, or the isolated Enterprise
`/enterprise/pos?tenant=SLUG`. Pause all new collection with its owner control;
status/recovery/refunds retain independent servicing gates. A global collection
freeze is POS_LIVE_COLLECTION_ENABLED=false (Enterprise uses its own prefix).
For rollback, disable collection, preserve servicing, additive tables, encrypted
keys/previous-key recovery and financial evidence. Do not run destructive down
migrations or revoke historical merchant access while recovery is outstanding.

Production remains protected CI -> existing Forge release process -> exact
`/ready` release verification. This change does not deploy or modify production
feature flags. New migration recovery tests and the data-free MySQL baseline
are mandatory. Enterprise adds POS schema only through the explicit opt-in
`migrate --path=apps/enterprise/database/pos-migrations`; its ordinary schema and
required isolated configuration must already exist. Never migrate the retail
tree in Enterprise.

## Local evidence (2026-10-24)

Observed a paired iPhone 15 Pro Max reporting iOS 27.0.1. Compatibility and
physical collection were not verified. No local provisioning profile matches
com.everbranch.pos or its Tap to Pay entitlement. Existing development signing
identities do not establish distribution approval. No real merchant grant,
account rate, Shopify acceptance, customer sale, refund or settlement was
observed. Simulator and unsigned device checks passed; see the implementation
PR for final automated checks. Production stays disabled.

References: [Square SDK](https://developer.squareup.com/docs/mobile-payments-sdk/ios),
[Tap to Pay](https://developer.squareup.com/docs/mobile-payments-sdk/ios/tap-to-pay),
[Square pricing](https://developer.squareup.com/docs/payments-pricing),
[Shopify inventory policy](https://shopify.dev/docs/api/admin-graphql/latest/enums/OrderCreateInputsInventoryBehavior),
[external payment recording](https://shopify.dev/docs/api/admin-graphql/latest/mutations/orderMarkAsPaid),
[Square payouts](https://developer.squareup.com/reference/square/payouts-api/get-payout).
