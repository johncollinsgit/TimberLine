# Everbranch Landlord Portal Readiness

Status: landlord Branches preview and operator-alert SMS safety are live on
`main` as of 2026-07-25.

## Mission

Define and harden the landlord/admin portal as the safe control center for Everbranch operations.

## Current State

- Landlord routes are host-locked to `app.theeverbranch.com`.
- Access uses `landlord.operator`.
- Current landlord surfaces include dashboard, alphabetized sidebar navigation
  with Home pinned first, commercial configuration, Branches preview, tenant
  directory/detail, support Tickets, Agreements, Invoices, and tenant
  operations.
- Guarded billing readiness actions are landlord-only.
- Onboarding and commercial diagnostics exist in pieces.

## Branches Preview

- `/landlord/branches` is a read-only operator surface for seeing what a
  selected workspace would see in its customer-facing Branch catalog.
- It uses the same tenant-scoped catalog payload as the tenant App Store through
  the canonical module catalog/services, but it does not install Branches,
  change billing, create access requests, activate entitlements, or mutate
  workspace setup.
- The page is available only on the landlord host behind `auth`, `verified`,
  and `landlord.operator`. An unauthenticated production request should redirect
  to login rather than return 404.
- The sidebar label is **Branches**. Landlord Home stays first; the rest of the
  landlord links sort alphabetically to keep operator scans predictable.

## Operator Alerts

- Landlord/operator SMS alerts are routed through
  `OperatorAlertService`; direct Twilio sends for operator texts are not part
  of the portal contract.
- The service logs a reserved alert row before any SMS send, suppresses
  non-real activity with a reason, and coalesces repeated identical alerts.
- Configure live SMS with `EVERBRANCH_OPERATOR_ALERT_PHONE` and optional
  `EVERBRANCH_OPERATOR_ALERT_SMS_ENABLED`. A missing phone or disabled flag
  keeps audit logs but does not text.
- See `docs/operations/operator-alert-sms-runbook.md` for the operating
  checklist and suppression rules.

## Target Capabilities

- Tenants.
- Stores and integrations.
- Users.
- Plans.
- Branches/modules catalog visibility and safe preview.
- Module installs.
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
- Read-only preview pages do not mutate billing, module access, entitlements,
  workflow state, or setup status.
- Mutations are auditable and constrained.
- Tenant support tools never bypass tenant-boundary checks.
- Operator SMS alerts have pre-send logging/dedupe and suppress test/demo/fake
  events.

## Fail Criteria

- Tenant host can render landlord pages.
- Manager/customer users can access landlord tools.
- Landlord mutation changes commercial/module/billing state without audit.
- A fixture, sandbox, or local/test event can text the operator.

## Recommended Next PR

Add a landlord dashboard clarity pass that groups tenants, onboarding, integrations, modules, billing readiness, and evidence links without changing core behavior.
