# Everbranch Landlord Portal Readiness

Status: PR 1 audit document.

## Mission

Define and harden the landlord/admin portal as the safe control center for Everbranch operations.

## Current State

- Landlord routes are host-locked to `app.theeverbranch.com`.
- Access uses `landlord.operator`.
- Current landlord surfaces include dashboard, commercial configuration, tenant directory/detail, and tenant operations.
- Guarded billing readiness actions are landlord-only.
- Onboarding and commercial diagnostics exist in pieces.

## Target Capabilities

- Tenants.
- Stores and integrations.
- Users.
- Plans.
- Modules and module installs.
- Custom module requests.
- Billing readiness/status.
- Onboarding status.
- Integration/import health.
- Notes.
- Audit logs.
- Feature flags.
- Safe support tools.

## Gaps

- Landlord dashboard needs clearer operator hierarchy.
- Intake/access requests need first-class queue treatment.
- Shopify/Square/import health should be consolidated.
- Mobile readiness needs a landlord view before mobile apps launch.
- Custom module requests do not yet have a first-class workflow.
- Audit logs should be easier to reach from operational pages.

## Pass Criteria

- Landlord pages are landlord-host only.
- Non-operator users are forbidden.
- Mutations are auditable and constrained.
- Tenant support tools never bypass tenant-boundary checks.

## Fail Criteria

- Tenant host can render landlord pages.
- Manager/customer users can access landlord tools.
- Landlord mutation changes commercial/module/billing state without audit.

## Recommended Next PR

Add a landlord dashboard clarity pass that groups tenants, onboarding, integrations, modules, billing readiness, and evidence links without changing core behavior.

