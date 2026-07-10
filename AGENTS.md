# Forestry Backstage Guardrails

- This repo is John's custom Modern Forestry backend first. Tenant `1` is the owned Modern Forestry workspace; protect it before making reusable-platform changes.
- Everbranch-branded modules and elements such as Rewards, Birthdays/Lifecycle, Branches, messaging, and workspace account configuration are allowed in the Modern Forestry-owned app when they are intentionally scoped to tenant `1` or guarded shared-capable contracts.
- Do not treat Modern Forestry customer-app, Candle Cash, Candle Club, Shopify, production-ops, or mobile behavior as generic tenant behavior unless a human explicitly asks for that generalization and tests prove tenant isolation.
- Commercial metadata may be present while dormant. Purchase keys, pricing, Stripe lookup metadata, or ledger rows must not activate billing, install modules, change entitlements, or expose paid actions while checkout/lifecycle flags are off.
- Treat `config/module_catalog.php` as the canonical source of truth for plans, modules, capabilities, visibility, billing mode, and CTA routing. Legacy `commercial.php` and `entitlements.php` are compatibility layers only.
- Use `TenantModuleAccessResolver`, `TenantExperienceProfileService`, `UnifiedAppNavigationService`, `UnifiedDashboardService`, and `TenantModuleCatalogService` instead of adding new ad hoc plan, channel, or module checks.
- Tenant-facing mutations must verify tenant scope on the server. Never trust client-provided tenant, module, store, host, or channel identifiers without resolving them against current tenant/store context.
- Public or self-serve surfaces must suppress modules unless they are explicitly safe and visible for that surface. Hidden, internal-only, placeholder, roadmap, or disabled modules should fail closed.
- Entitlement or commercial mutations must be validated, auditable, and safe to replay. Record before/after state and billing impact through the audit layer whenever landlord or module-access state changes.
- Search, dashboard, and navigation payloads must be permission-aware and tenant-scoped. Do not expose marketing-only entities or actions to users who cannot access marketing.
- Do not promote a mixed backend branch straight to `main` when it combines stabilization, commercialization, shell/search, and polish work. Follow the active split plan in `docs/architecture/backend-release-order-2026-04-01.md` and keep Shopify/rewards/storefront stabilization first.
- Keep physical split branches aligned to the release plan: `release-a-stabilization`, `release-b-commercial-core`, `release-c-module-discovery`, `release-d-unified-shell`, `release-e-polish-docs-assets`.
- Releases A through E are complete on `main`; the next standalone backend track is email/provider reliability. Keep that work isolated from App Store, shell, dashboard, search, commercialization, and deferred expansion scope.
