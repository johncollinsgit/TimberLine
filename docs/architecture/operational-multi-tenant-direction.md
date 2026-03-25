# Operational Multi-Tenant Direction (Guidance)

Date: 2026-03-24  
Status: Directional guidance built on current implemented architecture

## Purpose
Give future implementation work a clear boundary model for what is already tenant-safe, what is in transition, and what remains candle-specific operational logic.

This document is intentionally opinionated but does not claim unfinished domains are already fully multi-tenant.

## Current Release Checkpoint (2026-03-25)

Implemented operator/product surfaces now include:
- embedded shell surfaces for overview/start/plans/integrations
- public promo/contact product surfaces
- tenant-aware diagnostics surfaces for:
  - customer email timeline provider-context filters + CSV export parity
  - birthday analytics/reporting/export/comparison
  - campaign delivery diagnostics/provider-context summaries

Integrations scope in this release:
- placeholder-first cards + setup drawer + read-only status registry
- no live connector sync/OAuth/jobs/webhooks/API writes

What this checkpoint means for next runs:
- treat this as stabilization/deploy/verify baseline
- avoid broad architecture expansion unless fixing concrete regressions
- keep docs explicit about placeholder/read-only vs operational behavior

## Product Architecture Doctrine

- Fire Forge Tech is the platform owner.
- The Forestry Studio is the flagship tenant, not the architectural center of the platform.
- Flagship customization is allowed; platform coupling is not.
- Platform nouns, config keys, service names, and data models should remain domain-neutral unless a constraint is truly tenant-specific.

## Required Before Implementation (Hard Gate)

Do not implement a feature until all of the following are explicitly written:
- Classification: core platform capability / tenant configuration option / purchasable add-on / temporary tenant-specific override
- Tenant scope: what varies per tenant
- Entitlement/billing model: if applicable
- Canonical services/contracts reused
- Whether it works for a non-Forestry tenant without code changes

If this is not defined, stop and define it before coding.

## Forestry Bias Warning

- The Forestry Studio is the flagship tenant, but it is not the architectural center of the platform.
- Do not assume Forestry structure, naming, or UX is globally correct.
- Do not encode Forestry-specific language into platform models, services, config keys, or data models.
- Do not skip tenant abstraction "just for now."
- If a feature works for Forestry but not for a generic tenant, redesign the abstraction before implementing.

## Override -> Platform Graduation Rule

- Evaluate every tenant-specific override for promotion into:
  1. a tenant configuration option, or
  2. a shared add-on module.
- Do not let overrides accumulate as permanent parallel logic.
- Document the expected graduation path when creating an override.

## Tenant Presentation And Packaging Rules

- Structure, labels, ordering, visibility, and workflow composition should be tenant-configurable where practical.
- Shared business logic should remain in canonical backend services/contracts.
- Tenant-specific UI/presentation may vary, but should sit on top of shared module logic.
- Purchasable add-ons must be tenant-scoped, billing-aware, and configurable without per-tenant forks.

## Storefront Sidecar Boundary (Strict)

- Theme-side JS/CSS may render UI, trigger actions, and consume backend responses.
- Theme-side JS/CSS must not implement business logic.
- Theme-side JS/CSS must not perform validation that diverges from backend rules.
- Theme-side JS/CSS must not create parallel state systems.
- Backend remains the single source of truth.

## Add-On Module Requirements

Every add-on must explicitly define:
- Canonical data ownership
- Tenant scope boundary
- Entitlement/billing check
- Admin configuration surface
- Storefront interaction surface
- Integration points (`API`, app proxy, theme, etc.)
- Canonical services/contracts reused

Add-ons are attachable capabilities, not isolated systems or feature flags.

Add-on implementation rules:
- Reuse canonical identity and established sync/service pipelines before introducing new surfaces.
- Reuse existing signed storefront/app-proxy contracts when storefront interaction is required.
- Prefer one shared module architecture with tenant-level configuration over per-tenant forks.

## Productization Principle

