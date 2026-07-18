# Front Yard Foods Proposal Checkout Smoke Evidence

Copy this file to a dated note such as:

`docs/operations/evidence/YYYY-MM-DD/front-yard-foods-proposal-checkout-smoke.md`

## Environment

- Date:
- App URL:
- Evergrove proposal host:
- Stripe mode: test / live
- Tenant slug: `front-yard-foods`
- Operator:
- Proposal token suffix or internal reference:
- Agreement ID:
- Agreement mode: `sandbox_validation`
- Agreement version/content hash:
- Billing order ID:
- Stripe checkout session:
- Stripe invoice:
- Stripe subscription:
- Stripe webhook event IDs:

## Required Screenshots or Notes

- Locked proposal page.
- Unlocked customer-facing agreement.
- Visible legal name, title, email, and typed signature fields.
- Data-use assurance.
- Separated Shopify, Square/third-party, Everbranch, and Evergrove implementation costs.
- Stripe Checkout showing only `$299.00` onboarding plus `$59.00/month` service.
- Stripe paid invoice/subscription evidence.
- Everbranch billing order/receipt mirror.
- Laura workspace dashboard.
- Tenant signed agreement page.
- Events & Classes page.
- Plant Inventory page.

## Commands

```bash
php artisan config:doctor --env=production
php artisan everbranch:front-yard-foods-readiness --stage=sandbox-paid --agreement-id=<sandbox-agreement-id>
```

After canceling the test subscription and configuring verified live gates:

```bash
php artisan everbranch:front-yard-foods-readiness --stage=pre-send
```

## Acceptance Checklist

- [ ] Proposal requires password and is Evergrove-host-only.
- [ ] Proposal has no landlord UI.
- [ ] Signature binds the exact immutable agreement version.
- [ ] Acceptance is visible in landlord agreement management.
- [ ] Checkout is blocked before signature.
- [ ] Checkout excludes Shopify, Square, Substack, booking apps, domains, taxes, transaction fees, third-party apps, and unquoted implementation work.
- [ ] Card payment marks the order paid only after Stripe-confirmed evidence.
- [ ] ACH remains processing until Stripe confirms settlement.
- [ ] Duplicate webhook replay is safe.
- [ ] Receipt and invoice links are tenant-scoped.
- [ ] Sandbox payment creates no tenant commercial override, subscription ledger, entitlement change, or client workspace membership.
- [ ] Sandbox agreement and receipt are hidden from tenant User Agreements and the Front Yard dashboard.
- [ ] Stripe test subscription/schedule is canceled and the deletion webhook is recorded.
- [ ] Laura does not have workspace access before verified payment.
- [ ] Laura has workspace access after verified payment and operator activation.
- [ ] Dashboard says “Welcome, Laura” and shows the Front Yard Foods launch checklist, client needs, data-use assurance, agreement link, Events & Classes, Plant Inventory, and Messaging pending.
- [ ] No Modern Forestry, candle, wax, pouring, market-box, or cross-tenant demo data appears.
- [ ] Tenant `/agreements` shows the accepted copy and hides landlord-private notes.
- [ ] Events & Classes and Plant Inventory reject cross-tenant access.

## Result

- Final status: pass / blocked
- Blockers:
- Follow-up owner:
