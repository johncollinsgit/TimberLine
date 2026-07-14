# Everbranch Mobile Platform and Branches

## Contract v2 Operational Branches (2026-07-10)

- App 1.1.0 uses tailored Messaging, Customers, Work, Reporting, Search, Account, and landlord surfaces. Generic Share and summary-only Branches are not valid mobile workflows.
- `work_core` is canonical and included in every plan. Laravel resolves retail orders, field jobs, or client projects from tenant blueprint first and experience signals second.
- Messaging reads across the authenticated tenant's server-owned store keys and supports Text, Email, and eligible Modern Forestry App threads. Every send requires `mobile:write`, entitlement, channel readiness, and `Idempotency-Key`.
- Tenant-facing payloads and copy use Branches. Bootstrap returns `branches`; `modules` and `/modules/{key}` remain compatibility aliases through the next app release.
- Landlord access is independent from workspace membership and exposes audited triage only. Destructive tenant/configuration and live billing changes remain web-only.

## Field Service Contract v4 / Work 2.0

- Work 2.0 extends the existing Field Service aggregate. It does not add `WorkOrder`, `Appointment`, or a universal task table.
- Bootstrap resolves one server-owned profile (`trades`, `professional`, `retail_production`, `generic`) from tenant blueprint metadata. Entitlement metadata controls `experience_version`; Collins is the first version-2 trades pilot.
- Contract v4 adds profile labels/capabilities, viewer capabilities, readiness, typed destinations, My Day, guarded transitions, task ownership/completion, notification feed/unread state, and separate photo/document counts.
- Mobile and web use the same readiness, access, lifecycle, and transition services. Clients display permissions but never grant them.
- Everbranch APNs uses the `com.everbranch.app` device table and dedicated credentials. Modern Forestry push infrastructure is a separate product boundary.
- Compatibility routes remain active. Other tenant profiles continue their existing Work surfaces until their renderer is deliberately upgraded and tested.

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
