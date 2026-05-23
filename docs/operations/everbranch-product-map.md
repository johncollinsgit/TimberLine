# Everbranch Product Map Audit

Status: PR 1 audit document.

## Mission

Map public, tenant, landlord, Shopify embedded, auth, onboarding, and internal surfaces so Everbranch can become understandable before new features are added.

## Current State

Public:
- `theeverbranch.com` is the canonical public host.
- Public product surfaces exist under `/platform/promo`, `/platform/contact`, and plan/module oriented routes.

Landlord:
- `app.theeverbranch.com` is the canonical landlord app host.
- Landlord pages include dashboard, commercial configuration, tenant directory/detail, and operational diagnostics.
- Routes are host-locked and protected by `landlord.operator`.

Tenant:
- Tenant hosts follow `<slug>.theeverbranch.com`.
- Tenant app surfaces include dashboard, setup/onboarding, modules, integrations, and marketing/commerce tooling.
- Some tenant navigation is capability-aware through canonical services.

Shopify embedded:
- Embedded app surfaces exist under `/shopify/app`.
- Shopify OAuth callbacks are canonical to `app.theeverbranch.com`.

Auth/onboarding:
- Registration exists, but new users do not yet complete a full self-service tenant creation and setup path.
- `/platform/start` supports guided access requests.
- `/onboarding` is useful once tenant context exists.

Internal:
- Debug/harness routes exist behind local/debug gates.
- Several operational pages are useful but not yet organized as a SaaS operator console.

## Confusing Or Duplicated Areas

- Product language mixes Everbranch, Backstage, Forestry Backstage, Forestry Studio, and Modern Forestry.
- "Start Here", onboarding, public start, Shopify setup, and tenant setup overlap.
- Modules are discoverable through several contexts but lack one clear product definition page.
- Mobile capability exists only as a Modern Forestry catalog API, but product direction now includes Android/iOS readiness.
- Shopify is prominent, but non-Shopify customer import paths need clearer placement.

## Missing Pages For The Product Vision

- Public overview that explains Everbranch quickly without implying Shopify is mandatory.
- Setup page that captures Shopify, Square, CSV, manual import, and mobile app needs.
- Tenant setup progress page that distinguishes required, optional, blocked, and manual steps.
- Tenant account/workspace settings.
- Module App Store detail/request pages.
- Custom module request page.
- Landlord intake queue and mobile readiness view.

## Target Navigation

Public:
- Home
- Plans
- Modules
- Integrations
- Mobile
- Start/contact

Tenant:
- Home
- Setup
- Modules
- Integrations
- Customers/imports
- Mobile
- Settings
- Support/custom requests

Landlord:
- Dashboard
- Tenants
- Users
- Stores/integrations
- Onboarding/intake
- Modules
- Billing readiness
- Custom module requests
- Audit/support

## Pass Criteria

- Every route group has an owner: public, landlord, tenant, Shopify embedded, internal, or API.
- Tenant and landlord routes remain host separated.
- Public surfaces never show internal/unsafe modules.
- Product language clearly separates Everbranch platform from Modern Forestry tenant.

## Fail Criteria

- Legacy hosts are accepted by Laravel runtime.
- Tenant routes can reach landlord pages.
- Internal, roadmap, or placeholder modules appear in public or tenant App Store surfaces.
- Current mobile API is described as a generic Everbranch mobile platform.

## Recommended Next PR

Create a route/page inventory table and add a small brand label inventory. Do not rename routes yet.

