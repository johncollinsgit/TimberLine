# Stripe and PayPal support

Approved direction: 2026-10-16. Stripe and PayPal are the primary payment
options. The owner resumed Shop Pay integration on 2026-10-16 while PayPal
partner review is pending. ACH is not part of this work.

## Customer experience

Tenant owners open Payments, activate cards/digital wallets with Stripe and/or
connect their existing PayPal business account. Prefill known information;
identity, bank details and provider consent stay in provider-controlled forms.
No tenant API-key entry. Show a specific next step for incomplete setup.
The buyer sees only payment methods actually available for that checkout.

Customer payments belong to the tenant's merchant account. Evergrove's own
account is for its own software and services. Do not route unrelated tenant
sales into the Evergrove account. Do not automatically switch providers after
a failed or uncertain payment.

## Delivery sequence

1. **Connection setup (deployed; Wren setup page enabled).** One
   gated Payments page; Stripe embedded onboarding plus hosted fallback;
   existing-account PayPal Partner Referrals flow; server verification; clear
   connection/checkout separation. Fix Wren's Stripe retry/email error. No new
   schema and no customer charges or subscription changes.
2. **Provider approval and sandbox proof.** Activate the Everbranch Connect
   platform. Obtain PayPal platform partner approval and separately scoped
   sandbox/live credentials, partner merchant ID and BN code. Configure signed
   webhook endpoints for onboarding, revocation, capture, refunds and disputes.
   Demonstrate real sandbox onboarding, withdrawal/revocation and resumption.
   Local fake-provider tests are not proof of provider acceptance.
3. **Customer-to-tenant checkout.** Keep existing Stripe collection available
   only behind its current gates. Add PayPal Orders create/approve/capture,
   durable frozen amount/currency/payee/idempotency evidence and reconciliation.
   Start with soccer invoices, then native storefronts. Soccer capacity/offer
   expiry, no waitlist charge, coach waivers and jersey totals stay authoritative.
   Native tax, inventory, shipping quotes and order records retain their existing
   gates. Configure explicit PayPal tax calculation/commit/reversal before native
   retail activation; never assume zero tax. Freeze the provider on each attempt,
   serialize payment-method changes and reconcile uncertain attempts before a new
   charge. Verify capture with the provider before marking a payment paid.
4. **Tenant-to-Evergrove billing.** Keep Stripe Billing as the default. Add an
   opt-in direct PayPal Subscriptions lane for eligible direct tenants, using
   exact accepted agreement/version/prices and verified provider lifecycle.
   Shopify App Store tenants retain Shopify App Pricing. Exactly one active
   provider per subscription. Switching requires reviewed cancellation/effective
   date and confirmed replacement; no double billing or entitlement activation
   from a browser return. PayPal platform-managed subscriptions may require
   separate partner enablement; confirm the approved integration before release.
5. **Operations and controlled launch.** Payment-method-aware receipt/refund
   flows, disputes, reporting and reconciliation. Scope all records to tenant,
   original provider account, environment and payment. Exercise duplicate and
   delayed events, partial refunds, revocations, timeouts, expiry, stale prices,
   cross-tenant access and concurrent provider selection. Release via protected
   CI/Forge, verify exact-release readiness, then activate approved pilot tenants.

## Launch acceptance

- Stripe and PayPal have independently verified tenant ownership and readiness.
- Checkout never trusts browser totals, callback success flags or seller IDs.
- Signed webhooks plus server reads confirm the correct payee, amount, currency,
  environment and immutable local reference; replay cannot duplicate fulfillment.
- Refunds use the original processor/account. Connection changes cannot reroute
  pending payments, refunds or disputes.
- No new provider fee or subscription price is invented. Platform-fee collection
  needs explicit commercial approval and applicable provider permission.
- A tenant may connect either or both processors. A connection does not grant a
  Branch, create a subscription or satisfy checkout/tax/webhook launch gates.

## Current limits

Only phase 1 is implemented in this change. PayPal checkout, webhooks,
subscriptions, refunds and reporting are not implemented. No PayPal checkout
availability is returned anywhere. The new page says this explicitly even when
an account verifies. Stripe collection remains governed by existing readiness.
Real provider setup and end-to-end tests are required before activation.

## Primary references

- https://docs.stripe.com/connect/embedded-onboarding
- https://docs.stripe.com/connect/hosted-onboarding
- https://docs.stripe.com/connect/saas
- https://developer.paypal.com/platforms/get-started/
- https://developer.paypal.com/platforms/seller-onboarding/before-payment/
- https://developer.paypal.com/platforms/subscriptions/integrate/
- https://developer.paypal.com/subscriptions/about/
