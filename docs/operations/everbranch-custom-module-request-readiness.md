# Everbranch Custom Module Request Readiness

Status: PR 8 custom module request workflow added.

## Mission

Design a custom software request workflow without building it prematurely or mixing it with module catalog access requests.

## Current State

- Tenant module access requests exist for known catalog modules.
- Landlord pages exist for tenants and commercial/module configuration.
- A first-party `custom_module_requests` workflow now exists for tenant intake and landlord triage.
- Tenant-facing request pages live at `/custom-module-requests`.
- Landlord triage lives at `/landlord/custom-module-requests`.
- Module Store cards can link to the request form with a safe tenant-visible `related_module_key`.

## Target Tenant Questions

- What problem are you solving?
- What tools or data are involved?
- How do you do it today?
- Who uses it?
- How often does it happen?
- How valuable or urgent is it?
- Is this private to your business or could it become a public Everbranch module?
- What are your budget and timeline expectations?

## Target Landlord Statuses

- `new`
- `needs_discovery`
- `quoted`
- `approved`
- `in_development`
- `in_review`
- `installed`
- `converted_to_reusable_module`
- `declined`
- `archived`

## Safest MVP

- Completed in PR 8:
  - a tenant-scoped request table
  - a simple tenant form
  - tenant list/detail pages
  - a landlord queue with filters
  - landlord triage updates for status, next action, and internal notes
  - optional link to a safe visible module catalog key
- Still intentionally absent:
  - automatic module creation
  - automatic module installation
  - entitlement changes
  - billing, checkout, quotes, or invoices
  - jobs/projects/photos/messaging/mobile module implementation

## Data Model Added In PR 8

`custom_module_requests` stores:
- `tenant_id`
- `requested_by_user_id`
- `related_module_key`
- `title`
- `problem_summary`
- `current_workaround`
- `desired_outcome`
- `tools_involved`
- `users_impacted`
- `frequency`
- `urgency`
- `budget_range`
- `reusable_module_interest`
- `mobile_relevance`
- `status`
- `landlord_notes`
- `next_action`
- `reviewed_at`
- `reviewed_by_user_id`

Statuses are labels only in PR 8. `quoted`, `approved`, `installed`, and `converted_to_reusable_module` do not generate quotes/invoices, create modules, install modules, or change entitlements.

## Current Guardrails

- Tenant users can create, list, and view only their own tenant's requests.
- Cross-tenant object access is hidden/denied.
- Landlord/admin users can view and triage all requests through landlord-host routes.
- Non-landlord users cannot access landlord triage.
- Tenant-facing `related_module_key` values must resolve to known safe tenant-visible App Store modules.
- Internal/hidden modules cannot be attached through tenant-facing request submissions.
- Mobile relevance is planning metadata only.
- Reusable module interest is planning metadata only.

## Pass Criteria

- Requests are tenant-scoped.
- Status changes are landlord-only and auditable.
- Conversion to reusable module remains explicit/manual.
- Request flow does not expose internal module roadmap data.
- Request flow does not alter module entitlements or billing state.

## Fail Criteria

- Custom requests create modules automatically.
- Tenants can see another tenant's requests.
- Request approval activates billing or entitlements without a separate audited action.

## Recommended Next PR

Harden Shopify App Store readiness/compliance evidence, especially Partner Dashboard verification and privacy webhook coverage, before public launch work.
