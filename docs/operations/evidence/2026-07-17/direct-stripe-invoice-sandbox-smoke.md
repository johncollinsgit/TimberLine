# Direct Stripe Invoice Sandbox Smoke Evidence — 2026-07-17

Status: **production-ready pending live gates** for landlord-created direct Stripe invoices.

This evidence covers the direct invoice lane only. It does not enable proposal Checkout, tenant self-serve billing, Shopify App Store billing, subscriptions, entitlement fulfillment, or live payment collection.

## Stripe sandbox invoice

| Field | Evidence |
| --- | --- |
| Stripe account | `acct_1TtvJTEPWqN35ScU` |
| Mode | Test / sandbox (`livemode=false`) |
| Invoice ID | `in_1TuGRkEPWqN35ScUmupaYwdk` |
| Invoice number | `GHPJWFCX-0001` |
| Amount | `$299.00` (`29900` cents) |
| Customer test recipient | `johncollinsemail@gmail.com` |
| Hosted invoice status | `Invoice paid` |
| Payment method shown by Stripe | American Express ending `3007` |
| Payment date shown by Stripe | July 17, 2026 |

Stripe API verification returned `status=paid`, `amount_paid=29900`, and `amount_due=29900`.

## Stripe event evidence

| Event type | Event ID | Timestamp |
| --- | --- | --- |
| `invoice.paid` | `evt_1TuHKbEPWqN35ScUAom2TeEZ` | 2026-07-17 15:21:56 EDT |
| `invoice.payment_succeeded` | `evt_1TuHKbEPWqN35ScUk89ItgvV` | 2026-07-17 15:21:56 EDT |
| `invoice_payment.paid` | `evt_1TuHLGEPWqN35ScUmVdD4Qmc` | 2026-07-17 15:22:38 EDT |
| `invoice.sent` | `evt_1TuGRnEPWqN35ScUsR96QvRv` | 2026-07-17 |

## Branding evidence

- Stripe sandbox branding uses the Evergrove wordmark in the Logo slot.
- Stripe sandbox branding uses the leaf mark in the Icon slot.
- `Prefer logo over icon` is enabled in Stripe Dashboard branding.
- Brand color is `#073f3b`; accent color is `#0b6255`.
- The paid hosted invoice page no longer renders the temporary `eg` icon. Stripe's invoice payment page uses its compact invoice header treatment, showing the leaf mark plus the business name.

## Local verification

Focused tests passed:

```bash
php -d memory_limit=512M ./vendor/bin/pest \
  tests/Feature/Billing/DirectStripeInvoiceTest.php \
  tests/Feature/Agreements/AgreementStripePaymentsTest.php \
  tests/Feature/ConfigDoctorTest.php
```

Result: `23 passed (163 assertions)`.

`php artisan config:doctor --env=production` correctly blocks local live readiness with the current local test-mode Stripe keys and `MAIL_MAILER=log`. That is expected: production live billing requires live keys, production mail, webhook signing, Relay payout evidence, and tax/accounting approval.

## Production readiness decision

Direct Stripe invoices are ready to move from sandbox validation into a production launch window when the live gates below are satisfied:

1. Live Stripe account mode is configured in Laravel Forge/server secrets with complete `acct_`, `pk_live_`, `sk_live_`, and production `whsec_` values.
2. The Stripe production webhook endpoint is registered and subscribed to the required invoice/payment/refund/dispute events.
3. Relay is verified as Stripe's payout destination, and `EVERBRANCH_STRIPE_RELAY_PAYOUT_VERIFIED=true` is set only after evidence exists.
4. Accountant-approved taxability/registration direction is recorded, and `EVERBRANCH_AGREEMENT_TAX_DECISION_CONFIRMED=true` is set only after that determination.
5. Production mail is configured to a real provider; `MAIL_MAILER=log` must not be used for customer invoices.
6. `EVERBRANCH_STRIPE_TEST_MODE_ON_PRODUCTION_ALLOWED=false` before live billing.
7. Direct invoicing remains allowlisted to the first approved tenant before broader rollout.

Until those gates pass, the correct status is **production-ready pending live gates**, not live billing enabled.
