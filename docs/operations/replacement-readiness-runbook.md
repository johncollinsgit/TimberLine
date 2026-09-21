# Replacement Readiness Runbook

## Purpose

Replacement Readiness is the tenant- and Shopify-store-scoped control plane for
retiring selected third-party Shopify apps without changing live customer or
staff behavior before the replacement is proven. The embedded page is
`/shopify/app/replacements`.

The tracked program covers Storeify forms, Omnium locator, Shop Calendar, TnC
commerce consent, shipping, Recharge, and certification of the already-live
Product Options, Birthday/Rewards, wholesale gateway, and Wholesale Backstage
surfaces.

## Safety boundary

- `REPLACEMENT_ACTIVATION_ENABLED` defaults to `false` and is independent of
  module access, theme settings, and subscription billing settings.
- Every module and evidence row is tenant scoped. Every module also owns one
  `shopify_store_id`; a mutation from another store is rejected.
- An activation request additionally requires an active owner/admin tenant
  membership and an allowlisted Shopify admin ID or email. Recharge is rejected
  by the controller even when the common release gate is enabled.
- Imports never publish a form or locator record, send a notification, return a
  checkout rate, create an order, or run billing.
- Source snapshots are immutable. A changed export creates a new checksum and
  import batch. Import row source keys make replays idempotent.
- Candidate secrets are replaced with `[RECONNECT_REQUIRED]`. Reconnect them
  through the provider authorization flow; do not place credentials in export
  files, evidence payloads, logs, or email.
- A module stays on its legacy provider until every required evidence row is
  passing and a concrete activation adapter exists. A missing export or test is
  a visible blocker, never an implicit pass.

## Current import package

The 2026-09-21 audit package contains four Storeify XLSX exports totaling 338
submissions and an Omnium JSON export containing 44 locations and map settings.
The files remain outside the repository because the form exports contain
customer data.

Validate the package without database writes:

```bash
php artisan everbranch:replacement-readiness:prepare \
  --source-dir=/secure/path/shopify-app-audit-2026-09-21 \
  --module=all \
  --verify-only
```

After the migrations are deployed, import it for the retail store:

```bash
php artisan everbranch:replacement-readiness:prepare \
  --tenant=modern-forestry \
  --store=retail \
  --source-dir=/secure/path/shopify-app-audit-2026-09-21 \
  --module=all
```

The Storeify importer creates two draft customer-form candidates and two
history-only archives. It records all 338 submissions in the tenant forms
system and exposes search, workflow status, assignment, and CSV export in the
admin preview. It sends zero notifications. The Omnium importer creates draft
settings and 44 unpublished locations; the existing free-plan locator remains
the live provider.

## Evidence states

The lifecycle is `draft`, `importing`, `reconciled`, `shadow_testing`, `armed`,
`active`, `rolled_back`, or `blocked`. Recharge uses `armed_for_pilot`; an
already-live replacement becomes `live_verified` only after every certification
gate passes.

Evidence is versioned. Passing evidence followed by a failed rerun creates a
new required version and blocks the module. The dashboard reports source and
target counts, the latest batch, rejected rows, fingerprints, blockers, and
activation history. The **Re-run readiness gates** control only recalculates
state from current evidence.

## Module-specific stop conditions

- **Storeify:** do not activate until notification destinations, spam controls,
  confirmation behavior, attachments, customer previews, accessibility,
  performance, a current theme clone, rollback, and full-domain crawl pass.
- **Omnium:** do not activate until the public responsive map/list surface,
  search, directions, keyboard behavior, accessible markers, geocoding review,
  theme candidate, rollback, and crawl pass. Keep Omnium installed on its free
  plan through observation.
- **Calendar:** no complete authenticated source/settings export is present.
  Internal planning events cannot become public source rows. Do not synthesize
  or infer missing events.
- **TnC:** no exact terms/rules/evidence export is present. Commerce consent must
  remain separate from marketing consent and must pass server-side cart and
  checkout enforcement, accelerated checkout, Shop Pay, mobile cart,
  subscription, and direct-cart tests.
- **Shipping:** PluginHive confirmed 39,595 live FedEx rate calls over 2,140
  days. It is an active checkout dependency. PluginHive cannot export the app
  configuration, so a dated video capture of every settings screen and a
  field-by-field transcription are required source evidence. Do not remove or
  refund it until settings, carrier authorization, rate shadowing, labels, tracking, open
  shipments, atomic duplicate-rate exclusion, rollback, and representative
  carts pass. Native Website shipping is a separate system and cannot be used
  as a Shopify replacement shortcut.
- **Recharge:** the live provider remains authoritative. Synthetic dry-run
  ingestion and intent-only admin actions are disabled on the embedded surface.
  Do not pause Recharge, change Braintree/Shopify payment migration settings,
  enable the Everbranch billing scheduler, activate a cohort, or request a
  refund. Full readiness requires authenticated ingestion, webhook mirroring,
  payment-method migration evidence, billing and order parity, dunning,
  duplicate-charge protection, portal and staff parity, ledger reconciliation,
  rollback, and a controlled cohort completing one renewal cycle.

## Activation procedure

There is no all-modules control. For one non-Recharge module:

1. Confirm every required evidence row is current and passing.
2. Confirm the module-specific adapter performs the real theme/config/provider
   switch and immediate customer and admin smoke checks. A database flag alone
   is insufficient.
3. Confirm the live theme/config fingerprint matches the prepared candidate.
   Regenerate the candidate after any intervening theme change.
4. Enable the activation release gate only for the approved release window and
   configure the owner allowlist.
5. Use that module's **Activate** button once. The idempotency key, module lock,
   before/after payload, operator, smoke results, and rollback payload are
   recorded.
6. A failed smoke check must restore the previous provider immediately. Keep
   the vendor installed through the observation period and obtain separate
   approval before uninstall or cancellation.
7. Turn the release gate off after the isolated cutover.

## Verification

Run:

```bash
php scripts/ci/lint-migrations.php --base=HEAD --working-tree
php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/ReplacementReadinessTest.php tests/Integration/ReplacementReadinessMySqlMigrationRecoveryTest.php
composer test:modern-forestry
php artisan view:cache
```

Before any production activation, also save desktop/mobile browser evidence,
keyboard and automated accessibility results, performance comparison, failure
injection, rollback results, and a zero-new-404 crawl for both storefronts.
