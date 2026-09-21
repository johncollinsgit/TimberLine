# Replacement Readiness Restart Handoff

**Last updated:** 2026-09-21

**Working branch:** `feature/replacement-readiness-2026-09-21`

**Starting commit:** `364b68b2`

**Deployment state:** Not deployed

**Cutover state:** No replacement was activated

Read this file, `SYSTEM_SNAPSHOT.md`, and
`docs/operations/replacement-readiness-runbook.md` before continuing this
program.

## Non-negotiable live-state rules

1. Keep `REPLACEMENT_ACTIVATION_ENABLED=false` until one named non-Recharge
   module passes every current gate and has a real provider-switch adapter,
   customer and staff smoke checks, and tested rollback.
2. Recharge remains the live subscription authority. Do not request a Recharge
   refund, connect or change Braintree/PayPal, begin the Shopify Checkout
   migration, pause contracts, change billing authority, or enable Everbranch
   billing. Recharge ticket `#1225179` is open for information only.
3. Keep PluginHive PH Ship, Rate & Track for FedEx active. It is a production
   checkout dependency with 39,595 recorded rate calls. Do not uninstall or
   refund it before rate, label, tracking, and rollback parity is proven.
4. Do not commit the source exports. The Storeify workbooks contain customer
   data and remain under
   `/Users/johncollins/Code/output/shopify-app-audit-2026-09-21`.
5. Never mark a placeholder or intent-recorded action as complete. Missing
   source data or a missing live check remains a visible blocker.
6. Activate at most one store-scoped module at a time. There is no global
   activate-all operation.

## What is implemented locally

- Eight guarded migrations provide store-scoped replacement modules, immutable
  source snapshots, idempotent import batches and rows, versioned evidence,
  activation runs, stockist settings, and stockist locations.
- The embedded Replacement Readiness Center is registered at
  `/shopify/app/replacements` with progress, blockers, savings, previews,
  owner-only activation checks, audit history, and store isolation.
- Storeify ingestion handles the four real audited workbooks totaling 338
  submissions. It creates draft Contact and Job Opportunity candidates and
  history-only Forestry Weekend and old Wholesale Application records. It
  sends no notifications and publishes nothing.
- The Storeify admin preview has search, workflow status, assignment history,
  and authenticated CSV export.
- Omnium ingestion handles the real JSON export with 44 locations and settings.
  Secret-like fields are scrubbed and all locations remain unpublished.
- Recharge's synthetic migration, cutover approval, and intent-only contract
  controls fail closed on the embedded surface.
- The activation service has locking, idempotency, evidence rechecks, rollback
  snapshots, operator audit, and default-off release control. The provisional
  database adapter cannot report itself available without verified storefront
  adapter metadata and both source and target fingerprints.
- Savings are deduplicated by module across stores. The intended amounts are
  $1,475.28/year confirmed, $383.76/year additional non-Recharge savings, and
  $1,859.04/year projected total.

## Real source package status

The package was read and checksummed with `--verify-only`; no production rows
were changed:

| Source | Expected | SHA-256 |
|---|---:|---|
| `contact-us-5367.xlsx` | 90 | `45e5dc5a287549be4a71e210d0154d7e92ef3d1a2ea2492bc817c9ff4c16e0ec` |
| `forestry-weekend-16798.xlsx` | 41 | `2c59c67d58013d746f3e2aecda95321bd544200ac9872dda91fc18361637e7d1` |
| `job-opportunity-5426.xlsx` | 108 | `8ca49e8ce779f62a882e075b8988c29494012b683f5396a317dafa00edd957c0` |
| `wholesale-application-5368.xlsx` | 99 | `183adb743dd8fe64a9013c873551da0fe46ec38456a8bfbf68fe249eb682b1b6` |
| `omnium-full_data.json` | 44 locations | `7ee9c8a1497f3a798b05b5390b8fb6e1dd606e8ab51719d7e561ceaee79dcbbd` |

The import code is tested, but the real data is **not imported into the
production tenant**. Deployment and production migrations must happen first.

## Module queue and exact remaining work

| Module | Current position | Required next work |
|---|---|---|
| Storeify | Importer and candidate admin are implemented; 338-file package reconciles in verification | Deploy, run the real import for the retail store, capture source settings, implement/test live form rendering, notification and spam behavior, attachments, theme candidate, accessibility, performance, rollback, and complete browser/crawl evidence |
| Omnium | Importer and inactive data preview are implemented; 44 locations/settings available | Deploy and import; finish locator CRUD, deduplication, validation and geocoding review; build public responsive map/list synchronization, search, directions, accessible markers, theme candidate, performance, rollback, and browser/crawl evidence |
| Shop Calendar | Catalog and gates exist | Obtain and checksum complete event/settings export; implement import, public/internal separation, recurrence/timezone/cancellation admin, public views, reconciliation, theme candidate, and rollback |
| TnC consent | Catalog and gates exist | Obtain exact terms, rules, placements, conditions, stored evidence, and theme configuration; implement versioned commerce consent, admin tools, storefront and server enforcement, accelerated checkout/Shop Pay/mobile/subscription/direct-cart tests, theme candidate, and rollback |
| Shipping | Catalog and gates exist; PluginHive remains live | In a signed-in Shopify session, record every PluginHive settings page and transcribe fields because the vendor cannot export them; inventory all other shipping apps/profiles/accounts/packages/open shipments; reconnect secrets safely; implement diagnostics, shipment/label/void/refund/tracking tools; shadow representative rates; prove atomic removal of duplicate legacy rates and rollback |
| Recharge | Live provider; Everbranch production actions are deliberately blocked | Build authenticated Recharge ingestion and webhook mirror; implement full customer/staff parity, Shopify contract and billing engine, dunning, notifications, ledger reconciliation, payment-method migration evidence, idempotency and recovery; use a test store, then shadow production; only a separately approved controlled cohort may start after all gates pass and it must complete a renewal cycle before full readiness |
| Existing Everbranch replacements | Existing live behavior unchanged | Certify Product Options, Birthday/Rewards, wholesale gateway, and Wholesale Backstage with production counts, settings, permissions, customer/staff actions, isolation, audit, rollback, accessibility/performance where applicable, and crawl evidence before setting `live_verified` |