- Evaluate every feature built for The Forestry Studio as a future product for other tenants.
- Build for Forestry, but name, structure, and scope features so they can be reused or sold without architectural rework.

## Customization Ladder (Implementation Order)

Use this order for feature work:
1. Tenant content/config only
2. Tenant UI/theme composition
3. Shared module option
4. Shared module extension
5. Tenant-specific override
6. New bespoke code path (last resort only)

Do not skip upward on this ladder without documenting why the simpler level was insufficient.

## Current Multi-Tenant Status By Domain

### 1) Email provider + delivery reporting (established direction)
Current state:
- Tenant-aware provider settings/readiness/dispatch direction is established.
- Delivery truth is canonical in `marketing_email_deliveries`.
- Provider-context semantics are canonical and reused across reporting surfaces:
  - `provider_resolution_source`
  - `provider_readiness_status`
  - `provider_config_status`
  - `provider_using_fallback_config`
- Customer timeline, campaign diagnostics, and birthday analytics now share provider-context semantics.

What this means:
- New email/reporting work should reuse existing provider-resolution/readiness services and delivery metadata semantics.
- Do not add a second readiness/reporting path.

### 2) Birthday/lifecycle messaging (established direction)
Current state:
- Birthday lifecycle sends and analytics use canonical delivery tracking and reward issuance linkage.
- Provider-aware readiness/failure behavior is explicit (unsupported/incomplete/legacy remain visible).
- Tenant scoping is expected in this domain.

What this means:
- Extend canonical birthday + delivery reporting services rather than creating birthday-only side systems.

### 3) Customers domain (tenant-scoped core domain, still expanding)
Current state:
- Canonical identity model remains:
  - `marketing_profiles`
  - `marketing_profile_links`
  - `customer_external_profiles`
  - `MarketingProfileSyncService`
- Tenant access boundaries and tenant-aware scoping exist in key customer/admin surfaces.
- Customer timeline now includes provider-context diagnostics and filtered export parity.

Directional intent:
- Customers are a platform-level business domain, not just a messaging recipient list.
- Customer surfaces should remain reusable across campaigns, lifecycle programs, ops context, and future non-candle workflows.

### 4) Inventory + internal product operations (partially reusable, currently candle-shaped)
Current state:
- Reusable operational primitives already exist:
  - inventory adjustment ledger (`inventory_adjustments`)
  - status tracking patterns
  - reporting query foundations and drilldown contracts
- Active workflows still carry candle-production semantics:
  - pouring room flows/statuses
  - scent-driven mapping/splits
  - wax/oil material assumptions

Directional intent:
- Extract reusable workflow/inventory primitives where they are truly generic.
- Keep manufacturing/candle rules layered as domain-specific policies.

### 5) Order/ops workflows (mixed: reusable core + candle-specific layers)
Current state:
- Orders/order-lines are core operational truth for current business flows.
- Some downstream logic is intentionally production-specific (scent splits, pouring states).

Directional intent:
- Reuse state progression/audit/event patterns.
- Do not turn candle manufacturing assumptions into platform defaults.

## Generalize vs Keep Specific

### Likely generic/reusable (candidates)
- Workflow state progression primitives (queued/in_progress/completed/failed style flows)
- Operational status + attempt tracking patterns
- Audit/history ledgers and append-only event records
- Queue/job handoff contracts and idempotent replay patterns
- Inventory movement/event logging patterns (signed deltas + reason codes)
- Customer communications infrastructure (provider resolution, readiness, dispatch, delivery tracking)
- Delivery/notification diagnostics + export/reporting infrastructure

### Likely candle-specific unless proven otherwise
- Pouring/manufacturing stages and candle production routing
- Scent/recipe/variant assumptions tied to wax/oil/fragrance domain rules
- Wax/vessel/fragrance material logic and constraints
- Candle-specific replenishment heuristics
- Prep workflows tightly coupled to candle operations terminology

### Practical rule
- Reuse primitives first.
- Keep domain policy close to the domain.
- Only abstract when at least one real non-candle use case exists or a boundary is already duplicated with clear shared semantics.

