# Everbranch Mobile Platform and Branches

## Contract v2 Operational Branches (2026-07-10)

- App 1.1.0 uses tailored Messaging, Customers, Work, Reporting, Search, Account, and landlord surfaces. Generic Share and summary-only Branches are not valid mobile workflows.
- `work_core` is canonical and included in every plan. Laravel resolves retail orders, field jobs, or client projects from tenant blueprint first and experience signals second.
- Messaging reads across the authenticated tenant's server-owned store keys and supports Text, Email, and eligible Modern Forestry App threads. Every send requires `mobile:write`, entitlement, channel readiness, and `Idempotency-Key`.
- Tenant-facing payloads and copy use Branches. Bootstrap returns `branches`; `modules` and `/modules/{key}` remain compatibility aliases through the next app release.
- Landlord access is independent from workspace membership and exposes audited triage only. Destructive tenant/configuration and live billing changes remain web-only.

## Customer Administration Contract (2026-08-08)

- The Customers Branch list and detail remain available to entitled workspace members. The API returns `permissions.manage` for presentation, but Laravel independently requires `mobile:write`, current tenant membership, the Customers Branch, and owner/admin equivalence on every mutation.
- Owner/admin users may create and edit tenant-owned customer profiles from the native app. Email and phone identities are normalized and duplicates are rejected inside the current tenant.
- Hard delete is not general customer management. It is allowed only for an app-created `mobile_manual` profile with no connected provider, work, messaging, consent, delivery, reward, birthday, or group history. Connected profiles return `409` and remain available for editing.
- Every create, update, and safe delete writes an immutable operator audit record. The mobile app never queues customer mutations offline.
- Collins Upstate Electric appears through ordinary authenticated workspace membership and the workspace switcher. No Collins slug, user email, or tenant ID is a client authorization rule.

## Field Service Contract v4 / Work 2.0

- Work 2.0 extends the existing Field Service aggregate. It does not add `WorkOrder`, `Appointment`, or a universal task table.
- Native Work lists may reveal the manager-authorized `archive` transition with a left swipe. The server maps it to `history`, records a lifecycle note, and retains Reopen; clients must not implement archive as deletion or hide the server permission check.
- Readiness remains server-computed, but clients present missing keys as field-specific setup actions rather than warnings. Managers write those fields through the existing job update endpoint; members receive the same tenant-scoped read model without elevated edit capability.
- Job-site address import order is usable QuickBooks Ship To, existing confirmed job site, transaction Bill To, then imported customer address. Address fallback is separate from operational-evidence classification.
- Bootstrap resolves one server-owned profile (`trades`, `professional`, `retail_production`, `generic`) from tenant blueprint metadata. Entitlement metadata controls `experience_version`; Collins is the first version-2 trades pilot.
- Contract v4 adds profile labels/capabilities, viewer capabilities, readiness, typed destinations, My Day, guarded transitions, task ownership/completion, notification feed/unread state, and separate photo/document counts.
- Mobile and web use the same readiness, access, lifecycle, and transition services. Clients display permissions but never grant them.
- Everbranch APNs uses the `com.everbranch.app` device table and dedicated credentials. Modern Forestry push infrastructure is a separate product boundary.
- Compatibility routes remain active. Other tenant profiles continue their existing Work surfaces until their renderer is deliberately upgraded and tested.

## Manager Time Clock & Hours Contract (2026-09-03)

- `GET /api/mobile/v1/workspaces/{tenant}/field-service/time-clock-hours` requires `FieldServiceAccessService::canManageJobs` and the enabled `time_tracking` entitlement. Supported presets are `week`, `pay_period`, and `month`; `custom` requires inclusive `start_date` and `end_date` values and is capped at 366 days. The response identifies the tenant timezone, aggregates approved/submitted time by employee, job, and local day, and pages a unified timer/manual ledger at 10–50 rows per page.
- `edit_options` is limited to 250 active workspace members and 250 relevant jobs, with explicit truncation flags. Every submitted employee/job choice is independently resolved inside the current tenant; the response is presentation metadata, not authorization.
- `PATCH .../time-clock-hours/{source}/{id}` accepts a nonempty subset of timestamps, break seconds, `submitted|approved|rejected` status, notes, employee, and job. Running and paused timers cannot be corrected. End must follow start, breaks must be shorter than the period, manual entries remain on one local date with whole-minute breaks, and duration is server-recomputed.
- A timer correction changes `field_service_time_sessions.break_seconds` as the reviewed aggregate while preserving each `field_service_time_breaks` punch. The audit records the before/after aggregate and unchanged raw event count/seconds. Employee reassignment checks the tenant/user/client UUID idempotency key before writing and returns validation failure rather than exposing a database conflict.
- This surface reports operational timecards only. It does not calculate overtime, wages, payroll, taxes, withholding, filings, remittance, or payments.

## Manager Material Request and Job Edit Contract (2026-09-03)

