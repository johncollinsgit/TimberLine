# Self-service payments, bank feeds, quotes and invoices

This is the native tenant commerce lane. Stripe/PayPal accounts, payout banking,
optional bank feeds and accounting are separate purposes. A connection never
purchases a module, grants a capability, starts an Everbranch subscription or
proves a customer paid. Canonical entitlements and exact pilot allowlists remain.

## Owner setup

`/payments?tenant=WORKSPACE` starts Accept payments. A verified active owner/admin
can continue hosted Stripe setup, create a business-owned Standard account, or
connect an existing account using OAuth. The latter needs the platform's
`STRIPE_CONNECT_CLIENT_ID` and its registered return URL:
`https://app.theeverbranch.com/payments/stripe/return`.
Register it once for the platform. The workspace comes from owner/session-bound
state, so adding a merchant never requires another provider redirect entry.
OAuth state is actor/tenant/session-bound and expiring. Tokens stay encrypted.
Existing accounts bound to another workspace are rejected rather than borrowed.

Stripe-hosted onboarding collects business verification and payout details.
Configure/verify Financial Connections bank collection in the provider settings;
bank sign-in and manual verification remain provider-hosted. Request no bank
balances/transactions merely to receive payouts. Full Dashboard accounts manage
their own Stripe information. Existing Wren Express/destination routing and
immutable pending Express creation attempts are not converted.

Required setup rollout: TENANT_PAYMENT_SETUP_ENABLED and exact
TENANT_PAYMENT_SETUP_TENANTS, plus EVERBRANCH_TENANT_PAYMENTS_ENABLED and exact
EVERBRANCH_TENANT_PAYMENTS_TENANT_SLUGS. Carolina is the first native-invoice pilot;
authoring can be prepared before the business completes payment onboarding.
No transaction markup is introduced.

## Separate bank feeds

The optional bank-transactions action opens an already-authorized business
Trajectory space. It never creates a household or grants financial access. An
existing QuickBooks view remains authoritative accounting; provider/channel
summaries and bank deposits must not be added again as revenue.

Reuse Plaid Transactions and account mapping. Business Link requests Transactions
only; household optional products retain their existing consent boundaries.
Register `https://app.theeverbranch.com/trajectory` in Plaid and set the identical
PLAID_REDIRECT_URI. The app refuses new bank sign-in without this registered,
HTTPS, same-origin return configuration. A browser OAuth handoff resumes the
original unexpired Link token and server-issued user/space/browser-bound session.
It cannot silently connect into another selected company. Cache evidence is
encrypted; browser session data is cleared after completion/cancellation.

Configure credentials server-side, test real institution/product availability,
and verify OAuth/app handoff, MFA, unsupported bank, cancellation, token expiry,
revocation, reconnect and disconnect. A configured key is not provider acceptance.
Existing feeds/imports remain usable when new sign-in is unavailable.

## Native quote to payment

Enable MANAGED_WEBSITE_INVOICES_ENABLED and exact MANAGED_WEBSITE_INVOICE_TENANT_IDS
only for entitled editor workspaces. Native commerce/editor rollout still applies.
Staff use `/website/invoices` to author a quote or draft, including custom or
server-priced catalog lines. Existing form inquiries link to this authoring flow.
No provider request, inventory reservation or customer email happens on draft save.
A repeated create UUID with changed data fails. Draft revisions retain encrypted
prior snapshots; issued terms are immutable. Use a new draft for changed terms.

Staff explicitly confirms tax amount/basis and pickup/local delivery terms.
A fixed/percentage discount or one coupon is nonstacking. Codes support tenant/site
ownership, product eligibility, dates, minimum subtotal and an atomic maximum
number of issued invoices. Issuing reserves one code use, including if that
invoice is never paid; UI labels this limit accordingly. Disabling a code does
not change an issued invoice. No automatic tax conclusion is invented.

Issuing freezes the final price, discount, tax, delivery, deposit and version.
A quote requires customer acceptance before collection. Each installment must be
at least 50 cents. Customers use private, non-indexed `/invoices/{opaque-token}`
links on the verified tenant host, choose full payment or the deposit, then pay
the exact remaining balance later. Bearer links expose no staff notes or provider
credentials. No checkout starts from a GET or a successful signup redirect.

