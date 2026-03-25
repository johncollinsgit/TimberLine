# Operational Multi-Tenant Direction (Guidance)

Date: 2026-03-24  
Status: Directional guidance built on current implemented architecture

## Purpose
Give future implementation work a clear boundary model for what is already tenant-safe, what is in transition, and what remains candle-specific operational logic.

This document is intentionally opinionated but does not claim unfinished domains are already fully multi-tenant.

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

## Explicit Ambiguities (Intentional)

These are intentionally left open pending broader product decisions:
- how far order/fulfillment workflows should be generalized beyond current candle operations
- which inventory primitives should be extracted for non-manufacturing tenants
- what non-email customer lifecycle orchestration should look like across future verticals

Until those decisions are explicit, prefer incremental layering over broad platform refactors.
