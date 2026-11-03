# Retail Shop Pay setup and native checkout

Owner scope, November 3: include a setup wizard, settings and checkout options
for all retail customers, including full native Shop Pay checkout source.

## Merchant choices

Verified active workspace owners/admins open `/payments/shop-pay?tenant=SLUG`.
The shared navigation shows this option for the canonical retail/Shopify/hybrid
profile and native Website capability, independently of the payment rollout.
Reading the wizard does not contact a provider. No plan, entitlement, merchant,
billing source or collection flag is changed by opening it.

Shopify-managed retailers enable Shop Pay in their existing Shopify checkout.
The native Wallet choice uses a NEW dedicated Wallet store with that business’s
own Shopify Payments, Shop sales channel, API credentials, signing secret and
allowed website origin. Never reuse Modern Forestry’s Shopify app/store,
Marketplace connection, catalog or order models. See the current
[Shopify Wallet setup](https://shopify.dev/docs/api/commerce-components/pay).

The wizard saves secrets encrypted and never renders or flashes them. Saving
turns off new Wallet checkouts and invalidates previous verification/acceptance.
Identity and payment-free session verification uses the pinned Storefront API
2026-10 Wallet operation. A generic Storefront `shop` query is deliberately not
used as proof. A successful probe does not prove merchant approval, an origin
allowlist, a signed delivery or successful collection.

Subscribe the dedicated app to signed `orders/paid`, `orders/updated` and
`refunds/create` events at `/webhooks/website-shop-pay` on the canonical app host.
Ingress checks the original account’s HMAC, shop domain and supported topic,
then retrieves the original receipt/order. Event financial values are not paid
evidence. Existing five-minute `website:reconcile-payments` scheduling also
recovers submitted attempts and refunds when the browser closes.

## Collection and recovery

Existing Managed Website, tenant entitlement, payment-account, tax and signed
Stripe endpoint requirements remain. Native Wallet adds
`MANAGED_WEBSITE_SHOP_PAY_WALLET_ENABLED`, an explicit tenant ID allowlist,
merchant enablement, fresh verified identity/origin and matching test/live tax
account mode. Both new deployment settings default closed. Live collection
additionally requires operator-reviewed acceptance evidence; an owner checkbox
or API probe never creates `live_acceptance_verified_at` in the encrypted setup.
There is no self-service bypass for that review. No provider is enabled as part
of publishing this source or its wizard.

The buyer chooses cards or Shop Pay on the native cart. Shop Pay supports that
cart’s shipping quote or pickup choice. Manual local delivery and service-only
carts without a tax address use the existing card checkout. To change a shipping
address, cancel the unsubmitted checkout and select a fresh native shipping
quote. The Wallet window cannot silently change shipping costs or delivery mode.

Native checkout serializes under its site/cart lock, reserves the same native
stock and creates one native order/payment plus an encrypted Wallet attempt.
The original Wallet merchant credentials/mode, tax account, source identifier,
session, idempotency key, quoted terms and submission request remain attached to
that attempt. Settings changes cannot redirect recovery to a different merchant.
The active-payment fence covers both Stripe and Wallet, including cart edits.

Tax is calculated server-side in integer cents on the original tenant’s Stripe
tax account. A current reviewed quote is required at submission. Submission is
journaled before HTTP and is not repeated after timeout. Empty/pending receipts
never release stock or allow another payment. Only an unsubmitted cancellation
or an explicitly failed original receipt frees the checkout. A successful
browser return, ready receipt or webhook arrival never marks an order paid.

Reconciliation verifies source, merchant/mode, currency, exact subtotal/line
quantities/prices, tax, shipping, captured transactions and complete pagination.
It commits inventory once and converts the original cart. Missing stock keeps
fulfillment blocked for staff review. Off-Stripe tax posting uses its original
calculation and a durable idempotency key; fulfillment waits for tax posting.
Uncertain tax posts older than 23 hours need original-provider reconciliation,
never a fresh key. They do not erase a verified payment.

Refunds are issued in the original dedicated Wallet store, reached from the
native order’s staff actions. Check Shop Pay payment and refunds retrieves that
original merchant’s evidence, records confirmed refunds without restocking, and
posts each confirmed original refund’s tax reversal once. Pending or conflicting
provider evidence requires review. Native fulfillments remain in Website tables;
there is no legacy Shopify order import or stock/rewards mirror.

## Source validation and release

The two additive Wallet migrations retain financial rows on rollback and recover
missing named indexes without deleting rows. Their real MySQL recovery cases
are registered in the shared manifest and run in normal PR/deploy migration
gates. The data-free MySQL dump/fingerprint includes both tables. No production
data, API secrets or local preview account belongs in Git.

Use PHP 8.4, Node 22 and the protected GitHub/Forge release. Relevant checks are
the ManagedWebsite and Billing feature tests, Wallet MySQL recovery,
`lint:migrations`, migration upgrade/baseline verification, GraphQL validation,
JavaScript/browser checks and the normal production build. Exact commands and
results belong in the PR and shared handoff. Source tests with fictional
providers do not establish actual merchant acceptance.

Before activating a real business, record the exact reviewed live source,
approved dedicated merchant, allowed host, signed event delivery, original
payment/refund/settlement, tax posting/reversal and native fulfillment evidence.
Confirm Shopify and native orders remain separate. That merchant-specific
acceptance and operator review remain outstanding for this source candidate.
