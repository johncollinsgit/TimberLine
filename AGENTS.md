# Forestry Backstage Guardrails

## Required Orientation and Release Safety (2026-07-21)

- Read `SYSTEM_SNAPSHOT.md`, then `README_FOR_AGENTS.md`, before broad work.
  Update those documents and the relevant runbook/changelog when your change
  alters current system structure or operating practice.
- Production is `app.theeverbranch.com` on Forge server `129.212.138.111`.
  `/up` is liveness; `/ready` is the deploy readiness endpoint and exposes the
  active release ID only after Laravel, database, cache, and required config
  checks pass.
- Forge is configured for zero-downtime release directories with shared
  `storage`, retained releases, and a readiness health check. Keep the GitHub
  test/build gate as the release authority. Do not enable direct push-to-deploy
  because it bypasses that gate.
- GitHub Actions posts to the protected production `FORGE_DEPLOY_HOOK_URL`
  only after its test/build gate passes; Forge then activates the atomic
  release. This was verified by automatic Forge release `73789933` for commit
  `c272464…` on 2026-07-21. Do not reintroduce normal-use `git reset`,
  `git clean`, live-directory asset replacement, or broad cache clearing to
  production deployments.

- Treat `config/module_catalog.php` as the canonical source of truth for plans, modules, capabilities, visibility, billing mode, and CTA routing. Legacy `commercial.php` and `entitlements.php` are compatibility layers only.
- Use `TenantModuleAccessResolver`, `TenantExperienceProfileService`, `UnifiedAppNavigationService`, `UnifiedDashboardService`, and `TenantModuleCatalogService` instead of adding new ad hoc plan, channel, or module checks.
- Tenant-facing mutations must verify tenant scope on the server. Never trust client-provided tenant, module, store, host, or channel identifiers without resolving them against current tenant/store context.
- Public or self-serve surfaces must suppress modules unless they are explicitly safe and visible for that surface. Hidden, internal-only, placeholder, roadmap, or disabled modules should fail closed.
- Entitlement or commercial mutations must be validated, auditable, and safe to replay. Record before/after state and billing impact through the audit layer whenever landlord or module-access state changes.
- Search, dashboard, and navigation payloads must be permission-aware and tenant-scoped. Do not expose marketing-only entities or actions to users who cannot access marketing.
- Do not promote a mixed backend branch straight to `main` when it combines stabilization, commercialization, shell/search, and polish work. Follow the active split plan in `docs/architecture/backend-release-order-2026-04-01.md` and keep Shopify/rewards/storefront stabilization first.
- Keep physical split branches aligned to the release plan: `release-a-stabilization`, `release-b-commercial-core`, `release-c-module-discovery`, `release-d-unified-shell`, `release-e-polish-docs-assets`.
- Releases A through E are complete on `main`; the next standalone backend track is email/provider reliability. Keep that work isolated from App Store, shell, dashboard, search, commercialization, and deferred expansion scope.
- Collins Electric (`collins-electric`) is a guided electrician launch workspace, not a trial or live billing customer. Keep `everbranch:prepare-collins-electric` idempotent, keep QuickBooks as CSV/XLSX concierge import, keep Apple Photos manual, and keep SMS sends blocked until provider/consent/delivery readiness is verified.
- MySQL limits identifiers to 64 characters. In migrations, never rely on Laravel-generated foreign-key or index names when the table and column names could approach that limit; assign concise explicit names (preferably 60 characters or fewer) before deployment.
