# Pre-Billing Readiness Gate (Configuration-First Phase)

Status: Active guardrail for staging and merge decisions.

## What Is Ready Now
- Commercial configuration is active:
  - plan/add-on/template catalogs
  - tenant plan/template assignment
  - tenant display label overrides
  - tenant usage and included-limit visibility
  - billing mapping metadata fields
- Three guarded landlord billing actions are active:
  - landlord-only Stripe customer-reference create/sync on `/landlord/commercial`
  - landlord-only Stripe subscription-prep metadata sync on `/landlord/commercial`
  - landlord-only Stripe live subscription-reference create/sync on `/landlord/commercial` (guarded action flag can remain disabled by default)
  - explicit manual trigger only (no passive/background billing mutation)
- Commercial context propagates to:
  - `/landlord/commercial`
  - `/shopify/app/start`
  - `/shopify/app/plans`
  - `/shopify/app/integrations`

## What Is Not Ready (Must Stay Disabled)
- No live checkout.
- No broad subscription update/cancel lifecycle actions.
- No payment method collection or mutation from landlord/tenant commercialization surfaces.
- No automated overage billing.
- No integration connector write activation from `/shopify/app/integrations`.
- No tenant self-serve billing actions.

## Stripe/Braintree Mapping Readiness Scope
- Stripe remains primary direction; Braintree remains secondary/future.
- Current mapping scope is readiness-first plus guarded Stripe actions, such as:
  - plan/add-on recurring and setup pricing config
  - support-tier recurring pricing config
  - explicit Stripe mapping matrix in `commercial.stripe_mapping` for tiers/add-ons/setup/support/usage/store-channel policy
  - provider role/status in `commercial.billing_readiness`
  - tenant billing mapping JSON placeholders
  - guarded subscription-prep candidate metadata
  - guarded live subscription reference create/sync
- This does not imply full subscription lifecycle activation or broad lifecycle automation.

## Required Tenant Billing Mapping Fields
Before subscription lifecycle implementation passes, each tenant should provide billing mapping values for:
- `stripe.customer_reference`
- `stripe.subscription_reference`

Notes:
- `stripe.customer_reference` can be populated by the guarded landlord customer-sync action.
- subscription-prep candidate metadata can be refreshed by the guarded landlord subscription-prep action.
- `stripe.subscription_reference` can now be populated by the guarded landlord live subscription create/sync action.

## Evidence Required Before Billing-Focused Pass
Complete and attach all of the following from staging:
1. UAT matrix run results from `docs/operations/staging-commercial-uat-runbook.md`.
2. Per-permutation evidence using `docs/operations/staging-commercial-uat-evidence-template.md`.
3. Proof that blocking rows pass for:
  - assignment propagation
  - deterministic fallback behavior
  - integrations read-only guard
  - billing lifecycle disabled guard (except guarded Stripe actions explicitly listed above)
4. Any deferred items clearly marked non-blocking and non-lifecycle.
5. Activation prerequisites checked against `docs/operations/billing-activation-checklist.md`.
6. Any blocked run must include explicit blocker evidence and may not be counted as staging pass evidence.

## Merge-Blocking Conditions for Billing Readiness
- Any evidence that broad lifecycle actions are active outside the three guarded landlord Stripe actions.
- Any uncertainty about plan/template/label assignment propagation correctness.
- Any run where landlord routes are unreachable/mismatched (for example, `/landlord/commercial` returns `404` on intended landlord host).
- Any run where guarded Stripe actions were not executable but was still recorded as pass evidence.
- Any ambiguity where operators cannot distinguish:
  - tenant override
  - template default
  - entitlements default

## Explicit Non-Goals in This Phase
- No subscription checkout implementation.
- No broad subscription update/cancel lifecycle implementation.
- No App Store packaging.
- No broad multi-tenant refactor.
