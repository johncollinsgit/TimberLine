## Everbranch Pay inventory and guest checkout (owner direction, 2026-10-31)

- Everbranch owns finished-goods inventory, customers, in-person sales, receipts
  and rewards. Square owns payment/refund/settlement evidence. New POS sales must
  not create Shopify orders; Shopify remains the existing catalog/price source
  and receives online availability from Everbranch through explicit adapters.
- Tenant owners configure shared or separate stock pools. Modern Forestry uses
  confirmed ready/packed stock per show. Existing event plans mean to produce,
  not units available to sell. After prior show sales, use remaining physical
  counts; never seed original packing totals or deduct historical Square sales
  again. Read `docs/operations/everbranch-pay-owned-inventory.md`.
- Customer selection is optional at checkout. After confirmed payment, staff may
  explicitly associate the receipt with an Everbranch customer by phone/name/email
  lookup. Preserve original financial snapshots, audit associations and earn once
  through the existing ledger. Never infer identity from a card or contact match.
- Preserve legacy original-attempt recovery, materials inventory and domain
  boundaries. New authority and new native controls require protected release,
  reviewed opening counts/mappings and physical acceptance before activation.

## Shared AI provider and publishing flags (2026-10-24)

- Read `docs/operations/provider-terms-and-publishing.md` when changing Apple
  app publishing, Tap to Pay, provider-data retrieval or AI memory/training.
  Flag its applicable requirements and missing evidence in other Everbranch
  work. Publishing disclosures can remain a later milestone; live pilot
  entitlement, merchant and signing requirements still apply before collection.
- Do not repurpose Apple Tap to Pay data for marketing/customer profiling or
  use Square-derived content (even anonymized) to train or enhance AI. Proposed
  Square-data context/embedding/memory use requires provider/legal review.
  Review seller revocation/deletion and record-retention duties together; do not
  silently delete payment evidence or promise that provider content is permanent.
- Verify current terms at release. Agreement acceptance, a developer app and
  passing tests are distinct from legal approval and physical acceptance.

## Universal customer commerce capability (owner direction, 2026-10-30)

- Every tenant must have the included capability to associate confirmed online
  and in-person purchases and rewards with one tenant-scoped canonical customer.
  Modern Forestry is the first pilot, not a hardcoded tenant exception. Read
  `docs/architecture/unified-customer-commerce.md` before customer/POS/rewards work.
- Reuse MarketingProfile, CanonicalMarketingProfileResolver, explicit source
  links and the existing Candle Cash ledger. Keep provider/Website customers and
  orders authoritative in their original domains; an explicit read-only identity
  association is not a customer merge, order copy, marketing consent or paid sale.
- Treat shared customer purchase/rewards capability as core for all plans and
  templates. Merchant program setup and normal payment/permission gates remain
  necessary. Do not invent earn rates, copy balances or silently publish programs.
- Acceptance must prove the same customer across online/in-person purchases,
  replay/refund correctness, customer-facing visibility and tenant isolation.
  Do not claim live readiness from a catalog preview, source tests or a payment
  that has not also proved the identity/history/rewards connections.

## Self-service payment accounts, bank linking and native invoices (2026-10-19)

- New Stripe accounts use full Dashboard access and direct charges; owner-session OAuth can connect an existing Standard account. Frozen request/routing/environment/account context prevents cross-merchant retries. Existing Express reservations and Wren routing stay unchanged. Direct payments require their own connected-event signing secret and live proof gate; new direct attempts use stable platform-account/environment fingerprints across key rotation.
- The shared Accept payments screen separates optional bank feeds from payout setup. Plaid business links request Transactions only; encrypted user/space/browser Link sessions support the original-token OAuth return, safe reconnects and mapped ownership. A registered HTTPS return URL and genuine institution checks gate bank sign-in. No new subscription or accounting writeback.
- Native Website quote/draft/invoice authoring supports immutable issued terms, catalog/custom lines, nonstacking discounts, full payment or deposit+balance, private host-scoped links, one-time stock commitment, receipts and original-account refunds. Customer ACH remains independently default-off and processing until provider confirmation. Never infer paid status from a bank feed or redirect. One additive website_discounts table has durable MySQL recovery and data-free baseline evidence.
- Preserve provider/domain authority and existing entitlement/rollout gates. See `docs/operations/self-service-payments.md`; real merchant onboarding/provider acceptance remain required before customer collection.

## Wren payment choices and offline reconciliation (2026-10-19)

- The public family portal is card-only. Historical Venmo receipts remain available for organizer-verified reconciliation; new public PayPal/Venmo choices are removed. Link clicks never confirm payment. Organizer-confirmed receipts and reasoned fee waivers serialize with checkout, retire/reconcile open Stripe sessions, preserve actual receipts for review and never fabricate revenue. Preview-first `soccer:reconcile-offline` applies a reviewed invoice/family/amount with an active verified organizer.
- Verified PayPal/Venmo create, capture, status, webhook and original-account refunds are implemented behind independent provider/merchant/collection/webhook gates. Order intent is durable and encrypted before HTTP; uncertainty cannot create a replacement order. Current provider status is checked before money movement. Existing Wren Express/destination routing remains unchanged. Real sandbox/live approval and acceptance still gate launch. See `docs/operations/wren-verified-venmo.md` and `docs/operations/wren-soccer.md`.

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
- The MySQL schema dump and fingerprint are data-free release artifacts, not
  backups. Refresh them only via the disposable MySQL baseline workflow and
  review both files with the migration. Production drift checks and restore
  drills are governed by `docs/operations/release-observability-and-recovery.md`.
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

## Wren payment recovery (2026-10-23)

Expired unpaid reservations are payment_pending, not availability waitlists.
Resume preserves saved registration data and signed releases, uses only the
authenticated family, and serializes capacity with checkout. Never release an
unknown provider outcome or invite another payment against a recorded receipt.
Daily reminders stay default-off until existing verified receipts are reconciled.
