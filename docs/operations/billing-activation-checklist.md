# Billing Activation Checklist (Stripe-First, Lifecycle Disabled)

Status: Readiness checklist plus guarded live-slice reference. This is not full lifecycle implementation.

## What Must Be True Before Billing Lifecycle Work Starts
- Staging commercial UAT matrix is complete with blocking rows passing.
- Landlord commercial assignment propagation is stable for:
  - `/landlord/commercial`
  - `/shopify/app/start`
  - `/shopify/app/plans`
  - `/shopify/app/integrations`
- Billing lifecycle remains disabled in config and UI:
  - `commercial.billing_readiness.checkout_active=false`
  - `commercial.billing_readiness.lifecycle_mutations_enabled=false`
- Guarded landlord Stripe actions allowed in this phase:
  - customer-reference sync
  - subscription-prep metadata sync
  - live subscription reference create/sync (single guarded landlord action)
- Guarded actions do not imply full lifecycle automation.
- No connector write behavior is activated from `/shopify/app/integrations`.

## Required Staging Evidence
1. Completed runbook matrix from `docs/operations/staging-commercial-uat-runbook.md`.
2. Completed permutation evidence sheets from `docs/operations/staging-commercial-uat-evidence-template.md`.
3. Proof of assignment + fallback correctness for Starter/Growth/Pro and Candle/Law/Landscaping/Apparel/Generic scenarios.
4. Proof that integrations remain read-only/placeholder.
5. Proof that billing lifecycle controls are still disabled.
6. Proof that guarded live subscription create/sync behaves correctly (blocked with missing prerequisites, succeeds only when prerequisites pass).

## Stripe Mapping Requirements (Configuration-First)
All canonical mapping sections must be present in `config/commercial.php` under `stripe_mapping`:
- `tiers`:
  - `starter`
  - `growth`
  - `pro`
- `addons`:
  - `referrals`
  - `sms`
  - `additional_channels`
  - `bulk_email_marketing`
  - `future_niche_modules`
- `setup_packages`
- `support_tiers`
- `usage_metrics`:
  - `contact_count`
  - `sms_usage`
  - `email_usage`
- `store_channels` policy:
  - starter includes 1 channel
  - additional channels map through `additional_channels` add-on

## Tenant Fields Required Before Activation Work
Per-tenant billing mapping JSON must include readiness placeholders for:
- `stripe.customer_reference`
- `stripe.subscription_reference`

Tenant billing readiness in landlord surface should be treated as not ready until those fields and required mapping prerequisites are present.
Guarded prep paths in this phase:
- `stripe.customer_reference` can be synced from landlord UI.
- subscription-prep mapping candidate metadata can be synced from landlord UI.
- `stripe.subscription_reference` can be synced from landlord UI via the guarded live subscription create/sync action.

## What Remains Out of Scope Now
- No checkout implementation.
- No broad create/update/cancel subscription lifecycle logic.
- No webhook-driven billing lifecycle orchestration.
- No automated overage charging.
- No tenant self-serve paid activation.

## Risks While Platform Is Still Partially Multi-Tenant
- Some domains remain partially tenant-aware and still depend on profile-linked legacy assumptions.
- Internal ops domains are intentionally not productized tenant modules yet.
- Lifecycle billing activation before full confidence in assignment propagation could create tenant confusion or incorrect entitlement/billing coupling.

## Manual Verification Required Before Any Live Billing Mutation Work
1. Validate landlord host lock and operator authorization paths.
  - `/landlord` and `/landlord/commercial` must be reachable on landlord host and must not return `404`.
2. Confirm plan/template/add-on/module assignment changes propagate consistently to tenant commercialization surfaces.
3. Confirm label-source fallback behavior is deterministic.
4. Confirm tenant billing-readiness panel reflects missing requirements correctly.
5. Confirm disabled lifecycle wording is present and unambiguous on landlord commercial UI.
6. Treat any preflight-blocked run as blocked evidence only, not as a completed staging validation pass.
