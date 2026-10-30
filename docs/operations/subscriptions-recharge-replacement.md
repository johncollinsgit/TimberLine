# Recharge replacement: implementation and acceptance plan

Owner direction confirmed October 30, 2026: Everbranch Subscriptions replaces
Recharge's recurring billing and member management. Preserve existing Braintree
and Shopify-vaulted methods wherever their continuing authorization and supported
access can be verified. Stripe setup is an explicit fallback, not a universal
migration requirement. The first rollout is the two already prepared members;
all other members retain Recharge billing until separately reviewed.

## Existing implementation examined

| Existing component | Retain and extend | Current limit |
| --- | --- | --- |
| `SubscriptionAccessService` | Entitlement, active membership, exact persisted store/app binding | Generic provider execution deliberately closed |
| Shopify installation/credential/operation/resource/inbox services | Revisioned tenant-owned access, bounded owned-contract reads, encrypted evidence and raw HMAC verification | Read operations do not authorize contract adoption or mutations |
| Recharge connection/transport/readiness/source guard | Original store/customer/subscription mapping and fresh queued/collected-charge checks | No automatic source cancellation; incomplete reads block cutover |
| `SubscriptionPilotExecutionService` and cycle models | Reviewed exact economics, original anchor, durable request, no double collection, lost-response recovery, pause/cancel pattern | Stripe-specific authorization, fields and verification |
| `SubscriptionPilotShopifyBridge` | Paid-order product/customer mapping, inventory policy, original-result recovery | Separate original Stripe/Braintree verification; Shopify billing attempts create their own orders |
| `TenantBraintreeConnectionService` | Encrypted tenant connection and exact account/customer/method reads | Connection/verification only, no collection permission |
| `SubscriptionModuleService` and native Candle Club UI | Existing identities, votes, scent catalog, private ratings/comments, validated action payloads | Most management actions are review requests; pilot membership is separate |

Historical intents, imported mirrors, manual payment labels and matching emails
must never become executable payment instructions. Preserve the existing Stripe
pilot's independent behavior and all paid orders/fulfillments.

## Build order

1. **Shared billing ledger and controlled enrollment.** Separate the billing
   owner (Recharge/Everbranch) from the processor (Braintree/Shopify/Stripe).
   Reuse prepared member identities. Retain encrypted reviewed terms, exact
   source and reusable-method mappings, versioned amendments, unique cycles and
   immutable provider requests. Preparation cannot stop Recharge or collect.
2. **Braintree execution.** Keep the original merchant/account and vaulted method.
   Verify original-source payment mapping and continuing recurring authority.
   Submit one recurring sale per durable cycle; recover uncertainty by original
   transaction/order identity, never blind-retry a sale. Track processing,
   declines, settlement, refund and dispute state independently of Shopify.
3. **Shopify-vaulted execution.** Verify the installed subscription app, granted
   scopes, customer payment-method identity and supported contract migration.
   Own-contract API access does not grant ownership of Recharge contracts.
   Use Shopify billing attempts for this rail; its resulting order is not
   recreated through the external-payment bridge. An API key alone is not proof.
4. **Effective customer controls.** Connect the existing authenticated web/app
   surfaces to the new agreements. Implement pause/resume, one-cycle skip,
   cancellation, address and eligible scent changes with idempotent action IDs,
   cutoff/processing checks and future-term revisions. Preserve existing cycle
   requests, orders and labels. Reuse voting and private scent feedback.
5. **Operations and recovery.** Provider-specific dunning and customer action
   flows, original-payment refunds, signed events plus current reads, paid-order
   and fulfillment reconciliation, durable alerts and explicit operator recovery.
   Uncertain results block new payment attempts. No marketing is sent by setup.
6. **Dark release, then two-member acceptance.** Run migration recovery,
   concurrency, tenant isolation, provider sandbox and web/app checks. Verify
   each selected source is stopped and no queued/collected duplicate exists
   before individual activation. Confirm one payment, one paid Shopify order,
   expected inventory/shipping/notifications and working member controls.
   Expand only after a reviewed cycle; retain all other Recharge subscriptions.

## Gates and proof

Setup, mutation, collection, Shopify handoff and actual merchant acceptance are
separate default-off controls with exact tenant/member rollout. Connecting a
processor, an owner checkbox, a completed test or a redirect cannot enable live
billing. Supported existing authorization is retained only after exact mapping
and continuing authority are verified; unsupported/expired/revoked methods use
a separate customer authorization flow.

Rollback stops new attempts while retaining every agreement, original request,
payment, refund and order mapping. Never automatically re-enable Recharge for
an unknown or already collected cycle. Reconciliation precedes any resumption.

Preserve Shopify order/fulfillment behavior and verify downstream automations.
Provider/app/source labels can differ; an invisible backend cutover is not yet
proven. The two testers are acceptance cases, not hard-coded customer rules.

## Implementation checkpoints

