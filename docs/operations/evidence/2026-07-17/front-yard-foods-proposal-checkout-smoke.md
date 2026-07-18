# Front Yard Foods Proposal Checkout Smoke Evidence

## Result

- Date: 2026-07-17 EDT / 2026-07-18 UTC
- Environment: production host with Stripe test mode explicitly enabled
- App URL: `https://app.theeverbranch.com`
- Proposal host: `https://evergrovesoftware.com`
- Tenant: `front-yard-foods` (tenant ID 4)
- Operator/test signer: John Collins, Internal Sandbox Validator
- Deployed application commit: `eb046487112e48ebec0fee67d85fe6af2fc91f2d`
- Application changes: PR #75 and Stripe Checkout compatibility hotfix PR #76
- Sandbox card agreement: ID 2, version 1, billing order 1
- Sandbox ACH agreement: ID 3, version 1, billing order 2
- Immutable content hash: `6cf909bac44bf5b6f1c7ecc16f51854ada9a92f3021091bc6df762abb0a839d8`
- Final sandbox status: **passed and canceled**
- Pre-send status: **blocked only by five live operational gates**

Proposal tokens, passwords, full Stripe object identifiers, and credentials are intentionally excluded from this repository evidence.

## Client Flow Notes

- The locked proposal required its generated password and displayed a prominent `TEST MODE ONLY` notice.
- The unlocked page was customer-facing and contained no landlord controls.
- Evergrove and Front Yard Foods branding, readable signature fields, immutable version reference, scope, data-use assurance, and cost separation were visible.
- Legal name, title/authority, email, seven explicit acknowledgements, and the typed electronic signature were required.
- Signing locked the exact version and revealed payment only afterward.
- The payment summary contained only:
  - Everbranch one-time setup: `$299.00`
  - Everbranch Launch Partner service: `$59.00/month`
  - Future standard service: `$149.00/month` beginning with cycle seven
- Shopify, Square, Substack, booking apps, domains, taxes, transaction fees, paid third-party apps, and unquoted implementation work were informational and not Stripe line items.
- Stripe Checkout displayed `$358.00` due initially and `$59.00/month` thereafter, with card, Apple Pay, and US bank account methods.
- The first card checkout displayed and accepted the saved-payment option for future purchases.

## Card Validation

- Agreement ID 2 was accepted at `2026-07-18 01:48:08 UTC`.
- Stripe confirmed a paid `$358.00` invoice/receipt with zero sandbox tax.
- Everbranch recorded the paid order, receipt, recurring authorization, and configured six-cycle promotional schedule.
- `php artisan everbranch:front-yard-foods-readiness --stage=sandbox-paid --agreement-id=2` passed every check.
- The test schedule was canceled without proration or another invoice.
- Everbranch received `customer.subscription.deleted` and marked the authorization canceled.
- A signed duplicate replay of the deletion event returned HTTP 200. Event state, authorization timestamp, receipt count, and fulfillment count were unchanged.

## ACH Validation

- Agreement ID 3 was accepted at `2026-07-18 02:22:42 UTC`.
- Stripe's successful test bank account was connected through hosted Checkout.
- Immediately after Checkout completion, Everbranch recorded:
  - order status `processing`
  - no `paid_at` timestamp
  - authorization status `active`, not provider-verified
- Everbranch did not mark the order paid from `checkout.session.completed` alone.
- After Stripe delivered `checkout.session.async_payment_succeeded`, Everbranch recorded:
  - order status `paid`
  - a Stripe-confirmed `paid_at` timestamp
  - authorization status `provider_verified`
  - paid receipt total `$358.00`
- `php artisan everbranch:front-yard-foods-readiness --stage=sandbox-paid --agreement-id=3` passed every check.
- The second test schedule was canceled. Everbranch received `customer.subscription.deleted` and marked the authorization canceled.

## Isolation Evidence

- Both billing orders are permanently marked `validation_only=true`.
- Both provider subscriptions have zero rows in the live tenant subscription ledger.
- Each created only one `validation_only` / `noop` fulfillment audit record.
- The sandbox payment path did not create client workspace access or live commercial state.
- The internal operator membership predated agreement acceptance and payment; it was not payment fulfillment.
- Laura's real `client_services` agreement remains unsigned, has no billing order, and remains separate from both sandbox agreements.
- Pre-send readiness confirms Laura has no workspace membership before verified real payment.
- Front Yard Foods remains the only explicit agreement-checkout allowlisted tenant; wildcard access is not enabled.

## Commands and Outcomes

```bash
php artisan config:doctor --env=production
# PASS with production-hosted Stripe test mode explicitly allowed for sandbox validation.

php artisan everbranch:front-yard-foods-readiness --stage=sandbox-paid --agreement-id=2
# PASS

php artisan everbranch:front-yard-foods-readiness --stage=sandbox-paid --agreement-id=3
# PASS

php artisan everbranch:front-yard-foods-readiness --stage=pre-send
# BLOCKED: 5 live operational gates remain.
```

## Acceptance Checklist

- [x] Proposal requires a password and is Evergrove-hosted.
- [x] Proposal has no landlord UI.
- [x] Signature binds the exact immutable agreement version.
- [x] Checkout is blocked before signature.
- [x] Checkout contains exactly `$299.00` onboarding plus `$59.00/month` service.
- [x] Shopify, Square, third-party, and unquoted implementation expenses are excluded.
- [x] Card payment is recorded only from Stripe-confirmed evidence.
- [x] Saved card support is visible and works in hosted Checkout.
- [x] ACH remains processing until Stripe confirms asynchronous success.
- [x] Receipt, invoice, authorization, subscription, and schedule evidence is mirrored.
- [x] Duplicate webhook replay is safe.
- [x] Sandbox payments do not create live subscription ledger state, entitlement fulfillment, or client access.
- [x] Both test schedules are canceled and deletion webhooks are recorded.
- [x] The real Laura agreement remains unsigned and unconsumed.
- [x] Required Front Yard modules resolve: Customers, Events & Classes, Plant Inventory, Messaging pending, and Reporting.
- [x] PR CI passed on PHP 8.4, PHP 8.5, quality checks, and the production deployment.
- [ ] Replace test Stripe keys with live publishable and secret keys in Forge.
- [ ] Register the production webhook endpoint, record a signed live-mode event, and set the verification gate.
- [ ] Confirm an actual Stripe payout arrives in Relay and attach evidence.
- [ ] Attach the written accountant taxability/registration determination.
- [ ] Run `pre-send` green, rotate Laura's real link, and send it.
- [ ] After Laura pays, activate her workspace and run `post-payment` green.

## Remaining Live Blockers

1. Live Stripe publishable key (`pk_live_...`) is not configured.
2. Live Stripe secret key (`sk_live_...`) is not configured.
3. The production webhook endpoint has not yet recorded verified signed live-mode evidence.
4. An actual Stripe-to-Relay payout has not yet been evidenced.
5. Written accountant tax guidance has not yet been attached/confirmed.

The live proposal must not be rotated or sent until all five blockers are cleared and `pre-send` passes.
