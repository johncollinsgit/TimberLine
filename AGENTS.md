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
- Website Commerce is a tenant-owned `website_*` lane. Never reuse or touch
  legacy `orders`, `order_lines`, Shopify catalog/customer records, Shopify
  checkout, rewards, or Modern Forestry provider connections when building
  Website catalog, shopper, cart, payment, or fulfillment features.
- Multi-channel reporting may only normalize tenant-scoped, read-only summaries
  through `SalesChannelSummaryService`. Never copy Website orders into legacy
  orders, merge Website shoppers into existing customers, or let reporting
  trigger commerce, marketing, checkout, provider, or fulfillment mutations.
- Website checkout must remain fail-closed behind Managed Website entitlement,
  rollout, Stripe Connect, tax, and signed webhook readiness. Public Website
  commerce routes resolve tenant ownership from the verified public host;
  workspace management routes resolve it via `tenant.access`.
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
- MySQL also limits an InnoDB index to 3072 bytes. Keep composite `utf8mb4`
  string keys within the declared column lengths; the migration linter treats
  each indexed character as up to four bytes.
- Every new migration must pass `scripts/ci/lint-migrations.php`. Released
  migrations are immutable; add a new idempotent repair migration instead of
  editing one. The only exception is an audited clean-install compatibility
  fix for a released migration that cannot run on supported MySQL at all; it
  must be checksum-pinned in
  `scripts/ci/legacy-migration-compatibility-manifest.php`, limited to the
  blocking identifier/restart guard, and exercised by its named MySQL recovery
  test. Guard every table creation with `Schema::hasTable()`, and
  register every multi-step migration in
  `tests/Integration/migration-recovery-manifest.php` with a real MySQL
  durable-partial-state recovery test. The production Migration Safety Gate is
  mandatory even when the full emergency test/build gate is explicitly
  skipped. See `docs/operations/migration-safety-gate.md`.
- The `accounting_command_center` Branch is QuickBooks-authoritative,
  owner/admin-only, tenant-scoped, and disabled by default. Shopify, Square, and
  event spreadsheets are reconciliation sources, not additive ledger revenue.
  Never invent tax conclusions or workbook mappings and never add QuickBooks
  write-back.

## Managed Website safety contract (approved; feature remains disabled)

- `managed_website` is a tenant-scoped, default-disabled add-on. Use the
  canonical module catalog and audited entitlement fulfilment; do not derive
  access from a URL, host, agreement, checkout request, or connection.
- Its schema is additive only: tenant site, drafts, immutable published
  versions, sections, navigation, media references, redirects, and publish
  events. Publishing/rollback move a site-local pointer and cache keys only.
- Modern Forestry's separate Shopify app and Shopify Checkout are a hard
  exclusion. Managed Website work must not modify or invoke their routes, UI,
  credentials, app settings, checkout, customer account, webhooks, orders,
  customers, rewards, imports, existing connections, or workflow cursors.
- Four independently auditable fail-closed gates are required: global
  availability, tenant rollout allowlist, editor/publishing freeze, and public
  rendering disablement for Managed Website hosts. Use
  `docs/operations/managed-website-rollback-runbook.md`; preserve published
  snapshots and additive records during rollback.
- Public website rendering must follow existing landlord, authenticated app,
  Shopify app/Checkout, customer-account, webhook, and established public
  routing. Unknown or unverified hosts fail closed. V1 external CTA blocks only
  link out; forms create tenant-scoped submissions and must not create
  customers, messages, marketing events, workflows, or orders.
- Site-wide styling, menus, footer, announcement, and SEO defaults are versioned
  in `tenant_site_versions` separately from page versions. Public pages must
  resolve only the published site and page snapshot pair; authenticated preview
  uses draft snapshots with `no-store` caching.
