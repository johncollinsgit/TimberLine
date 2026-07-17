# Agreement Stripe Activation Runbook

## Fixed architecture

- Stripe is the only direct-client processor. Relay Financial is the Stripe payout bank only; do not enable Relay invoicing for Everbranch orders.
- Accepted immutable agreement versions are the source for negotiated charges. `config/module_catalog.php` remains the source for standard plan identity and entitlements.
- Shopify and third-party costs are display-only in this flow. Shopify App Store billing flags and routes remain unchanged and disabled.

## Production blockers

Keep `EVERBRANCH_AGREEMENT_CHECKOUT_ENABLED=false` until all items below have recorded evidence:

1. Stripe business verification is complete and the live Relay destination account has received a verified test payout.
2. Live `STRIPE_KEY`, `STRIPE_SECRET`, and `STRIPE_WEBHOOK_SECRET` are stored in deployment secrets, never repository files.
3. Cards and US bank accounts are enabled. Stripe customer emails, branding, statement descriptor, failed-payment handling, refunds, and disputes are configured.
4. The production webhook endpoint subscribes to Checkout completion/expiry/asynchronous outcomes, invoice paid/failed/voided, subscription changes/deletion, refunds, and disputes.
5. An accountant supplies written taxability/registration direction. Set `EVERBRANCH_AGREEMENT_TAX_DECISION_CONFIRMED=true` only after that evidence is attached. Enable `EVERBRANCH_AGREEMENT_STRIPE_TAX_ENABLED` only for approved registrations.
6. Stripe Billing Portal is configured for payment-method and invoice access only. Subscription cancellation stays in Everbranch so termination and the 30-day export window are retained.
7. Test-mode evidence covers card success/failure, ACH pending/success/failure, six promotional cycles followed by the standard phase, duplicate webhooks, refunds, disputes, receipts, and tenant isolation.

## Credential placement

- Local testing: use the ignored repository `.env` with complete `acct_`, `pk_test_`, `sk_test_`, and test-endpoint `whsec_` values.
- Production: store complete `acct_`, `pk_live_`, `sk_live_`, and production-endpoint `whsec_` values in the Laravel Forge/server environment for `app.theeverbranch.com`.
- Do not paste live secrets into `.env.example`, source control, GitHub, issue comments, or chat. Do not add `pk_`/`sk_` prefixes manually; copy the complete key from Stripe Workbench.
- Run `php artisan config:doctor --env=production` after setting server values and before enabling the checkout flag. The command must reject missing/malformed values, mixed test/live modes, test keys in production, missing webhook secrets, and incomplete tax or Relay gates.

## Staged enablement

1. Set the checkout flag true with test keys and `EVERBRANCH_AGREEMENT_CHECKOUT_TENANT_SLUGS=front-yard-foods`.
2. Complete an accepted-proposal card test and ACH test. Confirm Stripe invoices and mirrored Everbranch receipts match.
3. Configure live secrets, confirm the tax decision and Relay payout evidence, then repeat with an internal allowlisted tenant.
4. Add Front Yard Foods to the live allowlist. Use `*` only after multiple-client production evidence is approved.

No processing-fee surcharge is added by this system. Supplemental and milestone charges always require their own immutable acceptance and customer-initiated payment.
