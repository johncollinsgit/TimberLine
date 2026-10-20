# Wren verified Venmo checkout

## Availability and activation

The public `https://venmo.com/u/WrenFamilySoccer` profile link is an external
payment option. A click is not a receipt. Organizer-verified manual Venmo receipts
continue to use `provider=venmo`; actual PayPal Checkout receipts use
`provider=paypal` with `funding_source=venmo` retained in encrypted evidence.
Wren's existing Stripe account, destination and refund routing are unchanged.

Verified Venmo checkout is implemented but remains disabled by default. Neither
local tests nor a connected account constitute provider acceptance or approval.
Before activation, obtain PayPal platform partner approval, enable the correct
merchant checkout product/Venmo eligibility, complete Wren's own seller onboarding,
and verify sandbox/live app ownership, domains, seller permissions and callbacks.
No other business may reuse Evergrove's merchant account for its own receipts.

Configuration belongs in the server secret environment:

- Existing `TENANT_PAYMENT_SETUP_ENABLED` and exact tenant allowlist plus canonical
  Soccer module access; `TENANT_PAYPAL_ENABLED`, environment-specific client ID,
  client secret, partner merchant ID and BN code. Live requires
  `PAYPAL_PARTNER_APPROVED=true`.
- `WREN_SOCCER_PAYPAL_CHECKOUT_ENABLED=false` until acceptance is complete.
- `WREN_SOCCER_PAYPAL_WEBHOOK_ID` identifies the same environment/app's registered
  `https://app.theeverbranch.com/api/soccer/paypal` listener.
- `WREN_SOCCER_PAYPAL_WEBHOOK_VERIFIED=false` until genuine signature/capture/refund
  callback delivery and replay acceptance are recorded. A simulator signature is
  not proof of a genuine merchant transaction.

Register capture completed, pending, denied, refunded and reversed events;
merchant partner-consent revoked; and customer dispute created/resolved. The
handler also accepts refund completed/pending/failed events where supported by the
provider's approved app. Onboarding success never grants collection from a
browser callback; use the existing explicit owner account verification flow.

SDK availability uses saved verified connection identity. Its public profile
contains only public client ID, seller merchant ID, environment, USD and Venmo
funding preference when gates permit it. Secrets and provider evidence never enter
the browser. Create and capture independently check current merchant tracking,
partner PAYMENT/REFUND scopes, confirmed email and receivable state with PayPal.
The setup screen's 15-minute badge does not require organizers to recheck every
15 minutes. A local disconnect always wins; provider checks cannot reconnect it.

## Payment and reconciliation contract

Authenticated, HMAC-bridged POST actions:

- `paypal-create {invoice_id}` returns `{order_id,currency:"USD",amount_cents}`.
- `paypal-capture {invoice_id,order_id}` returns
  `{status:"paid"|"pending"|"review",invoice_id}`.
- `paypal-status {invoice_id}` retrieves the original stored order and reconciles
  provider evidence. It never starts another capture.

Family profile `paypal.invoice_states` exposes only that family's pending/review
invoice IDs, including while new checkout is disabled. Pending/review suppresses
payment alternatives after reload. SDK eligibility or load failure retains the
ordinary profile link when no payment is uncertain.

The server owns prices, merchant, currency, invoice references and request IDs.
Encrypted `soccer_events` stores one frozen `paypal_order_attempt` per invoice
before network requests; its unique event key prevents creating another order
because a request timed out. PayPal request IDs are unchanged on replay. The
conservative six-hour Orders retry limit stops uncertain recovery before its
documented minimum idempotency retention expires. Never delete reservations,
change frozen payloads or switch providers to work around an uncertain charge.

Before capture, the shared season lock covers retiring/reconciling any existing
Stripe session and committing the PayPal capture reservation. Card checkout,
manual Venmo and waivers reject pending/uncertain/review PayPal attempts. An
already settled Stripe payment is retained and prevents a new PayPal capture.
An approved PayPal order cannot capture after its invoice is paid, waived or
expired. A definitively declined attempt may use another listed payment method;
it is never silently retried as a new order.

The backend reads the order from PayPal and verifies the frozen order/invoice,
payee, amount, USD, intent and completed final capture before calling the existing
receipt service. HTTP success, SDK approval, link click and a browser return do
not mark paid. Provider=`paypal` plus capture ID deduplicates receipts and sends
the existing organizer tally/parent confirmation once. Late or competing receipts
are retained for review without granting places.

Webhook verification calls PayPal's signature-verification endpoint with the
registered app webhook ID and actual transmission headers. Signed events re-read
provider orders/refunds; they do not trust event amounts or browser seller IDs.
Replay is deduplicated only after processing succeeds. Collection flags may be
disabled while original-account receipts and refunds continue reconciliation.
Environment/platform identity changes stop recovery rather than reroute money.

Refunds reserve the amount locally first, use the original capture/seller context
and a stable refund UUID request ID, then verify the refund, original order,
amount, currency, local refund invoice reference and capture association. Pending
or uncertain refunds reserve funds; only verified completion increments refunded
totals once. A lost POST response can be recovered from its signed refund event
and immutable `wren-refund-<UUID>` reference. No background blind refund retry is
performed. Revocation/dispute/reversal events never silently withdraw children;
disputes/reversals create a provider-review audit entry.

## Verification and recovery

Run `php artisan test --compact tests/Feature/Soccer
tests/Feature/Billing/TenantPaymentSetupTest.php` before the protected release.
The PayPal suite uses fictional responses, prevents stray network calls and covers
identity, price/currency spoofing, permissions/disconnect, capture uncertainty,
expired places, original-account refunds, signature rejection/replay and family
scope. Frontend acceptance must cover supported iOS Safari/Android Chrome,
desktop QR, SDK ineligibility and failure, returning from Venmo, cancellation,
refresh during pending status, and receipt/tally dedupe.

For a pending attempt, use `paypal-status` or the authenticated provider dashboard
with the saved original order/capture IDs. Older unresolved reservations stop new
payment collection until an operator reconciles actual provider evidence. Keep
encrypted attempts, receipts and audit entries. If credentials or permissions are
unavailable, restore the original authorized context; do not invent a receipt,
rebind a merchant or create a replacement charge. Production activation still
requires real provider acceptance, a controlled merchant test and verified
callback/refund evidence; local test success does not satisfy those gates.

Primary references:

- https://developer.paypal.com/platforms/checkout/standard/integrate
- https://developer.paypal.com/v5/venmo/overview
- https://developer.paypal.com/api/rest/integration/orders-api/api-use-cases/advanced/
- https://developer.paypal.com/api/payments/v2/captures-refund
- https://developer.paypal.com/payment-methods/webhooks/
- https://developer.paypal.com/platforms/seller-onboarding/after-payment/
- https://help.venmo.com/cs/articles/business-profiles-faq-vhel143
