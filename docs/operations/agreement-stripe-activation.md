# Agreement Stripe Activation Runbook

## Fixed architecture

- Stripe is the only direct-client processor. Relay Financial is the Stripe payout bank only; do not enable Relay invoicing for Everbranch orders.
- Accepted immutable agreement versions are the source for negotiated charges. `config/module_catalog.php` remains the source for standard plan identity and entitlements.
- Shopify and third-party costs are display-only in this flow. Shopify App Store billing flags and routes remain unchanged and disabled.

## Production blockers

Keep `EVERBRANCH_AGREEMENT_CHECKOUT_ENABLED=false` and `EVERBRANCH_STRIPE_INVOICING_ENABLED=false` until the relevant flow has recorded evidence:

1. Stripe business verification is complete and the live Relay destination account has received a verified test payout.
2. Live `STRIPE_KEY`, `STRIPE_SECRET`, and `STRIPE_WEBHOOK_SECRET` are stored in deployment secrets, never repository files.
3. Cards and US bank accounts are enabled. Stripe customer emails, branding, statement descriptor, failed-payment handling, refunds, and disputes are configured.
   - Enable Stripe's invoice setting to save customer payment information so hosted invoices can offer saved payment methods on the reused Stripe Customer.
4. The production webhook endpoint subscribes to Checkout completion/expiry/asynchronous outcomes, invoice finalized/sent/paid/failed/voided/marked-uncollectible, subscription changes/deletion, refunds, and disputes.
5. An accountant supplies written taxability/registration direction. Set `EVERBRANCH_AGREEMENT_TAX_DECISION_CONFIRMED=true` only after that evidence is attached. Enable `EVERBRANCH_AGREEMENT_STRIPE_TAX_ENABLED` only for approved registrations.
6. Stripe Billing Portal is configured for payment-method and invoice access only. Subscription cancellation stays in Everbranch so termination and the 30-day export window are retained.
7. Test-mode evidence covers card success/failure, ACH pending/success/failure, six promotional cycles followed by the standard phase, direct invoice send/pay/fail/void/refund/dispute paths, duplicate webhooks, receipts, and tenant isolation.

## Credential placement

- Local testing: use the ignored repository `.env` with complete `acct_`, `pk_test_`, `sk_test_`, and test-endpoint `whsec_` values.
- Production: store complete `acct_`, `pk_live_`, `sk_live_`, and production-endpoint `whsec_` values in the Laravel Forge/server environment for `app.theeverbranch.com`.
- Production-host sandbox: to run a deliberate Stripe test-mode invoice on `app.theeverbranch.com`, use complete test credentials plus `EVERBRANCH_STRIPE_TEST_MODE_ON_PRODUCTION_ALLOWED=true` and a concrete tenant allowlist such as `EVERBRANCH_STRIPE_INVOICING_TENANT_SLUGS=front-yard-foods`. Do not use `*`, and turn the sandbox gate off before live billing.
- Do not paste live secrets into `.env.example`, source control, GitHub, issue comments, or chat. Do not add `pk_`/`sk_` prefixes manually; copy the complete key from Stripe Workbench.
- Run `php artisan config:doctor --env=production` after setting server values and before enabling the checkout or direct-invoicing flag. The command must reject missing/malformed values, mixed test/live modes, unapproved test keys on production hosts, missing webhook secrets, and incomplete live tax or Relay gates.

## Direct invoice flow

- Landlord operators create direct invoice drafts at `/landlord/invoices`. These drafts are for approved Everbranch service and Evergrove implementation, supplemental, or milestone charges only.
- `EVERBRANCH_STRIPE_INVOICING_ENABLED=true` enables the send action independently from proposal Checkout. Limit early rollout with `EVERBRANCH_STRIPE_INVOICING_TENANT_SLUGS=front-yard-foods` or another explicit tenant slug.
- The server snapshots customer contact, billing address, authorization reference, and line items before sending. Browser-supplied tenant IDs, Stripe IDs, totals, tax, and provider statuses are ignored.
- Stripe sends hosted invoices with card and US bank account payment methods. Everbranch mirrors only Stripe-confirmed subtotal, tax, total, hosted invoice URL, invoice PDF, receipt URL, payment status, and event references.
- Proposal Checkout reuses prior tenant-scoped Stripe Customer identifiers for the same billing signer when available, enables saved payment method controls, saves subscription payment methods as the subscription default, and marks one-time Checkout payments for future customer-present reuse.
- Saved cards and bank accounts are convenience only. Supplemental work, milestones, and new implementation charges still require a new immutable approval plus a customer-opened Checkout or hosted invoice payment action.
- Direct invoices do not activate subscriptions, modules, plans, or implementation fulfillment. Proposal Checkout and direct invoices share webhook signing and receipt mirroring but keep their local records separate.

## Staged enablement

1. Set the target flow flag true with test keys and an explicit tenant allowlist.
2. For proposal Checkout, complete an accepted-proposal card test and ACH test. Confirm Stripe invoices and mirrored Everbranch receipts match.
3. For direct invoices, create a draft, send it through Stripe test mode, pay by card, test ACH pending-to-paid, void an open invoice, and replay webhook duplicates.
4. Configure live secrets, confirm the tax decision and Relay payout evidence, then repeat with an internal allowlisted tenant.
5. Add Front Yard Foods to the live allowlist. Use `*` only after multiple-client production evidence is approved.

No processing-fee surcharge is added by this system. Supplemental and milestone charges always require their own immutable acceptance and customer-initiated payment.
