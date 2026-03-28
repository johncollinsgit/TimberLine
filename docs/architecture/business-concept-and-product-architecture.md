# Business Concept and Product Architecture (2026-03-27)

Status: Active operator reference.

## Core Positioning
- System first, client second:
  - Modern Forestry is the flagship alpha tenant and validation path.
  - The platform architecture must stay reusable beyond one client.
- Configuration before code:
  - plans, add-ons, templates, labels, and tenant assignment should be configured first.
  - custom code is optional and scoped, not the default foundation.
- One platform, many tenants:
  - Shopify is the flagship wedge.
  - Broader business-system expansion is additive, not a rewrite.

## Revenue Model
- Platform access:
  - recurring access by tier (`Starter`, `Growth`, `Pro`)
- Setup/onboarding:
  - standardized setup packages (separate from recurring tier price)
- Custom work:
  - separate paid line item
- Support:
  - separate paid support tier (not bundled into `Pro`)

## Product Packaging Model
- Public tiers:
  - `Starter`
  - `Growth`
  - `Pro`
- Add-ons:
  - `referrals`
  - `sms`
  - `additional_channels`
  - `bulk_email_marketing`
  - `future_niche_modules`
- Templates:
  - `Candle`
  - `Law`
  - `Landscaping`
  - `Apparel`
  - `Generic`

## Template Model
- Templates shape:
  - default labels/copy
  - recommended module mix
  - dashboard emphasis
  - navigation emphasis
  - onboarding checklist defaults
- Templates do not fork architecture:
  - core models/services remain shared.
  - template assignment changes presentation defaults only.
- Tenant admins may personalize safe presentation:
  - card order
  - nav order
  - pinned sections
  - default landing view
  - display labels
- Tenant admins may not self-enable paid modules.
- Landlord remains source of truth for paid module enablement.

## Billing and Commercial Control
- Landlord controls:
  - tier catalog
  - add-on catalog
  - setup pricing
  - template catalog and assignment
  - per-tenant pricing/usage/display overrides
- Billing lifecycle:
  - configuration-first
  - no live checkout/subscription mutation yet
- Provider direction:
  - Stripe first (primary readiness)
  - Braintree secondary (future path)

## Current Reality vs Target Direction
- Current reality:
  - Shopify shell and diagnostics are live.
  - Integrations page is read-only placeholder.
  - Billing checkout activation is intentionally off.
  - Tenant-hardening is real but incomplete in some domains.
- Target direction:
  - keep Shopify flows strong and trustworthy
  - finish launch-critical reward + email reliability
  - then expand reusable modules and tenant breadth

## Phased Growth Path
1. Validate own candle-company/backend first (Modern Forestry alpha health protected).
2. Ensure rewards/customer/email flows work visibly and reliably.
3. Standardize reusable modules behind entitlement/config controls.
4. Expand to additional tenants/vertical templates without architecture forks.

## Non-Negotiable Architectural Rules
- Reuse canonical identity pipeline only:
  - `marketing_profiles`
  - `customer_external_profiles`
  - `marketing_profile_links`
  - `MarketingProfileSyncService`
- Do not create parallel identity, loyalty, or profile systems.
- Keep imports/sync idempotent with stable external IDs and upsert patterns.

## Execution Order Guardrail
1. Candle Cash verified trustworthy end to end for Modern Forestry.
2. Email reliability fixed for launch-critical reward/customer workflows.
3. Only then broader platform expansion.

Do not begin yet:
- broad multi-tenant refactor
- Shopify App Store packaging
- speculative AI automation work