## First actions for the next work session

1. Protect the unrelated working-tree changes listed below. Do not discard,
   stage, or rewrite them.
2. Review the readiness diff and run the verification commands below.
3. Resolve or formally isolate the existing full-suite signed-URL failure. Do
   not deploy while the repository-required suite is red.
4. Commit the readiness work on an appropriate branch without including source
   exports or unrelated changes.
5. Deploy through the repository's normal GitHub CI/Forge path and run the
   migrations.
6. Securely transfer the source package outside source control and run:

   ```bash
   php artisan everbranch:replacement-readiness:prepare \
     --tenant=modern-forestry \
     --store=retail \
     --source-dir=/secure/path/shopify-app-audit-2026-09-21 \
     --module=all
   ```

7. Confirm production counts are exactly 338 Storeify submissions and 44
   Omnium locations, with zero rejected rows, zero duplicate source keys, draft
   forms, unpublished locations, and no notification sends.
8. Open the production Replacement Readiness Center from the signed Shopify
   retail context and verify every control and blocker. Do not enable the
   activation release flag.
9. Obtain the missing Calendar and TnC exports and capture PluginHive settings.
10. Continue one module at a time through customer/admin implementation,
    shadow testing, accessibility, performance, rollback, and site-integrity
    evidence. Only then may a non-Recharge module become `armed`.

## Verification commands and last result

```bash
vendor/bin/pint --dirty --test
php artisan view:cache
php artisan test \
  tests/Feature/ReplacementReadinessTest.php \
  tests/Feature/ShopifyEmbeddedSurfaceIsolationTest.php \
  tests/Feature/Subscriptions/SubscriptionModuleTest.php \
  tests/Integration/ReplacementReadinessMySqlMigrationRecoveryTest.php
php artisan everbranch:replacement-readiness:prepare \
  --verify-only \
  --source-dir=/Users/johncollins/Code/output/shopify-app-audit-2026-09-21
git diff --check
composer test:modern-forestry
```

Last focused result: 44 passed, 205 assertions, with the MySQL-only recovery
test skipped when MySQL was unavailable. Pint, Blade compilation, migration
linter, source verification, and `git diff --check` passed.

The required full suite last reported 151 passed and one failure in the existing
scheduled rewards finance report test: it expected signed URLs under
`https://app.theeverbranch.com` and received `https://localhost`. No readiness
code touches that service, but the failure must be resolved or explained by the
repository's deployment rules before release.

## Public-site and browser evidence

- The last public crawl checked 269 URLs across the two domains: all returned
  HTTP 200 and there were zero 404s.
- One existing Prestige-theme empty-cart JavaScript error attempts to access a
  null `parentNode`; it predates this readiness work.
- No replacement was activated, so there is no post-cutover crawl.
- The in-app browser had no available signed-in browser binding in the last
  session. PluginHive settings capture and the final visual desktop/mobile
  click-through remain outstanding.

## Refund and vendor correspondence state

Issued Shopify credit notes total **$32.06**:

- Minmaxify: three credits of $5.34, totaling $16.02.
- Omnium: $5.34.
- Happy Birthday ticket `#3928`: $10.70 including tax.

Pending, unapproved requests total **$115.96 before tax** (approximately
$124.08 if the same 7% tax applies), plus an unknown Replaceit amount:

- Storeify: $4.99. `contact@storeify.app` bounced; the request reached
  `storeifyapps@gmail.com` and the vendor said only the latest charge is
  eligible.
- Wholesale Helper: $31.98 before tax, under internal review.
- InstaBuy: $7.99 before tax; maximum/latest and historical goodwill requested.
- Rewind: $71 before tax; uninstalled/cancelled and escalation follow-up sent to
  `help@rewind.com` for the latest charge and historical goodwill.
- Replaceit: refund amount still unidentified.

Matrixify denied any additional refund. PluginHive has no current refund because
the app remains an active production dependency. Recharge has no refund request
and must remain that way.

## Working-tree boundaries

These changes existed independently of the replacement work and must be
preserved exactly unless the next task explicitly owns them:

- `app/Http/Controllers/Mobile/EverbranchMobileFieldServiceController.php`
- `app/Services/FieldService/WorkspaceAssetService.php`
- `routes/api.php`
- unrelated contents under `output/`

The readiness implementation is currently uncommitted. Review `git status` and
stage explicit files rather than using `git add -A`. Never stage the customer
exports under `output/shopify-app-audit-2026-09-21`.

## Definition of ready for activation

A non-Recharge module may be activated only when its latest required evidence
versions all pass, source and target counts reconcile, settings are signed off,
the live theme/config fingerprint matches the candidate, a real adapter exists,
customer and Backstage browser checks pass, performance and accessibility pass,
rollback is tested, and the crawl produces zero new 404s or unexpected console
errors. Activation must automatically roll back on a failed smoke check.

Recharge is never eligible for this common activation path.
