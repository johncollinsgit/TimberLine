# Forestry Backstage Guardrails

- Treat `config/module_catalog.php` as the canonical source of truth for plans, modules, capabilities, visibility, billing mode, and CTA routing. Legacy `commercial.php` and `entitlements.php` are compatibility layers only.
- Use `TenantModuleAccessResolver`, `TenantExperienceProfileService`, `UnifiedAppNavigationService`, `UnifiedDashboardService`, and `TenantModuleCatalogService` instead of adding new ad hoc plan, channel, or module checks.
- Tenant-facing mutations must verify tenant scope on the server. Never trust client-provided tenant, module, store, host, or channel identifiers without resolving them against current tenant/store context.
- Public or self-serve surfaces must suppress modules unless they are explicitly safe and visible for that surface. Hidden, internal-only, placeholder, roadmap, or disabled modules should fail closed.
- Entitlement or commercial mutations must be validated, auditable, and safe to replay. Record before/after state and billing impact through the audit layer whenever landlord or module-access state changes.
- Search, dashboard, and navigation payloads must be permission-aware and tenant-scoped. Do not expose marketing-only entities or actions to users who cannot access marketing.
- Do not promote a mixed backend branch straight to `main` when it combines stabilization, commercialization, shell/search, and polish work. Follow the active split plan in `docs/architecture/backend-release-order-2026-04-01.md` and keep Shopify/rewards/storefront stabilization first.
- Keep physical split branches aligned to the release plan: `release-a-stabilization`, `release-b-commercial-core`, `release-c-module-discovery`, `release-d-unified-shell`, `release-e-polish-docs-assets`.
