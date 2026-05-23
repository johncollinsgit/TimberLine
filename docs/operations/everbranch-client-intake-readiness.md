# Everbranch Client Intake Readiness

Status: tenant blueprint foundation added after access-surface separation and test access passes.

## Mission

Design the simplest safe path for a small business to create an account, identify its business, choose setup needs, and become a tenant without overbuilding.

## Current State

- Registration exists, but it does not complete a full self-service tenant creation path.
- Public start/contact flows can capture interest and create access request style records.
- Public access requests now capture primary setup/import path intent (`shopify`, `square`, `csv`, `manual`, `other`, `undecided`) and future mobile interest (`none`, `android`, `ios`, `both`, `undecided`) as metadata only.
- Tenant onboarding exists after tenant context is available.
- A tenant-scoped setup status table now exists: `tenant_setup_statuses`.
- Existing tenant `/start` now shows a setup guidance section with business profile, setup phase, import path, Shopify connection status, Square/CSV/manual setup intent, module interests, mobile interest, landlord review status, next recommended action, and explicit inactive-capability guardrails.
- Existing tenant `/start` now captures plan interest, likely billing lane interest, implementation help interest, and commercial notes as planning signals only.
- Existing landlord onboarding diagnostics now includes a client setup status table with light review controls.
- A landlord intake queue now exists at `/landlord/onboarding/intake` with filters for setup statuses that need operator triage.
- A landlord commercial intent gate now exists at `/landlord/commercial-intent` with plan/lane grouping, billing readiness blockers, custom request context, and review-only commercial triage.
- Landlord `/landlord/tenants/create` now captures a tenant blueprint/profile for Shopify and non-Shopify tenants without creating a separate app architecture.
- Tenant detail pages now show the stored blueprint/profile so operators can see the tenant's business template, operating mode, data source path, labels, starter module recommendations, and next action.
- Tenant `/start` now reflects the blueprint/profile so direct, manual, CSV, Square-pending, demo, sandbox, and unknown tenants do not read as Shopify-only.
- Tenant blueprints now capture work-management intent for future project/work tracking, task management, user assignments, team/client communication, photo/file uploads, mobile field capture, and project/task/assignee/communication/upload labels.
- Approved access requests now seed `tenant_setup_statuses` during landlord approval when a tenant is resolved or created.
- Manual landlord tenant creation can seed `tenant_setup_statuses` from a matching pending/approved access request by primary contact email and tenant slug.
- Onboarding contracts already understand setup concepts such as Shopify, CSV, manual, connector, and mobile intent.
- Square sync code exists operationally, but there is no self-service Square OAuth/setup path.

## Data Model Added In PR 3

`tenant_setup_statuses` stores one row per tenant:
- `business_profile_status`
- `import_path`
- `shopify_connection_status`
- `square_status`
- `csv_manual_status`
- `module_interests`
- `mobile_interest`
- `landlord_review_status`
- `next_recommended_action`
- `internal_notes`
- `reviewed_by`
- `reviewed_at`

## Commercial Intent Fields Added In PR 13

`tenant_setup_statuses` now also stores:
- `plan_interest`: `starter`, `growth`, `pro`, `custom`, or `undecided`
- `billing_lane_interest`: `shopify_app_store`, `stripe_direct`, `manual_invoice`, `free_internal_demo`, or `undecided`
- `implementation_help_interest`
- `commercial_notes`
- `commercial_review_status`
- `commercial_next_action`
- `commercial_reviewed_by`
- `commercial_reviewed_at`

These are intent/status labels only. They do not create checkout sessions, subscriptions, Shopify charges, payment links, quotes, invoices, module installs, or entitlement changes.

The Shopify connection status is derived from existing `shopify_stores` rows by `TenantSetupStatusService`; this PR does not change Shopify OAuth/install behavior.

## Tenant Blueprint Foundation

Tenant blueprint/profile state uses existing patterns rather than a parallel tenant state model:
- `TenantAccessProfile.metadata.account_mode`
- `TenantAccessProfile.metadata.tenant_blueprint`
- `TenantAccessProfile.operating_mode`
- existing `tenant_setup_statuses` import/status/review/next-action fields

Blueprint fields include:
- `business_template`
- `operating_mode`
- `data_source_preference`
- `primary_outcome`
- `customer_label`
- `work_label`
- `money_label`
- `material_label`
- `stage_label`
- `project_label`
- `task_label`
- `assignee_label`
- `communication_label`
- `upload_label`
- `wants_project_workspace`
- `wants_task_management`
- `wants_user_assignments`
- `wants_team_communication`
- `wants_client_communication`
- `wants_photo_uploads`
- `wants_file_uploads`
- `wants_mobile_field_capture`
- `work_management_notes`
- `starter_modules`
- `setup_notes`
- `onboarding_next_action`

Supported operating modes:
- `shopify`
- `direct`
- `csv`
- `manual`
- `square_pending`
- `demo`
- `sandbox`
- `custom_or_unknown`

Supported initial business templates:
- `generic`
- `candle_maker`
- `landscaping`
- `electrician`
- `law`
- `apparel`

Templates only change presentation defaults, work-management intent defaults, and starter module recommendations. They do not create industry-specific route systems, install modules, change entitlements, activate billing, trigger Shopify OAuth, start Square automation, execute CSV imports, create project/task/upload/message tables, start storage uploads, or expose mobile APIs.

## Provisioning Bridge Added In PR 4

`TenantSetupStatusService::seedFromAccessRequest()` safely promotes access request metadata into the setup status:
- `metadata.import_path` -> `tenant_setup_statuses.import_path`
- `metadata.mobile_interest` -> `tenant_setup_statuses.mobile_interest`
- `metadata.module_interests` or `metadata.addons_interest` -> safe visible `module_interests`
- business context (`company`, `message`, `business_type`, `team_size`, `timeline`, `website`) -> `business_profile_status = in_progress` only when the profile is still `not_started`
- review context -> `landlord_review_status = waiting_on_everbranch` only while still pending review
- source evidence -> `internal_notes` entry with the access request id and captured intake values

The bridge is idempotent. It does not duplicate setup status rows, does not duplicate source notes, and does not overwrite existing operator/tenant edits unless the field is still at its safe default.

## Landlord Intake Queue Added In PR 5

`/landlord/onboarding/intake` lists tenant setup statuses with tenant identity, source access request context, import path, Shopify status, Square/CSV/manual state, mobile interest, landlord review status, next action, internal notes, and last update time.

Server-rendered filters:
- `all`
- `waiting_on_everbranch_review`
- `shopify_selected_not_connected`
- `square_selected`
- `csv_selected`
- `manual_selected`
- `undecided_import_path`
- `mobile_interest`
- `reviewed`

The queue reuses the existing landlord review action for review status, next action, and internal notes only.

## Tenant Setup Guidance Polish Added In PR 6

Tenant `/start` now explains what each setup signal means without adding automation:
- Shopify is framed as the primary supported integration path, with connection status derived from existing `shopify_stores` rows.
- Square is described as requested/planned/manual setup unless later connector automation is implemented.
- CSV/manual setup is described as Everbranch-coordinated mapping, validation, and manual setup work.
- Other/undecided paths route the tenant back to Everbranch review rather than pretending automation exists.
- Module interests are shown as planning signals only; they do not install, enable, entitle, or bill modules.
- Android/iOS mobile interest is shown as future companion app planning only; it does not imply a generic Everbranch mobile app is active.
- The page states that self-service checkout, paid module activation, Square automation, CSV execution, and generic mobile app access are not active from setup status.

## Plan Selection Without Billing Added In PR 13

Tenant `/start` now lets the tenant indicate:
- intended plan/package
- likely billing lane, if known
- implementation help interest
- commercial notes/questions