## Customer Domain Modeling Guidance

Customers are a tenant-scoped core domain, not just a campaign endpoint.

When extending customer features, assume future needs include:
- lifecycle state
- communication history (email/SMS/provider outcomes)
- rewards + birthday participation
- segmentation + eligibility
- operational/customer-service context

Required identity architecture remains:
- canonical profile: `marketing_profiles`
- external linkage: `customer_external_profiles`, `marketing_profile_links`
- sync pipeline: `MarketingProfileSyncService`

Do not create sidecar identity models for campaign-specific or product-specific use cases.

## Inventory / Internal Ops Modeling Guidance

Before introducing new ops abstractions:
1. Inspect existing order/inventory/pouring workflows and reporting query services.
2. Identify whether the requested behavior is:
   - reusable workflow infrastructure
   - domain-specific manufacturing logic
3. Isolate reusable core only when it remains understandable without candle assumptions.
4. Keep candle-specific policy in domain-layer services/components above reusable primitives.

Do not flatten all internal operations into one generic inventory abstraction if the behavior is fundamentally manufacturing-specific.

## Do Not Assume

- Do not assume all tenants operate like the candle business.
- Do not assume all product operations are manufacturing workflows.
- Do not assume all customer workflows are email-centric.
- Do not generalize candle terminology into platform-wide architecture without explicit reason.
- Do not create abstractions only because two code paths look similar in one sprint.

## Future Codex Implementation Guidance

Before extending customers, inventory, orders, or ops workflows:
1. Read `SYSTEM_SNAPSHOT.md` and this document first.
2. Classify the requested change into one layer:
   - tenant/platform infrastructure
   - reusable operational workflow infrastructure
   - candle-specific domain logic
3. Prefer layering:
   - reusable infrastructure at the base
   - tenant/domain-specific policy on top
4. If introducing a new abstraction boundary, document:
   - why current reuse was insufficient
   - what is generic vs domain-specific
   - idempotency + tenant-scope implications

## Entitlement-First and Tiered Product Vision

Future platform work should be designed as entitlement-aware by default, even when full billing orchestration is not implemented yet.

### Product access model direction
- Plan levels define broad capability envelopes (for example: core, growth, pro, enterprise).
- Add-ons define optional purchasable modules on top of a base plan.
- Each tenant/module should support explicit states:
  - enabled
  - disabled
  - configured
  - setup-incomplete
  - locked (upgrade required)

### Required behavior for new major modules
- Module enablement must be tenant-scoped.
- Access checks must exist in backend service/controller paths, not only in UI visibility rules.
- Setup status must be explicit so UI can show:
  - next required setup action
  - degraded/placeholder state safely
  - upgrade prompt when entitlement is missing
- Storefront and embedded/admin surfaces should read from the same entitlement/status truth where possible.

### Billing implementation scope (current)
- Do not block platform progress on immediate full billing integration.
- It is acceptable to start with canonical entitlement state tables/config and upgrade prompts first.
- When billing is later connected, it should map onto existing entitlement states instead of replacing module logic.
- Foundation implementation details live in:
  - `docs/architecture/tenant-entitlements-foundation.md`

### Identity and canonical data constraint
- Entitlement packaging must never introduce a second customer identity stack.
- Continue reusing canonical identity and sync pipelines:
  - `marketing_profiles`
  - `customer_external_profiles`
  - `marketing_profile_links`
  - `MarketingProfileSyncService`

### Anti-patterns to avoid
- Hardcoding module access by store key, email domain, or temporary tenant checks.
- Treating placeholder pages as “enabled” modules without backend capability.
- Building separate per-tenant module forks when one entitlement-aware module can serve all tenants.

## Explicit Ambiguities (Intentional)

These are intentionally left open pending broader product decisions:
- how far order/fulfillment workflows should be generalized beyond current candle operations
- which inventory primitives should be extracted for non-manufacturing tenants
- what non-email customer lifecycle orchestration should look like across future verticals

Until those decisions are explicit, prefer incremental layering over broad platform refactors.