Invoice checkout version 2 creates direct charges on the frozen merchant account,
without transfers, on_behalf_of, new platform fees or recalculating tax on the
balance. Encrypted payloads and provider keys are persisted before HTTP.
A lost response recovers the same customer/session; expired unresolved attempts
stop before the provider idempotency window lapses. Processor identity, amount,
currency, customer, version, account and environment must all match. Bank-feed
rows and redirect query parameters cannot confirm money.

Checkout temporarily reserves tracked stock. Verified first payment commits it
once; balance payments never commit again. Stock loss/cancellation/mismatched
receipts require review and block fulfillment. Pending or successful refunds
block new balance collection and fulfillment. Refunds target a specific original
payment/account, reserve uncertain amounts and never automatically restock.
The invoice list reports verified native collections, issued discounts/tax, refunds, known USD processing fees, charge net before refunds, and balances separately. Unknown settlement details are labeled incomplete. Payouts and refund fee adjustments remain in the merchant Stripe Dashboard. Bank deposits/imported store sales are excluded.

## Stripe callbacks and bank payments

Direct payments emit Connected accounts events. Configure a separate endpoint
signing secret MANAGED_WEBSITE_STRIPE_CONNECT_WEBHOOK_SECRET and set
TENANT_STRIPE_DIRECT_WEBHOOKS_VERIFIED only after genuine acceptance. The existing
MANAGED_WEBSITE_STRIPE_WEBHOOK_SECRET verifies platform/destination events and
must not be reused as proof for direct events. Ingress selects the secret by
scope, verifies raw signatures/time, and checks frozen event.account/livemode.

Subscribe to checkout.session completed/expired/async_payment_succeeded/
async_payment_failed, charge.refunded, and refund created/updated/failed events.
`website:reconcile-payments` recovers both cart v1 and invoice v2, even while
new checkout is paused. It is already scheduled every five minutes.
New direct attempts freeze stable platform-account + test/live identity, so
routine API-key rotation preserves reconciliation/refunds. Earlier legacy cart
payments retain their key-derived fingerprint: restore the original authorized
credentials or use reviewed recovery; never rewrite evidence or change merchants.

Optional customer ACH is independently default-off via
MANAGED_WEBSITE_INVOICE_BANK_PAYMENTS_ENABLED. The merchant must have active
us_bank_account_ach_payments capability and refresh its verified status. Hosted
Checkout handles Financial Connections bank verification and mandate consent.
Processing is not paid, failed payments release uncommitted stock, and genuine
async success commits once. Bank refunds require a full original-payment refund.
Keep this rollout off until real ACH/return/refund acceptance. Wren is excluded.

## Wren and later providers

Wren frontend v40 fixes mobile word spacing and displays explicit payment choices.
Manual Venmo uses https://venmo.com/u/WrenFamilySoccer and organizer confirmation.
The owner approved Sarah's remaining $60 organizer waiver and Miriam Sinatra's
$30 Venmo receipt. Apply only the preview-first commands documented in the soccer
runbook after deploying, with expected family/invoice/amount and verified actor.
Sarah's waiver adds no collections; Miriam's receipt contributes $30 once. An exact existing manual receipt is attested in place after checkout retirement; never add a duplicate receipt. Reconcile/retire open
card sessions before either adjustment. No fake Venmo transaction ID is created.

Verified Venmo uses Wren's own PayPal merchant account, not the profile balance.
See wren-verified-venmo.md for platform approval, merchant readiness, signed
webhook proof, original-account refunds and duplicate/pending guards. Production
activation remains off until genuine provider/merchant acceptance.

Shop Pay remains merchant-owned and gated by its own Shopify store, Payments,
app/domain setup and reconciliation acceptance. Evergrove's existing Wallet
belongs only to Evergrove. See shop-pay-wallet-readiness.md. No unrelated merchant
is routed through Evergrove's wallet. Retail orders, maker custom orders,
service estimates/jobs/invoices and program registrations/capacity keep their
own records and terminology; these rails do not rename or merge their ledgers.

## Release and rollback