Copy explicitly says:
- plan selection is intent only
- checkout is not active
- billing will not begin automatically
- modules are not installed or enabled by plan selection
- Everbranch reviews commercial intent before any activation

The landlord intake queue shows plan interest, billing lane interest, implementation help interest, commercial notes, commercial review status, and commercial next action. Landlord updates remain review/status updates only.

## Landlord Commercial Intent Gate Added In PR 14

`/landlord/commercial-intent` is an operator-only decision support page. It shows:
- tenants by plan interest
- tenants by billing lane interest
- tenants needing commercial review
- tenants requesting implementation help
- tenants with custom module request context
- tenants blocked by missing plan/lane decisions
- Shopify lane blockers for Partner Dashboard/CLI/dev-store evidence, scope review, branding review, and future Shopify Billing/App Pricing
- Stripe lane blockers for disabled billing posture and future explicit activation

The gate does not charge tenants, create checkout sessions, create subscriptions, generate quotes/invoices, install modules, or change entitlements.

## Required Setup Concepts

- Shopify connection.
- Square customer/order import readiness.
- CSV import readiness.
- Manual import/setup option.
- Android/iOS mobile interest and readiness.
- Business type/template.
- Landlord tenant blueprint/profile.
- Work-management intent for projects/jobs/matters/orders, tasks, assignments, communication, uploads, and mobile field capture.
- Module interest.
- Plan/trial interest without activating checkout.
- Commercial lane interest without activating billing.

## Gaps

- New user to tenant creation is not complete enough for public onboarding.
- Tenant slug/workspace creation needs clear validation and collision handling.
- Import readiness is now visible, but connector automation is still intentionally absent.
- Setup progress has a clearer tenant-facing home on `/start`, but it is still guidance/status rather than a full onboarding automation workflow.
- Mobile readiness must not imply the current Modern Forestry catalog API is generic.
- Landlord setup review is now filterable, but it is still not connector automation or a full custom onboarding workflow.
- Public access request metadata is promoted on approval/provisioning, but the public request is still not a fully self-service tenant creation workflow.
- Landlord blueprints can be created and displayed, but a dedicated edit/review flow for existing tenant blueprints is still future work.
- Plan selection is captured, but real paid plan activation and billing provider selection remain blocked until a future billing activation PR.
- Commercial intent can now be summarized by the landlord, but the summary is a decision gate only and cannot activate paid access.

## Safe To Expose Now

- Business profile readiness.
- Import path intent.
- Shopify connection status as read-only status.
- Square interest/manual setup status.
- CSV/manual setup status.
- Module interests for safe visible modules.
- Android/iOS mobile interest as intent only.
- Landlord review status, next action, and internal notes.
- Plan and billing lane interest as intent only.
- Landlord commercial summary and billing lane blockers as decision support only.

## Must Stay Landlord/Manual

- Tenant approval/provisioning.
- Square setup and import mapping.
- CSV import execution and validation.
- Module enablement/entitlement changes.
- Billing/checkout activation.
- Billing lane activation, checkout, quotes, invoices, and entitlement changes from plan selection.
- Billing lane activation, checkout, quotes, invoices, and entitlement changes from the landlord commercial intent gate.
- Any Android/iOS mobile app rollout.
- Any real project/task/assignment/comment/messaging/photo/file/mobile-capture implementation.

## Pass Criteria

- A user can safely request or create a tenant only through validated server-side scope.
- Setup captures primary data source and import readiness.
- Shopify is prominent but not mandatory.
- Billing is framed as readiness/status until activation gates pass.
- Mobile is captured as Android/iOS interest/readiness, not as launched generic capability.

## Fail Criteria

- Intake creates a tenant with ambiguous slug or ownership.
- A user can attach another tenant's Shopify or Square data.
- Checkout is offered before billing gates pass.
- Setup implies generic mobile APIs are available today.

## Recommended Next PR

Capture external Shopify Partner Dashboard/CLI/dev-store evidence or add a manual commercial follow-up SOP before any billing activation work.