- Manager My Day returns at most 25 pending `requested_materials` rows and a separate exact `counts.material_requests`. Each row includes job, requester when known, creation time, status, purchase/delete permissions, and a typed destination for the job Materials tab. Existing rows without a requester remain valid with `requester: null`.
- The manager-only material DELETE route re-resolves tenant, job, and request; it rejects inventory/catalog/provider material and writes an audit snapshot before deletion. Purchasing continues through the existing material update route and may retain an admin note for the crew.
- The Team response supplies active, tenant-scoped vehicle choices only to job managers. Full job edits independently validate active leads/participants, vehicles, and strict schedule ordering, then audit supported fields and assignment IDs. Lock-box audit state is boolean-only; the secret itself is never recorded.

## Boundaries

The tenant app is a separate repository at `../everbranch-mobile`, bundled with React/TypeScript and Capacitor for `com.everbranch.app` on iOS and Android. It does not wrap the production web app and does not replace or modify the Modern Forestry SwiftUI customer app. The initial lane is a US B2B pilot.

## Trust and Session Contract

1. The app opens `/mobile/authorize` in the system browser. Existing Fortify login, email verification, and 2FA remain authoritative.
2. Laravel issues a five-minute, single-use authorization code bound to `everbranch-mobile`, the custom callback, state, and an S256 PKCE challenge. Exchange consumes it transactionally.
3. Sanctum issues a named, expiring device token. Native clients store it only in Keychain/Android Keystore and can list, revoke, rotate, or sign out device sessions.
4. `EnsureMobileTenantAccess` resolves a workspace slug only against the authenticated user's active memberships, sets request tenant attributes and `TenantContext`, and returns 404 for cross-tenant attempts.
5. Controllers and providers then enforce role, canonical module entitlement, and tenant scope on every referenced resource. Client tenant, store, channel, module, job, and billing identifiers are never trusted.

## Rendering Contract

`TenantMobileModuleRegistry` is contract version 2. It filters the canonical catalog by mobile readiness and `TenantModuleAccessResolver`, then returns data/layout using finite primitives: dashboard, metrics, list/search, detail, form, action sheet, tabs, notice, empty, and error states. It never accepts executable JavaScript or arbitrary remote UI.

A declaration names its renderer, entry screen, contract version, minimum binary version, navigation position/icon, and supported primary actions. A new module can appear after payment/refresh without a binary release only when it uses an already supported renderer and action vocabulary. A new primitive requires an app release and higher `min_app_version`.

The pilot includes Customers, Field Service, Messaging, and Reporting. Field Service camera capture is a real multipart module action: the server validates the declared action, current entitlement, tenant-owned job, image type/size, actor, and storage path before recording it.

## Branches and Billing

Branches is the module store name on mobile. Its payload is produced by `TenantModuleCatalogService` and `TenantModuleAccessResolver`, using `visibility.mobile_store` as an additional fail-closed discovery gate. Owners/admins may use a guarded hosted-billing handoff; managers submit the existing audited request. No response from the client activates a module.

Canonical plan and add-on entries own stable purchase keys, prices, and Stripe lookup metadata. `commercial.php` projects those values for compatibility. Verified Stripe lifecycle events retain the existing replay receipt, audit, and commercial fulfillment behavior and also update `tenant_billing_subscriptions`, keyed by tenant, provider subscription reference, and canonical purchase key.

Checkout opens Stripe Checkout or Customer Portal in the system browser only when the existing checkout and lifecycle flags are both enabled. US storefront gating fails closed; non-US surfaces remain request/manage-existing only. Apple and Google external-payment policy must be checked again immediately before submission.

## New Module Checklist

1. Add the module, plan/add-on relation, stable purchase key, pricing/lookup metadata, visibility, and full mobile declaration to `config/module_catalog.php`.
2. Prove tenant scoping for every read and mutation. Apply canonical role and `module:{key}` access semantics; reject spoofed tenant/resource IDs.
3. Add a provider/schema to `TenantMobileModuleRegistry` using supported primitives. Keep unsafe, placeholder, roadmap, and web-link-only states absent.
4. Declare and implement supported actions. Validate action vocabulary server-side and provide at least one meaningful phone workflow.
5. Cover contract validation, inactive users, membership spoofing, cross-tenant reads/writes, roles, gates, suppression, entitlement gain/loss, and action replay/error behavior.
6. Add client decoding, navigation, tenant switching, deep-link, session recovery, offline read-only, and entitlement-refresh tests as applicable.
7. Capture small/large iOS and Android screenshots. Check keyboard, text scaling, VoiceOver/TalkBack, camera/files, no overlap, startup, and poor-network recovery.
8. Update this document, the service/client READMEs, system snapshot, and module readiness notes.

## Release Gates

Backend and web-contract tests are necessary but insufficient. Rollout proceeds through sandbox/demo tenants, TestFlight and Play internal testing, a selected-tenant pilot, then US production. Store submission additionally requires biometric re-entry, push-token registration, foreground/deep-link evidence, privacy manifests, App Privacy/Data Safety answers, privacy/terms and account-removal links, review credentials, screenshots, reviewer notes, and a current policy review.

Billing flags remain off until signed sandbox evidence covers checkout, webhook replay, cancellation, failed payment, proration, refund, entitlement activation, and entitlement reversal. Broad tenant rollout waits until every catalog entry marked mobile ready passes this contract.
