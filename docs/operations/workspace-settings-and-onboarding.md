# Guided onboarding and workspace settings

This source candidate extends existing tenant and account systems. It is not a
production release claim. Follow protected PR/CI/deployment and readiness checks.

## Existing source and entry points

| Purpose | Existing authority | Customer entry |
|---|---|---|
| Registration, verification, reset and invites | Fortify and existing membership/invitation services | Existing authentication URLs, unchanged |
| Create one first workspace | `FirstLoginWorkspaceController` / `FirstLoginWorkspaceProvisioner` | `/workspace/create` |
| Blueprint, template and manual presentation overrides | `TenantBlueprintProfileService`, `tenant_access_profiles.metadata.tenant_blueprint` | Existing operator blueprint; new Business profile |
| Available branches | `TenantModuleAccessResolver`, `TenantModuleCatalogService` | Existing plan/branches screen |
| Setup and first value | Existing setup/completion services; `WorkspaceSetupGuide` reads actual authorized records | `/workspace/setup` and dashboard reminder |
| Settings discovery | `WorkspaceSettingsService` metadata plus existing destination policies | `/workspace/settings` |
| Personal security and appearance | Existing Livewire settings forms | Existing `/settings/profile`, password, appearance and 2FA URLs |
| Embedded Shopify settings | Existing signed embedded-context routing | Existing `/settings` and Shopify URLs, unchanged |

The new hub links to existing forms. It is not another settings backend. Operator
administration remains separate, and no tenant owner gains platform authority.
Independent product consoles retain their existing navigation/authorization.

## Business context and resume

Organization > Business profile has three server-saved steps: business/specialty,
products/services/both and optional channels/customer types, then an optional
first goal. `company_context` extends the canonical business blueprint; it never
replaces operating mode, manual labels, grants, agreements, or source records.
Existing blueprint edits retain this context. Suggestions are reviewable and no
industry is inferred from a business name or an unsolicited website scrape.

Only an active verified current owner/admin membership may edit this context.
A locked profile row and revision hash reject stale submissions rather than
silently overwriting another administrator. Existing profile-less workspaces
receive an honest configuration-help state rather than a newly invented plan.
Changes are recorded in the existing operator-action audit with field names,
actor and tenant; raw business descriptions and secrets are not logged.

Each step persists before continuing. Finishing confirms the profile. Generic
and unknown industries remain useful, and mixed businesses can retain several
channels. Existing customers are not forced to repeat onboarding to keep working.

Before workspace creation, questionnaire progress is saved only in the signed-in
user's existing `onboarding_guide_answers.workspace_draft`. Save requests never
create tenants or memberships. They use a locked account and revision counter;
conflicting tabs receive a retry/reload message. Only questionnaire fields are
accepted, not passwords, appointment contacts, arbitrary tenant IDs or secrets.
The existing creation transaction now serializes repeated submissions per user;
invitees with an existing membership keep that workspace instead of creating one.

## Progress, optional steps and permissions

The guide offers actions supported by effective branches and viewer permissions:
customers, field jobs, classes and existing native website draft products where
available. Existing records count; opening a link or dismissing a reminder does
not complete work. Setup never creates a sample operational record. A failed
record read is unable-to-verify, and connection configuration is not a healthy
provider assertion. Optional connections lead to the existing status/check flow.
Required starting steps are separate from optional improvements, not a new
account-access requirement. No payment method/domain/invite is required for an
unrelated supported first action.

Personal reminder preferences live under the user's existing JSON, keyed by
active tenant. They do not change shared organization progress or another
person's reminders. Dismissed optional steps are explicitly not completed. Setup
is always reachable again from Settings. Tenant changes re-resolve profile,
progress, metadata and destinations; no browser-local draft cache is used.

Confirmed minimal category/specialty/model/channel/goal context can enter the
existing authorized AI help path. Descriptions and private operational data are
excluded. Context stamps refresh after confirmation changes; absent/unconfirmed
context preserves the previous stamp. No AI model/provider is introduced or
activated, and approved voice pilot/rates/caps and all #442/#443 boundaries stay.

## Registering a setting or setup action

Add a metadata row to `WorkspaceSettingsService::catalog` only after its existing
role, membership and effective-branch checks. Define label, description, synonyms,
category, scope and named internal route. Search runs after this filtering and
never indexes stored values. Existing destination and save authorization remain
authoritative. New branch adapters belong in `WorkspaceSetupGuide::goals` with a
real authorized destination and tenant-scoped record query, not a checklist
checkbox. Planned or unentitled capabilities must not become available actions.

## Verification and rollback

Focused feature tests cover owner/member and foreign/inactive boundaries, stale
saves, mixed profiles, independent user reminders, tenant switching, draft privacy
and existing records. Browser fixtures render actual Blade and production assets
for desktop, 1024px tablet-class and 390px phone layouts in Chromium/WebKit.
Browser draft writes are intercepted fictional responses; feature HTTP tests
verify actual server persistence. This is not physical-device or persistent
production-login/provider acceptance. Use provider fakes and fictional records.

Run the feature suite with `GUIDED_SETTINGS_FIXTURES=1` to export synthetic HTML,
then the existing workspace Playwright configuration. Build first so the manifest
contains the new entry points. Standard CI retains opt-in fixture export skips.
No migration/backfill/provider configuration is required. Roll back through the
protected release process; retain additive JSON and existing customer records.