Use protected GitHub/Forge deployment. One additive discount table has a durable
partial-state recovery scenario, canonical disposable MySQL baseline and actual
prior-release upgrade proof. Historical rehearsal explicitly bypasses the current
schema dump so it cannot preload future migrations and falsely pass an upgrade.
No historical migration is rewritten. Disable the relevant invoice, bank-payment,
PayPal or bank-link rollout when stopping new work; retain immutable evidence and
continue authentic reconciliation/refunds. Preserve Wren/Modern Forestry records.

Validate tenant/host/session isolation, unfinished/revoked connections, mobile
handoffs, frozen pricing, deposits/balances, usage caps, stock races, duplicate/
late events, uncertain results, refunds, separate webhook scopes and ACH outcomes.
`payments:health` runs every 15 minutes and records deduplicated, tenant-scoped setup restrictions, old unconfirmed attempts, reconciliation reviews and stale business feeds in the existing Integration Health/operator dashboard. Recovery resolves those events. Deliberate disconnects and household feeds are excluded; it sends no customer messages or provider requests. Reconciliation logs exception class and payment ID only. Watch signed callback delivery in the provider dashboard as well; local pending-age alerts do not prove delivery failure. Never log raw credentials or shopper payloads.


## Pilot release evidence (2026-10-19)

Wren Sites v40 is published. Protected backend PR 427 activated commit 5cf3bdc20f70cb6aad09fb87e8b4b425ecb515e8. The preview-first command retired/reconciled Sarah's card checkout and waived the remaining $60, registering Glory and Eden while preserving Peter/Selah's waivers. Lucy still has no registration. The invoice is waived at $0 with no receipt or added revenue.

Miriam's $30 manual Venmo receipt and Emily's registered place already existed before this release. Preserve that one receipt; the follow-up attestation path retires/reconciles its old card checkout and audits the owner's confirmation in place. Do not create another $30 receipt. Provider-key-only historical evidence is not a new processor verification.

Carolina-only authoring/setup configuration is active: payment-setup and tenant-payment slug allowlists include carolina-barrel-co; invoice IDs are [8]; catalog preview preserves [11] and adds 8. Live commerce, direct-event proof, customer bank payments and verified PayPal remain off. The Plaid return URL is still a provider-setup requirement. No charge, merchant account, payout bank or bank-feed connection is created by enabling authoring.


A separate live Connected accounts webhook is registered as we_1USSyDCb0UT6fSMI2dAR1Qzp at https://app.theeverbranch.com/webhooks/website-stripe, API 2025-06-30.basil, for the eight checkout/refund events above. The signing secret is stored only in the owner-only production environment for activation by this protected release. Existing Wren/platform endpoints are unchanged. Registration is not signed-delivery or live direct-charge proof: TENANT_STRIPE_DIRECT_WEBHOOKS_VERIFIED remains false until real merchant acceptance. No payment was created by this setup.

### Stripe platform configuration (2026-10-19)

The owner signed into the existing Evergrove Software LLC platform,
`acct_1Ttv37Cb0UT6fSMI`. OAuth for Stripe Dashboard accounts is enabled, and the
single registered default redirect is
`https://app.theeverbranch.com/payments/stripe/return`. Its live client ID is staged
in the owner-only production environment as STRIPE_CONNECT_CLIENT_ID. Activate
cached configuration through a normal protected release, then verify both the
finished deployment and the runtime configuration; a same-SHA `/ready` response
alone does not prove a configuration-only redeploy completed. Keep the full
test/build and migration gates enabled. Never print environment files or tokens.

Connect payout settings already use Financial Connections with manual entry as
the fallback. The required account/routing permission is enabled; optional
balances, account ownership details and transactions are disabled. No payout
settings or bank account were changed. These platform settings apply to the
accounts for which Stripe exposes platform collection controls; new full
Dashboard merchants manage their own verification and payout details.

Wren remains on `acct_1USHLXEHU70c1SzP` with its existing Express/destination
routing. Carolina still requires its owner's genuine account connection or
onboarding, and direct-charge/refund callback acceptance before collection. OAuth
registration and payout-setting inspection do not prove a bank connection,
merchant verification, Plaid OAuth, customer ACH or signed payment delivery.
