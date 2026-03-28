# Repo Instructions

Analysis-first rule:

Before writing any code, run a repository scan to locate relevant existing
controllers, services, models, commands, migrations, and queries.

Summarize what already exists and how the requested feature could reuse
or extend that logic.

Only proceed with implementation after confirming that reuse is not possible.

Before building anything new:

1. Search the codebase for existing:
   - controllers
   - services
   - models
   - commands
   - migrations
   - queries
2. Determine whether the requested feature already exists or partially exists.
3. If it exists, reuse or extend it rather than creating a duplicate.
4. If a similar pattern exists elsewhere in the repo, follow that pattern.
5. Only introduce a new file or system if no suitable existing structure exists.
6. When a new component is necessary, explain why reuse was not possible.

Do not create duplicate systems that replicate existing logic.
Prefer modifying the current architecture over introducing parallel structures.

Dual-track product guardrail:
- Shopify Product Track is the flagship wedge and must remain strong.
- Broader Business Systems Track is an expansion path, not a rewrite.
- Do not weaken currently working Shopify proof-of-concept flows while building broader platform direction.

Current host and execution reality (required):
- Production host direction:
  - public: `forestrybackstage.com`
  - landlord: `app.forestrybackstage.com`
  - tenant pattern: `<slug>.forestrybackstage.com`
- Landlord routes are host-locked.
- Landlord tenant directory remains read-only; landlord commercial writes are limited to safe configuration scope (`/landlord/commercial`).
- Current public commercial model:
  - tiers: `Starter`, `Growth`, `Pro`
  - add-ons: `referrals`, `sms`, `additional_channels`, `bulk_email_marketing`, `future_niche_modules`
  - templates: `Candle`, `Law`, `Landscaping`, `Apparel`, `Generic`
- Strict near-term order:
  1. Candle Cash verified live and trustworthy
  2. email reliability fixed for launch-critical reward/customer flows
  3. only then broader expansion
- Do not start yet:
  - broad multi-tenant refactors
  - Shopify App Store packaging
  - speculative AI automation work

Feature classification rules (required for every new major feature):
- Classify as exactly one:
  - Shopify-only
  - Shared core
  - Integration layer
  - Purchasable add-on
  - Internal/admin only

Required feature metadata before implementation:
1. Classification (from the list above)
2. Tenant scope (tenant-scoped, global-by-design, or mixed with explicit bridge rules)
3. Entitlement/access level (plan tier, add-on dependency, enabled/disabled defaults)
4. Canonical model/service dependency (which existing canonical models/services are reused)
5. Shopify-specific hooks (proxy routes, embedded surfaces, webhooks, theme/runtime dependencies)
6. Setup/onboarding implications (what setup checklist or connector state must exist)
7. Shopify behavior preservation requirement (what current behavior must remain unchanged)
8. Non-Shopify applicability target (now, later, or never)

Identity duplication warning:
- Never invent a parallel customer identity system.
- Reuse `marketing_profiles`, `customer_external_profiles`, `marketing_profile_links`, and `MarketingProfileSyncService`.
- Any proposal that introduces a second profile/identity truth must be rejected unless explicitly approved.

Identity architecture rule:
- The canonical customer identity model is `marketing_profiles`.
- External systems such as Shopify, Square, and Growave must integrate through:
  - `customer_external_profiles`
  - `marketing_profile_links`
  - the `MarketingProfileSyncService` pipeline
- Never create a new identity, loyalty, or profile model unless explicitly instructed.
- All customer identity must flow through the canonical `marketing_profiles` system.
- Do not introduce:
  - alternate identity tables
  - parallel loyalty systems
  - sidecar profile models
  - duplicate identity resolution logic

Idempotency rule:
- All imports, sync jobs, and merge operations must be idempotent.
- Running a sync multiple times must not create duplicate records.
- Prefer:
  - upserts
  - unique provider keys
  - source identifiers
  - stable external IDs

Import pipeline rule:
- External integrations such as Shopify, Square, and other providers must land raw data in source tables first.
- Source data must then flow through the `MarketingProfileSyncService` pipeline.
- Do not write integrations that insert directly into `marketing_profiles`.

Commit review rule:
- If the local branch contains commits ahead of origin, inspect them before pushing.
- Summarize:
  - commit message
  - affected subsystems
  - files changed
- Only push automatically if the ahead commits appear intentional and safe.
- If anything appears experimental, temporary, or unrelated, stop and ask before pushing.

Production data safety rule:

Never write code that mutates or deletes production customer data without
explicitly confirming the safety of the operation.

The following operations require special caution and explanation before implementation:
- mass updates
- deletes
- schema migrations affecting customer data
- loyalty balance recalculations
- profile merges
- identity remapping

Before implementing any data-changing logic:

1. Identify which tables will be affected.
2. Explain whether the change is reversible.
3. Prefer idempotent operations and upserts over destructive changes.
4. Avoid direct deletes; prefer soft-delete or archival patterns when possible.
5. Do not overwrite canonical identifiers (`marketing_profile_id`, external IDs).
6. When possible, stage changes through:
   - new columns
   - background migrations
   - recomputation jobs

Never destroy or rewrite historical records such as:
- `candle_cash_transactions`
- `marketing_review_histories`
- `marketing_import_runs`

When working on integrations such as Shopify, Growave, Square, marketing sync, loyalty, reviews, or segmentation:
- Check for existing services, sync commands, link tables, and projection logic first.
- Extend the unified profile pipeline instead of creating sidecar identity flows.