- [x] Inventory existing services, schema, customer surfaces and tests.
- [x] Record replacement scope and retain existing-method reuse as the priority.
- [x] Encrypted provider-neutral ledger, controlled preparation and original-anchor operator schedule controls.
- [x] Recorded gateway badges in Everbranch customer purchase history, with bounded history enrichment.
- [x] Source implementation for exact Braintree method/source mapping and gated individual activation.
- [x] Source implementation for Braintree execution, original-transaction recovery and owned Shopify order/fulfillment reporting.
- [ ] Customer-facing future-term changes and original-authorization amendment handling.
- [ ] Actual Braintree merchant sandbox/live acceptance and original source-stop verification.
- [ ] Shopify-vaulted contract ownership/migration and billing attempts.
- [ ] Customer membership/control parity, dunning, refunds and reconciliation.
- [ ] Protected release and actual two-member acceptance.
- [ ] Reviewed wider migration and eventual Recharge account retirement.

## First source checkpoint

The three new tables are `subscription_billing_agreements`,
`subscription_billing_cycles` and `subscription_billing_actions`. Composite tenant
ownership, named recoverable constraints, immutable original requests and
encrypted/hidden terms, method bindings and audit payloads preserve the existing
Stripe tables without reinterpreting their fields. No released migration changed.
MySQL 8.4 partial-DDL recovery and a data-free schema baseline are included.

`SUBSCRIPTIONS_REPLACEMENT_SETUP_ENABLED`,
`SUBSCRIPTIONS_REPLACEMENT_SERVICING_ENABLED`, exact
`SUBSCRIPTIONS_REPLACEMENT_TENANT_IDS` and exact
`SUBSCRIPTIONS_REPLACEMENT_MEMBER_IDS` all default off/empty. These authorize
local preparation/servicing; separate execution controls are listed below.
An active verified workspace owner/admin and the existing Subscriptions
entitlement are required. There are no seeded tester names or email bypasses.

`subscriptions:replacement summary --tenant=WORKSPACE --actor=ID` is offline.
`prepare --member=ID --file=/absolute/private.json` previews reviewed terms and
returns a digest. The file must be a regular nonsymlink 0600 JSON file, at most
64 KiB. `--apply --reviewed-hash=DIGEST` saves only a prepared agreement. Method
tokens, original authorization references and addresses remain encrypted and are
not printed. Source amount/products/shipping/tax, future date and the original
tenant-owned processor identity must match. Review checkboxes do not establish
provider method verification or continuing collection authority. Mixed/prepaid
schedules remain unsupported by this first slice.

`pause`, `resume`, `skip` and `cancel` require `--agreement=ID --revision=N` and
`--request-id=UUID`; they preview by default and persist only with `--apply`.
They reject a prepared/Recharge-owned agreement. Individual Braintree activation
is separate from these schedule controls and repeats current provider/source checks.
Unchanged IDs replay the original result; stale/conflicting reviews fail.
Skips and resumes preserve the original month-end anchor and prior skips.
Unknown/prepared/in-flight cycles block schedule edits. Cancellation stops future
billing while retaining an original uncertain payment for reconciliation; it
does not void/refund that payment. Existing Stripe creation/collection refuses
an already activated replacement agreement, even if an old ownership flag is stale.

Customer address/scent/payment changes and customer web/app access are not wired
to these agreements yet. Neither a model status nor an operator schedule action
is proof that the full replacement is ready.

## Braintree execution source checkpoint

`SubscriptionBraintreeMethodService` links an original paid Shopify order's exact
Recharge subscription/customer/payment references, products, amount, shipping,
tax and address to an original settled Braintree sale and its current vaulted
token. It uses the original tenant-owned connection and official SDK, not an
email match. Active unexpired cards and nonrevoked PayPal billing agreements are
supported; shared-vault grants and active native Braintree subscriptions need
separate review. Verification performs no sale, hold, vault change, new consent
or source cancellation. Continuing authorization must already be reviewed in the
prepared agreement; technical mapping does not replace actual merchant acceptance.

Separate controls all default off:

- `SUBSCRIPTIONS_REPLACEMENT_ACTIVATION_ENABLED`
- `SUBSCRIPTIONS_REPLACEMENT_COLLECTION_ENABLED`
- `SUBSCRIPTIONS_REPLACEMENT_HANDOFF_ENABLED`
- `SUBSCRIPTIONS_REPLACEMENT_BRAINTREE_COLLECTION_ENABLED`
- `SUBSCRIPTIONS_REPLACEMENT_BRAINTREE_LIVE_ACCEPTANCE_VERIFIED`

These also require setup, exact tenant/member allowlists, the active verified
owner/admin and the original persisted account/credential revision. Production
additionally requires an audited per-connection mapping/polling acceptance that
matches the current credential fingerprint. Setting an environment flag alone
does not satisfy that merchant proof. Sandbox attempts require an isolated
Shopify development store and test source orders; they cannot decrement stock
in a production Shopify store.

`subscriptions:braintree summary --tenant=WORKSPACE --actor=ID --agreement=ID`
is offline. `verify --source-order=SHOPIFY_ORDER_ID` previews current owned
Braintree/Shopify reads; only `--apply` persists masked proof and audit evidence.
`accept --reviewed-hash=HASH --apply` pins the freshly verified merchant mapping
and transaction-polling acceptance without collecting or activating.
`activate --source-connection=RECHARGE_CONNECTION_ID --reviewed-hash=HASH
--revision=N --apply` repeats method/current-product/Shopify permission checks
and fresh exact-source stopped/queued/collected checks. Only then does it switch
that member's billing owner to Everbranch. It does not cancel any Recharge
subscription, change other members or send a payment.

`collect --apply` is limited to the original current billing day at/after the
reviewed hour. It repeats exact source-stop/charge coverage immediately before
reserving the immutable cycle. One database-serialized dispatch marker is
durable before one recurring SDK sale, using the original merchant account,
saved token and stable `ebsub-UUID` order ID. No automatic sale retry is used.
Lost, timed-out, zero-result or mismatched responses remain unresolved; subsequent
collection calls reconcile that original request and cannot send another sale.
Even a definite decline retains its original attempt. A future reviewed dunning
attempt ledger is still required for safe retries.

`reconcile --cycle=ID --apply` searches the original order ID, reads the original
transaction and a bounded complete customer/account billing window. Multiple
matches or additional transactions persist a duplicate-review block. Settlement,
refund and dispute reads update the original cycle. A restarted source blocks
the first fulfillment until fresh proof recovers; a genuinely observed source
collection remains a duplicate alert even after source billing is stopped.
Refund/dispute/uncertainty blocks another cycle and never reenables Recharge.
The original monthly anchor advances once after verified settlement and source
coverage. Source reads currently require a recent seven-day cycle for first
handoff; older unresolved collection needs operator review. Eventual Recharge
retirement needs a separately verified source-retirement policy; this pilot does
not silently remove source coverage checks.

The Braintree path reuses the existing paid external Shopify order bridge with
its own receipt/reference/account attributes and an exact original-payment read.
Only settled, nonrefunded, undisputed transactions with fresh source coverage
permit a first handoff. The durable original request/tag recovers a lost order
response without another create. Original products, prices, tax, shipping,
customer, payment receipt, test mode and amount must match. Inventory obeys
Shopify policy; receipt and fulfillment emails remain disabled by this request.
Order/fulfillment projection requires the persisted original cycle/order link,
not just an order note. The external receipt never becomes another payment.
The pilot dashboard includes observed production Braintree collections, excludes
sandbox/refunded/disputed money, and retains duplicate-review alerts. These
operational observations are not accounting revenue.

`subscriptions:replacement-process` is offline by default. Its `--collect
--reconcile --limit=20` scheduler runs every five minutes with overlap protection,
exact allowlists and the independent gates. It polls unresolved/recent cycles
and older retained receipts daily within the reviewed window. The worker never
resubmits an original sale or changes provider context. Braintree's transaction
settlement webhooks cover ACH/SEPA rather than general card/PayPal sales, so this
path uses current SDK reads instead of depending on an unavailable settlement
webhook. Signed dispute/revocation events remain a later operations requirement.

No provider credentials, real sale, source stop, customer activation, hosted
fulfillment acceptance or production deployment has been performed by this
checkpoint. Customer controls, Shopify-vaulted contract adoption, safe dunning,
refund execution and the two-member acceptance remain outstanding.

## Customer purchase history gateway icons

The existing Everbranch customer profile Orders table now has a Payment gateway
column. Bundled Braintree, Stripe, PayPal, Shopify and Square icons include text
labels; Shop Pay/other methods use a neutral icon with the reported name. The
separate Square payment records also show their known source. No remote icon
service is contacted. Source and license accompany the bundled SVGs.

Normal Shopify ingestion retains minimal gateway names and source time in
`Order.attribution_meta.payment_gateways`, not raw receipts, tokens or card data.
Successful SALE/CAPTURE gateways take precedence when supplied; otherwise names
are explicitly reported gateway evidence, never confirmation of payment success.
Older/partial payloads do not erase a newer known observation. Existing unknown
history stays Not recorded until refreshed.

`customers:payment-gateways --tenant=WORKSPACE --actor=ID --profile=ID --limit=10`
previews fixed query-only reads for at most 20 of that profile's linked Shopify
orders. It requires active verified customer management access, an exact
tenant-owned persisted store credential and both local order/customer links.
Shop/app/customer identity and credentials are rechecked after I/O. Reads must
return API 2026-10; failure saves nothing. `--apply` writes only gateway metadata
in one transaction without order model events, loyalty, messages, payment or
Shopify changes. It does not run on profile load. Older-order visibility depends
on the installed app's Shopify permissions. Its GraphQL document was validated
against the 2026-10 schema.

References: [Braintree recurring sales](https://developer.paypal.com/braintree/docs/reference/request/transaction/sale/php),
[Braintree transaction states](https://developer.paypal.com/braintree/docs/reference/response/transaction/php),
[Braintree settlement webhook coverage](https://developer.paypal.com/braintree/docs/reference/general/webhooks/transaction/php),
[Shopify subscription contracts](https://shopify.dev/docs/apps/build/purchase-options/subscriptions/contracts),
[Shopify payment-method migration](https://help.shopify.com/en/manual/products/purchase-options/subscriptions/migrating-payment-methods).
