# Modern Forestry Backstage

## Authenticated Onboarding Wizard + Workflow UI System (2026-04-12)

This release adds the first real authenticated onboarding wizard surface and standardizes “workflow” UI polish so onboarding/setup/builder-style screens feel like one coherent product.

Implemented:
- authenticated onboarding wizard UI:
  - `/onboarding` (tenant-aware, shared across Shopify/direct rails)
  - consumes the existing onboarding seams (no second onboarding backend):
    - `GET /api/onboarding/wizard-contract`
    - `POST /api/onboarding/blueprint-draft`
    - `POST /api/onboarding/blueprint-finalize`
    - `GET /api/onboarding/blueprint-post-provisioning-summary` (read-only; gated by provisioning flag/role)
  - renders backend-driven stepper + real step panels (`template_and_outcome`, `modules_and_data`, `mobile_intent`, `review_and_start`)
  - keeps unknown-step fallback safe (contract can evolve without UI crashes)
- provisioning/orchestration UI integration:
  - post-finalize “What happens next” card consumes the post-provisioning summary seam as the single read source
  - provisioning actions remain explicitly internal/gated; the wizard does not introduce redirects or session mutation endpoints
- workflow UI systemization (minimal, reusable):
  - shared workflow primitives live in `resources/css/forestry-ui.css` (panels, stepper, state banners, module cards, fast reduced-motion-safe transitions)
  - the same visual DNA is applied to the Module Store (`/marketing/modules`) so onboarding → in-app feels like one product

Feature flags:
- `features.internal_onboarding_provisioning` gates provisioning read seams and internal provisioning actions.
- `features.internal_onboarding_harness` (and `app.debug`) gates the internal harness page at `/internal/onboarding/harness`.

## Customer Access Requests + Activation (2026-04-12)

This release connects the public promo surfaces, landlord/admin approval, and tenant-aware post-login journey into one cohesive (still minimal) customer onboarding path.

Implemented:
- public entry points:
  - `/platform/plans` (compare plans + add-ons; informational, config-driven)
  - `/platform/demo` (request demo access)
  - `/platform/start` (request production client access)
- access request persistence:
  - `customer_access_requests` table + `App\\Models\\CustomerAccessRequest`
  - `POST /platform/access-request` creates a pending request and an inactive user when needed
- approval + activation:
  - admin approvals stay canonical in `/admin/users`
  - approval sends `ApprovalPasswordSetupNotification` with a password-setup URL targeting the intended tenant host (`<slug>.<base-domain>`)
- first-run landing:
  - `/start` is a non-embedded, authenticated Start Here surface (tenant-aware, entitlement-driven) built via `TenantCommercialExperienceService`

Billing note:
- plan/add-on truth remains landlord-controlled; customer-facing billing writes are intentionally deferred in this pass.

## Shopify Embedded AI Assistant Foundation (2026-04-10)

This release adds a tenant-aware AI Assistant foundation in Shopify embedded surfaces with centralized access gating and alpha override safety.

Implemented and shipped:
- new embedded AI Assistant routes:
  - `/shopify/app/assistant` (`Start Here`)
  - `/shopify/app/assistant/opportunities` (`Top Opportunities`)
  - `/shopify/app/assistant/drafts` (`Draft Campaigns`)
  - `/shopify/app/assistant/setup` (`Setup`)
  - `/shopify/app/assistant/activity` (`Activity`)
- embedded shell registration:
  - top-level nav label: `AI Assistant`
  - assistant subnav labels: `Start Here`, `Top Opportunities`, `Draft Campaigns`, `Setup`, `Activity`
- stage foundation behavior:
  - `Start Here` is now a tenant-facing landing page with:
    - a short welcome block
    - a 4-state status strip (`Ready`, `Needs Setup`, `Locked`, `Coming Soon`)
    - up to 3 recommended next-click actions
    - a small `What This Helps With` section
  - `Top Opportunities` is now a recommendation-backed work surface with:
    - one plain-English opportunities list (top 5 per page)
    - explainable `why this matters` lines from existing recommendation records
    - plain-English priority labels (`High priority`, `Medium priority`, `Lower priority`, `Needs review`)
    - one clear next action per card (no send actions)
    - clean empty and locked states with clear CTA routing
  - `Setup` is now tenant-facing with a fast checklist (up to 6 plain-English readiness items, per-item state, and one obvious next action)
  - `Draft Campaigns` is now tenant-facing as a focused human-review surface with:
    - a small list of recent/pending drafts
    - one simple `Review Draft` editor area (`Campaign Name`, `Audience`, `Message`, `Next Step`)
    - recommendation-to-draft creation actions from existing opportunity records
    - explicit no-autonomous-send behavior (draft/update only)
  - `Activity` is now tenant-facing as a lightweight history surface with:
    - recent opportunities surfaced
    - drafts created
    - approvals/rejections
    - key draft status changes
    - recent-item focus with pagination for older activity
  - no autonomous send workflow, no background send automation, and no LLM execution path are implemented
- tenant-facing module state labels standardized to:
  - `Ready`
  - `Needs Setup`
  - `Locked`
  - `Coming Soon`
- centralized alpha unlock behavior:
  - `ModernForestryAlphaBootstrapService` now explicitly configures the `ai` module state in addition to entitlement defaults
  - Modern Forestry retains full AI surface access regardless of plan/add-on restrictions
- human-review guardrail remains explicit:
  - no autonomous send behavior is added.
- stage 6 hardening (multi-tenant production readiness):
  - tier matrix is now enforced at capability level:
    - `Starter`: locked preview positioning
    - `Growth`: `Start Here`, `Top Opportunities`, `Setup`
    - `Pro`: `Start Here`, `Top Opportunities`, `Setup`, `Draft Campaigns`, `Activity`
  - Modern Forestry protected alpha override remains centralized and config-driven (`module_catalog.alpha_overrides.ai_assistant`) and unlocks all AI surfaces
  - landlord/commercial entitlement overrides still work and flow through the canonical resolver (no view-level bypasses)
  - AI routes now fail closed with tenant-aware 403 behavior when a surface is locked
  - embedded navigation/search visibility now respects required AI capabilities so locked/coming-soon child pages do not leak
  - embedded capability payloads are cached as safe tenant-scoped summaries to reduce repeated resolver work

## Agentic Discovery + Brand Graph Backend (2026-04-10)

This release is shipped and live on production (`main` commit `cdfce8d`).

Implemented and deployed:
- tenant-scoped discovery source-of-truth persistence:
  - `tenant_discovery_profiles`
  - `tenant_discovery_pages`
- backend discovery services for:
  - tenant discovery profile resolution/default seeding
  - canonical domain/page intent resolution
  - structured data contracts (Organization, WebSite, ContactPoint, policy/shipping/FAQ-safe entities)
  - normalized brand graph read model
  - discovery sitemap export
  - domain/crawler drift audit
- public machine-readable endpoints:
  - `/.well-known/brand-discovery.json`
  - `/api/public/discovery/brand/{tenant}`
  - `/api/public/discovery/structured/{tenant?}`
  - `/sitemaps/discovery.xml`
- audit command:
  - `php artisan modern-forestry:audit:domains`
- Modern Forestry bootstrap integration:
  - discovery defaults now seed through existing alpha bootstrap flow (idempotent/non-destructive)

Safety/accuracy guardrails:
- no fabricated merchant facts
- no `LocalBusiness` emission without complete real address data
- no `FAQPage` emission without real FAQ content
- no false international/geo guarantees when policy/config is unset

Operational note:
- the stale custom-domain mismatch on `theforestrystudio.com` is still operationally external; this release adds diagnostics for drift detection but does not assume backend-only remediation.

Production deploy evidence:
- GitHub Actions `Deploy Production` run `24220680927` succeeded for `cdfce8d` on 2026-04-10.

## Embedded Admin Dashboard Lite + Rewards Stall Notes (2026-04-08)

Observed in production after the initial embedded perf deploy:
- `/shopify/app` (inside Shopify Admin) could show the shell quickly but default to `7d` and never populate `Today` until a tab switch.
- `/shopify/app/rewards` could stall/hang on first load.

Root causes:
- Dashboard Lite shipped with `7d` selected and then stamped that default into `localStorage` on first paint (making `7d` “sticky” even when the merchant never chose it).
- Dashboard Lite fired its first API request before App Bridge session token (`window.shopify.idToken`) was reliably available; errors were swallowed and there was no retry.
- Rewards computed a server-side “overview” payload on first paint that the `rewards-overview` Blade view does not consume, and it duplicated label/module access resolution instead of reusing the cached embedded shell payload.

Fixes shipped:
- Dashboard Lite now defaults to `Today` on a clean load and only persists the range after an explicit click (`fb.dashboard_lite.range.explicit` prevents accidental persistence).
- Dashboard Lite retries auth + fetch when the embedded session token is not ready yet and shows a visible error/toast instead of silently failing.
- Rewards no longer computes the unused “overview” payload on initial render and reuses the cached embedded shell display labels + module states.
- Dashboard Lite “Today/7d/30d” windows now use the store reporting timezone (prevents “Today” rolling over at 8pm ET due to UTC day boundaries).

Timezone config (embedded reporting windows):
- `SHOPIFY_REPORTING_TIMEZONE` (global fallback, default `America/New_York`)
- `SHOPIFY_RETAIL_TIMEZONE` / `SHOPIFY_WHOLESALE_TIMEZONE` (store-specific overrides)

Debugging:
- Add `?perf=1` to embedded routes for `Server-Timing` + `shopify.embedded.perf` log entries.
- Add `?dashboard_debug=1` to `/shopify/app` to enable Dashboard Lite console logs (`[dashboard-lite] …`).
- Do not add debug query params to the original Shopify-signed entry URL (adding params breaks the HMAC); open the app first, then append debug params on subsequent in-app navigations.
- See `PERF_NOTES.md` for file-level details.

## Embedded Admin Redis Runtime Cutover (2026-04-08)

Use this sequence when moving embedded-admin session/cache/queue storage from database to Redis in production.
Do not cut over until preflight checks pass.

1) Preflight checks:

```bash
redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" ping
php artisan optimize:clear
php artisan tinker --execute="cache()->store('redis')->put('redis-preflight', 'ok', 60); dump(cache()->store('redis')->get('redis-preflight'));"
php artisan queue:failed
```

2) Production env values:

```dotenv
SESSION_DRIVER=redis
SESSION_CONNECTION=default
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_QUEUE_CONNECTION=default
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_QUEUE_DB=2
```

3) Cutover and warm caches:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

4) Verify after deploy:
- Embedded Shopify admin login persists across refreshes.
- Cache reads/writes succeed (`cache()->store('redis')->get('redis-preflight')`).
- Queue backlog does not grow unexpectedly (`php artisan queue:failed`, worker metrics/process manager).

5) Rollback steps:

```dotenv
SESSION_DRIVER=database
SESSION_CONNECTION=null
CACHE_STORE=database
QUEUE_CONNECTION=database
REDIS_QUEUE_CONNECTION=default
```

```bash
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
```

## Candle Cash Reconciliation Runbook (2026-04-07)

Use this live-safe sequence whenever Candle Cash totals look inconsistent between dashboards, customer views, and ledger reports:

1) Audit current scoped composition + reconciliation status:

```bash
php artisan marketing:audit-candle-cash-composition --tenant-id=1
```

2) Preview balance-table drift from ledger net (no writes):

```bash
php artisan marketing:reconcile-candle-cash-balances --tenant-id=1
```

3) Apply deterministic repair (upsert `candle_cash_balances` from ledger net):

```bash
php artisan marketing:reconcile-candle-cash-balances --tenant-id=1 --apply
```

4) Re-audit to confirm reconciliation:

```bash
php artisan marketing:audit-candle-cash-composition --tenant-id=1
```

5) Validate legacy Growave conversion signals remain clean:

```bash
php artisan marketing:validate-candle-cash-legacy-conversion --json --limit=10
```

Notes:
- `marketing:reconcile-candle-cash-balances` is preview-first by default and returns a non-zero exit code when drift exists.
- Use `--profile-id={id}` for targeted repairs and `--chunk={n}` to tune scan chunking for large tenants.

## Legacy Growave Candle Cash Rehome Runbook (2026-04-07)

Use this when migrated legacy Candle Cash appears missing (for example, known legacy customers show `0` balance) and duplicate null-tenant profiles are suspected.

1) Preview deterministic retail rehome candidates:

```bash
php artisan marketing:rehome-legacy-growave-candle-cash --tenant-id=1 --store=retail
```

2) Require safe preview shape before apply:
- `eligible_pairs > 0`
- `ambiguous_old_profiles = 0`
- `ambiguous_target_profiles = 0`
- `excluded_wholesale_profiles` is non-zero only for quarantine candidates (default behavior)

3) Apply retail-only move:

```bash
php artisan marketing:rehome-legacy-growave-candle-cash --tenant-id=1 --store=retail --apply
```

4) Post-run verification sequence:

```bash
php artisan marketing:audit-candle-cash-composition --tenant-id=1
php artisan marketing:reconcile-candle-cash-balances --tenant-id=1
php artisan marketing:reconcile-candle-cash-balances --tenant-id=1 --apply
php artisan marketing:audit-candle-cash-composition --tenant-id=1
php artisan marketing:validate-candle-cash-legacy-conversion --json --limit=10
```

Operational safety:
- `marketing:rehome-legacy-growave-candle-cash` is preview-first by default and writes only with `--apply`.
- Mapping is fail-closed: ambiguous old↔target relationships are excluded and reported.
- Wholesale-touched profiles are excluded by default; include them only with explicit `--include-wholesale` after manual review.

## If Points Import Disappears Again (SOP)

1) Diagnose duplicate-profile drift:
- Run rehome preview and confirm candidate/ambiguous/wholesale counters.

2) Execute deterministic rehome:
- Run rehome apply for retail-only scope.

3) Reconcile and audit balances:
- Run the reconciliation sequence above until `reconciled=yes`.

4) Verify top non-wholesale customers still look correct (orders and rewards):

```bash
php artisan tinker --execute="
\$rows = DB::table('customer_external_profiles as cep')
  ->join('marketing_profiles as mp', 'mp.id', '=', 'cep.marketing_profile_id')
  ->where('cep.provider', 'shopify')
  ->where('cep.integration', 'shopify_customer')
  ->where('cep.store_key', 'retail')
  ->whereNotNull('cep.marketing_profile_id')
  ->whereNotExists(function (\$q) {
    \$q->select(DB::raw(1))
      ->from('marketing_profile_links as mpl')
      ->whereColumn('mpl.marketing_profile_id', 'cep.marketing_profile_id')
      ->where('mpl.source_type', 'shopify_customer')
      ->where('mpl.source_id', 'like', 'wholesale:%');
  })
  ->whereNotExists(function (\$q) {
    \$q->select(DB::raw(1))
      ->from('customer_external_profiles as ws')
      ->whereColumn('ws.marketing_profile_id', 'cep.marketing_profile_id')
      ->where('ws.provider', 'shopify')
      ->where('ws.store_key', 'wholesale');
  })
  ->groupBy('cep.marketing_profile_id', 'mp.email', 'mp.first_name', 'mp.last_name')
  ->orderByDesc(DB::raw('max(coalesce(cep.order_count, 0))'))
  ->orderBy('cep.marketing_profile_id')
  ->limit(10)
  ->get([
    'cep.marketing_profile_id',
    'mp.first_name',
    'mp.last_name',
    'mp.email',
    DB::raw('max(coalesce(cep.order_count, 0)) as orders_count'),
  ]);
foreach (\$rows as \$row) { echo json_encode(\$row).PHP_EOL; }
"
```

```bash
php artisan tinker --execute="
\$rows = DB::table('candle_cash_balances as b')
  ->join('marketing_profiles as mp', 'mp.id', '=', 'b.marketing_profile_id')
  ->where('mp.tenant_id', 1)
  ->whereNotExists(function (\$q) {
    \$q->select(DB::raw(1))
      ->from('marketing_profile_links as mpl')
      ->whereColumn('mpl.marketing_profile_id', 'mp.id')
      ->where('mpl.source_type', 'shopify_customer')
      ->where('mpl.source_id', 'like', 'wholesale:%');
  })
  ->whereNotExists(function (\$q) {
    \$q->select(DB::raw(1))
      ->from('customer_external_profiles as ws')
      ->whereColumn('ws.marketing_profile_id', 'mp.id')
      ->where('ws.provider', 'shopify')
      ->where('ws.store_key', 'wholesale');
  })
  ->orderByDesc('b.balance')
  ->orderBy('b.marketing_profile_id')
  ->limit(10)
  ->get([
    'b.marketing_profile_id',
    'mp.first_name',
    'mp.last_name',
    'mp.email',
    'b.balance as candle_cash_balance',
  ]);
foreach (\$rows as \$row) { echo json_encode(\$row).PHP_EOL; }
"
```

5) Keep wholesale quarantine separate:
- Do not run `--include-wholesale` in the broad pass.
- Export and manually classify wholesale-touched candidates before any dedicated wholesale migration pass.

## Responses Inbox (2026-04-06)

Backstage now includes a unified `Responses` inbox in the embedded Shopify Messaging area:
- route: `/shopify/app/messaging/responses`
- channels: `Text` and `Email`
- purpose: operator workflow for inbound replies, opt-out handling, and thread-based replies back out from Backstage

Key implementation notes:
- SMS inbound replies persist through `POST /webhooks/twilio/inbound`
- SendGrid event tracking remains on `POST /webhooks/sendgrid/events`
- provider-agnostic inbound email seam is now exposed via `POST /webhooks/sendgrid/inbound?token=...`
- STOP/unsubscribe state is stored in Backstage and blocks inbox SMS replies
- email reply threading now uses generated reply aliases when an inbound reply domain is configured

Setup and testing details live in:
- `docs/architecture/messaging-responses-inbox.md`

## Shopify Storefront Tracking Bootstrap (2026-04-06)

This repo now contains the Shopify CLI app container files needed for storefront tracking:
- `shopify.app.toml`
- `extensions/forestry-marketing-embed/` theme app extension
- `extensions/forestry-marketing-pixel/` web pixel extension

Operational flow:
- deploy the Shopify extensions from this repo
- enable `Forestry storefront tracking` in Shopify Theme Editor under `App embeds`
- verify `/apps/forestry/health` on the storefront
- open Message Analytics detail and confirm tagged visits create funnel events

Useful commands:
- `npm run shopify:app:info`
- `npm run shopify:app:dev -- --store modernforestry.myshopify.com`
- `npm run shopify:app:deploy`

Storefront tracking hardening pass (2026-04-09):
- Messaging Setup now exposes a single-source tracking health snapshot with:
  - theme embed inferred enabled state
  - web pixel connected/disconnected state
  - granted-vs-requested Shopify scope verification state
  - recent storefront event count
  - last event type/timestamp
  - recent checkout completion seen/not-seen state
- Messaging Setup now includes an explicit tracking inventory list so each tracking source is visible with status + known gaps.
- Message Analytics now includes a `Storefront tracking health` card plus raw diagnostics output for debugging.
- Message-level storefront funnel summary now visibly includes `checkout_completed` (in addition to directional checkout abandonment candidates).
- Scope diagnostics now parse requested scopes from `shopify.app.toml` and compare against granted scopes returned by Shopify app installation access scopes (with stored fallback when live lookup is unavailable).
- Tracker-level event diagnostics now separate recent signal flow by:
  - `theme_app_embed`
  - `web_pixel`
  - `unknown`
- Shopify-native analytics/reporting status is now surfaced explicitly as scope availability only.
  - Backstage still does **not** query Shopify native analytics/report APIs for storefront funnel reporting.

## MT-4C Hardening Status (2026-03-30)

MT-4C is now complete.

Completed in MT-4C Pass 1 + Pass 2 + Pass 3 + Pass 4:
- Growave wishlist and opening-balance backfill flows now require deterministic tenant ownership proof and fail closed on missing/ambiguous/conflicting ownership.
- Legacy campaign/report/helper surfaces now enforce tenant ownership rails across campaign, segment, template, recommendation, and direct-messaging chains.
- Campaign send/retry execution now validates tenant-owned recipient scope before mutation; foreign/unproven recipients are blocked.
- Campaign performance analytics/report computation now supports tenant-scoped query predicates so foreign-tenant rows are excluded at the data layer.
- First-class schema-backed tenant ownership rails now exist for legacy authoring/storage entities:
  - `marketing_campaigns.tenant_id`
  - `marketing_segments.tenant_id`
  - `marketing_message_templates.tenant_id`
  - `marketing_event_source_mappings.tenant_id`
  - `marketing_order_event_attributions.tenant_id`
- Strict-mode authoring flows previously blocked for safety are now re-enabled where storage rails are deterministic:
  - segment create/store/duplicate
  - template create/store
  - tenant-owned event mapping create/edit/update/list
- Event source mappings and attribution projection rows are now tenant-partitioned at storage + query boundaries; foreign/unresolved mapping rows remain fail-closed.
- Legacy ownership backfill now assigns tenant ownership only for provable rows using deterministic rails (campaign/profile/group/conversion, variant/template, and tenant-owned Square evidence for mapping/attribution).
- Pass 4 added explicit unresolved-tail remediation + quarantine visibility with `marketing:remediate-authoring-ownership`:
  - dry-run inventory classification across campaigns/segments/templates/mappings/attributions,
  - deterministic `--apply` ownership assignment for provable rows only,
  - ambiguous/unprovable/unsupported rows left intentionally fail-closed.
- Pass 4 also hardened customer analytics/detail attribution reads to enforce tenant-scoped Square order/attribution matching when duplicate `square_order_id` values exist across tenants.
- Tenant-sensitive admin helper commands now require tenant scope in strict mode and block foreign ownership targets:
  - `marketing:send-approved-sms`
  - `marketing:send-approved-email`
  - `marketing:generate-recommendations`
- Targeted MT-4C regression coverage now includes campaign/report isolation, schema-backed authoring ownership rails, unresolved-row remediation and quarantine behavior, tenant-owned mapping behavior, strict command ownership guards, and shared-square-order attribution isolation.

MT-4C closure criteria used in Pass 4:
- campaigns, segments, templates, mappings, and attributions are tenant-owned when proof is deterministic;
- unresolved historical rows without safe proof remain explicitly quarantined (`tenant_id` null) and fail closed on tenant-sensitive surfaces;
- unresolved inventory is now observable/remediable through an explicit operator command rather than hidden compatibility behavior.

## Post-MT-4C Operator Phase (2026-03-30)

New landlord/operator capabilities are now available behind landlord host + landlord operator auth guards:
- Explicit tenant selector flow for landlord tenant operations entry.
- Guarded tenant snapshot export workflow (tenant-scoped only) for customer/marketing recovery inspection.
- Guarded tenant snapshot restore/import workflow (bounded MVP) with strict target-tenant confirmation.
- Guarded customer modify workflow (bounded editable fields only) scoped to one selected tenant.
- Guarded customer delete workflow implemented as safe archive/redaction (not hard delete), with explicit operator confirmation.
- Immutable landlord operator action trace for export/restore/customer modify/customer archive actions, including actor, tenant, action type, status, and result context.
- Tenant-scoped snapshot download flow with blocked/success audit records.

Additional operator hardening in the follow-up pass:
- Restore now supports explicit dry-run mode (`dry_run`) that returns projected table impact without mutating rows.
- Restore now enforces stronger artifact gates before apply:
  - supported schema version
  - source tenant id + source tenant slug match
  - scope manifest table list must match data payload tables exactly
  - max snapshot artifact size guard
- Restore apply and overwrite now require typed confirmation phrases:
  - apply phrase: `apply <tenant-slug>` when dry-run is off
  - overwrite phrase: `overwrite <tenant-slug>` when overwrite mode is enabled
- Export/restore actions now require explicit operator reason strings and persist them in audit confirmation metadata.
- Snapshot export now records artifact metadata (`artifact_bytes`, `generated_at`, `expires_at`, retention days); download is blocked once artifact retention expires.
- Download now enforces tenant path prefix checks (`landlord/tenant-ops/tenant-<id>/...`) in addition to tenant/action ownership checks.
- Customer modify/archive now require typed target confirmation (`confirm_profile_id` must match selected `profile_id`).
- Tenant action trace UI now includes status summaries and richer per-action details (mode, reason, expiry, blocked error context).

Operator safety constraints in this phase:
- Every operation requires explicit tenant id + tenant slug + confirmation phrase (`confirm <tenant-slug>`).
- Cross-tenant restore/import is blocked fail-closed.
- Cross-tenant customer modify/delete is blocked fail-closed.
- Snapshot download artifacts are tenant-locked by route + audit action ownership checks.
- Landlord operator action records are append-only; update/delete mutation is blocked at the model layer.

Consistency conventions standardized in this phase:
- Landlord tenant context now uses one canonical confirmation contract across UI + controller + service boundaries.
- Destructive/overwrite operator workflows now require explicit confirmation toggles plus confirmation phrase.
- Operator action statuses now follow one pattern (`success`, `blocked`, `failed`) with shared context/result payload structure for traceability.
- Recovery workflows now share one explicit mode contract (`dry-run` vs `apply`) with consistent confirmation and audit semantics.

Bounded scope (truthful MVP):
- Snapshot restore/import supports the landlord-generated tenant snapshot artifact format from this phase.
- Restore/import remains intentionally bounded to tenant-owned marketing/customer datasets included in the snapshot scope.
- Snapshot artifact retention is bounded by operator config and enforced on download paths; this is not yet a full artifact lifecycle management suite.
- Broad cross-environment migration tooling and unrestricted operator “god mode” edits are intentionally deferred.

## Merchant Experience Consolidation Phase (2026-03-30)

This phase shifts primary execution from backend hardening to merchant-facing product clarity and onboarding momentum across the embedded Shopify shell.

What now ships for merchant experience:
- Post-login and post-install landing (`/shopify/app`) now uses one clear hierarchy:
  - what the product does
  - next best setup/import action
  - current customer/setup snapshot
  - what unlocks after import
  - what is active now vs setup next vs purchasable unlocks
- Start Here, Plans, and Integrations pages now reuse one journey model so setup guidance and CTA logic stay consistent.
- Customer workflows now include shared setup/import status framing so merchants understand readiness before acting in manage/activity/questions views.
- Import-first orientation is now explicit:
  - import state is visible (`Not started`, `In progress`, `Needs attention`, `Imported`)
  - import CTA is preserved as the first meaningful action when setup is incomplete
  - post-import path is explained in plain merchant language.
- Feature discovery and monetization visibility is now structured and non-spammy:
  - `Available Now` (active)
  - `Setup Next` (included but not configured)
  - `Unlock Next` (upgrade/add-on eligible)

Feature metadata for this major UI phase:
1. Classification: Shared core
2. Tenant scope: Mixed (tenant-scoped merchant surfaces using existing host/context rails)
3. Entitlement/access level: Plan/add-on state and module access remain canonical via existing tenant entitlement resolvers
4. Canonical dependencies reused:
   - `App\Services\Tenancy\TenantCommercialExperienceService`
   - `ShopifyEmbeddedAppController`
   - `ShopifyEmbeddedCustomersController`
   - embedded shell/navigation components and existing module checklist state
5. Shopify-specific hooks preserved: embedded query context, App Bridge bootstrap, existing embedded routes/tabs, host/context fail-closed behavior
6. Setup/onboarding implications: no new identity/import architecture; customer onboarding remains tied to canonical import runs and module setup states
7. Shopify behavior preservation requirement: MT-4C tenant protections and fail-closed access logic remain unchanged
8. Non-Shopify applicability target: Later (pattern is reusable, this pass is implemented on merchant embedded surfaces now)

Intentionally deferred after this phase:
- Broad backend/operator refactors not required for merchant UX clarity
- App Store packaging work
- Additional module implementation depth beyond current entitlement/discovery visibility
- Advanced visual regression tooling (behavioral/rendering tests remain the primary guardrail today)

## Current Release State (2026-03-27)

This branch now includes the first commercialization/operator shell on top of tenant entitlements.

Implemented and navigable now:
- Embedded product shell:
  - `/shopify/app` (overview/dashboard)
  - `/shopify/app/start` (Start Here)
  - `/shopify/app/plans` (Plans & Add-ons informational)
  - `/shopify/app/integrations` (integrations placeholder surface)
- Public product surfaces:
  - `/platform/promo`
  - `/platform/contact`
- Landlord commercial control surface:
  - `/landlord/commercial` (host-locked, pricing-first admin controls for plans/add-ons/templates/tenant overrides)
- Operator diagnostics surfaces:
  - customer email timeline provider-context filters + CSV export parity
  - birthday analytics/reporting/export/comparison flows
  - campaign delivery diagnostics/provider-context sections

Integrations surface behavior (intentional in this release):
- placeholder-first, entitlement-aware cards
- setup detail drawer per integration
- deterministic read-only status registry context per card
- fallback-first guidance (manual/CSV/continue without connector)
- no live connector sync/OAuth/jobs/webhooks/API writes from this page

Commercialization/access state:
- product shell and entitlement-aware UI are in place
- public tier model is now normalized to `Starter`, `Growth`, `Pro` (legacy keys are mapped for compatibility)
- add-on model is now normalized to `referrals`, `sms`, `additional_channels`, `bulk_email_marketing`, `future_niche_modules`
- template library foundation is now normalized to `Candle`, `Law`, `Landscaping`, `Apparel`, `Generic`
- three guarded landlord-only Stripe actions are implemented:
  - customer reference create/sync
  - subscription-prep metadata sync
  - guarded live subscription reference create/sync (explicit trigger only; disabled-by-default config flag)
- guarded Stripe preflight now requires HTTPS for remote `services.stripe.api_base` endpoints (HTTP is loopback-only for local testing on `localhost`/`127.0.0.1`/`::1`)
- staging validation support is now explicit for these guarded Stripe actions:
  - run + evidence sequence is documented in `docs/operations/staging-commercial-uat-runbook.md`
  - operator evidence template is documented in `docs/operations/staging-commercial-uat-evidence-template.md`
- latest repo-side validation status (2026-03-29):
  - real staging landlord operator evidence is attached for a guarded run on tenant `modern-forestry`
  - blocked-run record: `docs/operations/staging-commercial-uat-blocked-run-2026-03-28.md`
  - staging Stripe sandbox + operator follow-up: runtime Stripe auth succeeds and all required recurring lookup-key prices are present/verified (`tier_starter_monthly`, `tier_growth_monthly`, `tier_pro_monthly`, `addon_referrals_monthly`, `addon_sms_monthly`, `addon_additional_channels_monthly`, `addon_bulk_email_marketing_monthly`, `addon_future_niche_modules_monthly`), and the landlord operator account `modernforestryteam@gmail.com` is route-ready
  - tenant-row unblock follow-up (2026-03-29): existing `TenantSeeder` was executed on staging; `/landlord/commercial` now renders one selectable tenant row (`Modern Forestry`, slug `modern-forestry`)
  - guarded run evidence artifacts (2026-03-29): `docs/operations/evidence/2026-03-29/guarded-stripe-run-2026-03-29T16-23-07.524Z/`
  - guarded step outcomes from the real run:
    - step 1 customer sync: `PASS` (`cus_UEpZQoP8cJadrs`)
    - step 2 subscription-prep sync: `PASS` (`eaaddd980cf88b07e7f52f3ce7db5856a7394ff9eb08c602ee87afeb4b6ad563`)
    - step 3 live subscription create/sync: `FAIL` (`Missing email. In order to create invoices that are sent to the customer, the customer must have a valid email.`)
  - full guarded 3-step PASS evidence is still not attached because step 3 failed in real staging execution
  - follow-up commit `9c2502c` (CI assertion alignment after dotenv bootstrap fix) is pushed to `main`
  - local CI-equivalent rerun for this pass:
    - command: `php -d memory_limit=512M ./vendor/bin/pest`
    - result: `845 passed`, `0 failed`
  - GitHub Actions results for commit `9c2502c`:
    - `linter`: `success`
    - `tests`: `success` (`ci (8.4)` and `ci (8.5)` passed)
    - `Deploy Production`: initial `failure` on push, then `success` on rerun `23687500356` after deploy-ops unblock
  - deploy-ops unblock completed in GitHub `production` environment:
    - configured `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PORT`, `DEPLOY_PATH`, `DEPLOY_SSH_KEY`
    - corrected server checkout branch at `DEPLOY_PATH` to `main` so workflow `git checkout main` succeeds
  - latest production rollout for commit `dbf0762` had been completed manually before deploy automation was restored:
    - deploy command: `ssh forge@129.212.138.111 'bash /home/forge/deploy_backstage.sh'`
    - health check: `curl -sS https://backstage.theforestrystudio.com/up` returned `Application up.`
  - manual SSH deploy remains available as fallback, but is no longer the primary required path while deploy secrets stay configured
- checkout and broad subscription lifecycle mutation writes are still intentionally disabled
- upgrade prompts are informational routing only
- multi-tenant completion estimate (historical snapshot at 2026-03-27): `45%`

Multi-tenant state:
- tenant-aware semantics are now established in email/birthday/provider diagnostics and shell module-state presentation
- full domain tenant isolation is still in progress and should not be overclaimed
- internal ops/inventory/pouring boundaries remain intentionally cautious and partially candle-shaped

Recommended next step after this push:
- deploy and run manual production verification of shell navigation, diagnostics filters/export parity, and integrations placeholder/drawer behavior before adding new scope
- use the staging operator runbook for landlord commercial assignment propagation checks:
  - `docs/operations/staging-commercial-uat-runbook.md`

## Strict Near-Term Execution Order (As of 2026-03-27)

1. Verify Candle Cash is trustworthy end to end for Modern Forestry (storefront truth, admin/backstage truth, reconciliation parity).
2. Fix email reliability for launch-critical reward/customer workflows.
3. Only then start broader platform expansion.

Do not start yet:
- broad multi-tenant refactors
- Shopify App Store packaging
- speculative AI automation work

Current backend release-order note:
- treat `docs/architecture/backend-release-order-2026-04-01.md` as the active split plan for the waiting backend branch
- do not promote mixed commercialization/unified-shell work to `main` until Release A stabilization is green and the later releases are separated
- prepared split branches now exist:
  - `release-a-stabilization`
  - `release-b-commercial-core`
  - `release-c-module-discovery`
  - `release-d-unified-shell`
  - `release-e-polish-docs-assets`
- Releases A through E are now merged on `main` as of `2026-04-01`.
- The active standalone follow-up track is email/provider reliability for launch-critical reward and customer workflows.

## Product Architecture References (2026-03-27 Pass)

- Business concept and commercial model:
  - `docs/architecture/business-concept-and-product-architecture.md`
- Multi-tenant implementation inventory:
  - `docs/architecture/multi-tenant-inventory-2026-03-27.md`
- Entitlements/commercial shell foundation:
  - `docs/architecture/tenant-entitlements-foundation.md`

## Production Host Rules (Current Direction)

- Public marketing site: `forestrybackstage.com`
- Landlord/operator host: `app.forestrybackstage.com`
- Tenant host pattern: `<slug>.forestrybackstage.com`
- Landlord routes are host-locked; tenant directory remains read-only while commercial config writes are limited to safe scope (`/landlord`, `/landlord/commercial`, `/landlord/tenants`, `/landlord/tenants/{tenant}`).
- Unknown hosts must not silently fall back to the first tenant.

## Production DNS + Wildcard TLS Verification (2026-03-27)

Verified in production for Forestry Backstage:
- Wildcard certificate issuance for `*.forestrybackstage.com` is working.
- Tenant wildcard DNS now resolves through Cloudflare wildcard records.
- Tenant HTTPS now negotiates the wildcard cert and routes into Laravel.

Working production host model:
- public site: `forestrybackstage.com`
- landlord app: `app.forestrybackstage.com`
- tenant apps: `<slug>.forestrybackstage.com`

Operator notes (important):
- Forge DNS-01 challenge must use:
  - type: `CNAME`
  - name: `_acme-challenge`
  - target: Forge-provided `verify-<token>.ssl.on-forge.com`
  - proxy status: `DNS only` (gray cloud)
- Tenant wildcard record may stay proxied:
  - `A * -> 129.212.138.111` (Cloudflare proxied is acceptable)
- If Forge verification target returns `NXDOMAIN`, regenerate a fresh challenge in Forge and update only the CNAME target.

## Historical Auth Findings (2026-03-25, Legacy Host Check)

Observed during a historical live verification on legacy host `https://backstage.theforestrystudio.com/login` (kept for audit context; current production direction is `*.forestrybackstage.com`):
- Password resets run locally do not affect production.
- Production user `johncollinsemail@gmail.com` exists, is active/approved, and password reset was successfully applied on production (`PASSWORD_MATCH=1`).
- Google login failure is currently external-credential based, not route/UI based:
  - production log shows `POST https://www.googleapis.com/oauth2/v4/token` returning `401 invalid_client`
  - message: `The provided client secret is invalid.`

What was verified on production:
- `services.google.client_secret` loaded by Laravel matches the `.env` value fingerprint (same length/hash), so this is not a runtime config drift in the app process at verification time.
- Login Google credentials are distinct from `GOOGLE_GBP_*` credentials (no accidental key collision in current config).

Google login runbook:
1. Run local/production diagnostics (masked output only):
   - `php artisan auth:doctor-google`
   - `php artisan auth:doctor-google --token-smoke`
2. In Google Cloud Console, confirm the OAuth client ID + client secret pair are from the same OAuth credential entry.
3. Update production `.env` keys:
   - `GOOGLE_CLIENT_ID`
   - `GOOGLE_CLIENT_SECRET`
   - `GOOGLE_REDIRECT_URI`
4. Rebuild Laravel runtime config on production:
   - `php artisan config:clear`
   - `php artisan config:cache`
   - `php artisan queue:restart`
5. Retry in an incognito window and check `storage/logs/laravel.log`:
   - `invalid_client` => wrong/revoked/mismatched OAuth pair
   - `invalid_grant` => credentials accepted; test/code is intentionally invalid or expired
   - `redirect_uri_mismatch` => callback URL mismatch in Google Console

Interpretation of smoke test results:
- `invalid_client` = broken client ID/secret pair
- `invalid_grant` = credentials accepted by Google

Important:
- Do not mix login keys (`GOOGLE_CLIENT_*`) with Google Business Profile keys (`GOOGLE_GBP_*`); they are separate integrations.

## Auth Redirect Diagnostics (2026-03-26)

Ship-readiness manual validation and release checklist:
- `docs/operations/auth-tenant-ship-readiness.md`

Tenant-aware login landing decisions now emit:
- event: `auth.post_login.redirect_decision`
- category: `auth.redirect`

Redirect strategy meanings:
- `intended_url`: session `url.intended` was present and passed host/query safety checks.
- `tenant_intent`: no accepted intended URL; tenant intent existed and user membership check passed.
- `role_fallback`: no safe intended URL and no usable tenant intent; role default route used.
- `safe_fallback`: role fallback returned empty; hard fallback route used.

If tenant landing looks wrong, check in order:
1. `tenant_intent_exists`, `tenant_intent_tenant_id`, `tenant_membership_passed` in the redirect decision log.
2. `intended_present` and `intended_accepted` to confirm whether intended URL safety rejected a redirect.
3. `auth.tenant_context.resolved` log around entry/callback routes (`login`, `login.store`, `auth.google.callback`, `password.reset`, `password.update`) and compare `host`, `classification`, and `strategy`.
4. User tenant membership in `tenant_user` for the intended tenant.
5. Session reset lifecycle:
   - logout should clear tenant session context
   - failed login should not set `tenant_id`
   - successful login should consume `auth.tenant_intent`

Google OAuth callback failure severity:
- known OAuth classes (`invalid_client`, `invalid_grant`, `redirect_uri_mismatch`, `state_error`) are logged as structured warnings.
- only `unknown_oauth_failure` is additionally reported as an exception.

## Landlord Host Foundation (Phase 1, 2026-03-26)

What was added:
- New pre-auth host context resolution path for all web requests:
  - `app/Services/Tenancy/PreAuthTenantContextResolver.php`
  - `app/Support/Tenancy/HostTenantContext.php`
  - `app/Http/Middleware/ResolveHostTenantContext.php`
- Middleware is prepended to the `web` stack in `bootstrap/app.php` so host context is available before auth/login handling.
- Host behavior now supports:
  - landlord host (`app.forestrybackstage.com` in production, configurable) => landlord context (`isLandlordMode=true`), no tenant required
  - tenant host (`<slug>.forestrybackstage.com` in production) => resolves tenant by slug/subdomain
  - unknown host => unresolved context, no fallback to first tenant
- Existing auth tenant resolver (`GuestAuthTenantContextResolver`) now reuses this host resolver rather than duplicating host parsing logic.

Configuration added:
- `TENANCY_LANDLORD_HOSTS` env var (comma-separated, default `app.forestrybackstage.com`)
- `TENANCY_LANDLORD_OPERATOR_ROLES` env var (comma-separated, default `admin`)
- `TENANCY_LANDLORD_OPERATOR_EMAILS` env var (comma-separated, optional allowlist)
- `config/tenancy.php` now exposes:
  - `tenancy.landlord.hosts`
  - `tenancy.landlord.primary_host`
  - `tenancy.landlord.operator_roles`
  - `tenancy.landlord.operator_emails`

Local setup note:
- In this implementation, the first host in `TENANCY_LANDLORD_HOSTS` is treated as the landlord route domain (`tenancy.landlord.primary_host`) and is what `Route::domain(...)` uses for `/landlord*` routes.
- Host examples:
  - production example: `TENANCY_LANDLORD_HOSTS=app.forestrybackstage.com`
  - local example: `TENANCY_LANDLORD_HOSTS=forestrybackstage.test`
- Recommended local baseline:
  - `TENANCY_LANDLORD_HOSTS=forestrybackstage.test`
  - `TENANCY_LANDLORD_OPERATOR_ROLES=admin`
  - `TENANCY_LANDLORD_OPERATOR_EMAILS=`
- Fast local login bootstrap (if your expected admin login fails):
  - `php artisan users:ensure-approved your-email@example.com 'your-password' --name='Your Name' --role=admin`
  - then sign in at `/login` and open `/landlord`
- Leave `TENANCY_LANDLORD_OPERATOR_EMAILS` blank unless you explicitly want an additional email allowlist on top of role checks.

Landlord/operator routes added (landlord-host only):
- `GET /landlord` => landlord dashboard (`landlord.dashboard`)
- `GET /landlord/commercial` => landlord commercial configuration console (`landlord.commercial.index`)
- `GET /landlord/tenants` => read-only tenant directory (`landlord.tenants.index`)
- `GET /landlord/tenants/{tenant}` => read-only tenant detail (`landlord.tenants.show`)
- Guarded by `auth`, `verified`, and `landlord.operator`
- `landlord.operator` is an interim dedicated landlord check that defaults to `admin` role only (and can optionally enforce an email allowlist) until a first-class landlord role/flag exists.
- Implemented via:
  - `app/Http/Controllers/Landlord/LandlordTenantDirectoryController.php`
  - `resources/views/landlord/dashboard.blade.php`
  - `resources/views/landlord/tenants/index.blade.php`
  - `resources/views/landlord/tenants/show.blade.php`

Read-only directory fields now surfaced from existing schema/relations:
- tenant name
- slug/subdomain
- derived status label (from existing access/users/shopify/health signals)
- `created_at`
- user count
- connected Shopify stores count
- primary Shopify store/domain
- basic readiness/health indicators derived from current data

View data path prepared for host-aware UI:
- shared on every web request:
  - `hostTenantContext`
  - `hostTenant`
  - `isLandlordMode`
- wired into auth/app layouts as `data-landlord-mode` and `data-host-tenant` attributes for future branding/runtime branching.

Tests added:
- `tests/Feature/Tenancy/LandlordHostFoundationTest.php`
  - landlord host access for authorized landlord operators
  - tenant host resolution
  - unknown host no-fallback behavior
  - `/landlord`, `/landlord/tenants`, and `/landlord/tenants/{tenant}` blocked on tenant hosts
  - non-landlord-authorized users forbidden on landlord host routes

Explicit non-goals in this phase:
- no changes to post-auth `tenant.access` enforcement semantics
- no billing checkout/subscription lifecycle actions, impersonation, or unsafe landlord writes outside commercial configuration scope
- no Candle Cash/campaign/birthdays core-table changes
- no Shopify embedded/proxy/webhook behavior changes

## Shopify (Phase 1)
Required environment keys:
- `SHOPIFY_RETAIL_SHOP`
- `SHOPIFY_RETAIL_CLIENT_ID`
- `SHOPIFY_RETAIL_CLIENT_SECRET`
- `SHOPIFY_WHOLESALE_SHOP`
- `SHOPIFY_WHOLESALE_CLIENT_ID`
- `SHOPIFY_WHOLESALE_CLIENT_SECRET`
- `SHOPIFY_API_VERSION` (default `2026-01`)
- `SHOPIFY_SCOPES` (default `read_orders,read_products,read_customers`)
- `SHOPIFY_ALLOW_ENV_TOKEN_FALLBACK` (default `false`, legacy only)

OAuth (Admin) routes:
- `/shopify/auth/{store}`
- `/shopify/callback/{store}`
- `/shopify/reinstall/{store}`

CLI helper:
- `php artisan shopify:auth retail`
- `php artisan shopify:auth wholesale`

Notes:
- OAuth access tokens are stored in `shopify_stores` and encrypted at rest.
- CLI imports/sync use DB-installed OAuth tokens as primary source of truth.
- Static env access tokens are legacy fallback only when `SHOPIFY_ALLOW_ENV_TOKEN_FALLBACK=true`.
- `shopify:sync-customer-metafields` requires Admin API `read_customers` or `write_customers` scope; Customer Account `customer_*` scopes are not sufficient for Admin `customers` queries.
- Webhooks are verified with HMAC and dispatched to a sync queue (Phase 1).

### Modern Forestry Native Reviews + Wishlist

Modern Forestry now uses Backstage-owned canonical review and wishlist flows on the Shopify storefront. Growave remains import-only source data and must not be used as a runtime storefront dependency.

Storefront app-proxy endpoints:
- `GET /apps/forestry/product-reviews/status`
- `POST /apps/forestry/product-reviews/submit`
- `GET /apps/forestry/wishlist/status`
- `POST /apps/forestry/wishlist/add`
- `POST /apps/forestry/wishlist/remove`
- `POST /apps/forestry/wishlist/lists/create`

Contract/runtime expectations:
- Product review status always returns the native widget contract and `task.button_text = "Write a review"`.
- Product review rewards are tenant-scoped and, for Modern Forestry, default to `$1` (`100` cents).
- Reward credit is issued only after a fulfilled/completed order-line match for the same product passes dedupe checks.
- Guest reviews are allowed, but guest reviews do not receive Candle Cash unless they resolve to a qualifying customer/order match.
- Wishlist supports guests through `guest_token`, named lists, and merge-on-auth when a known customer resolves later.
- Reviews and wishlists must resolve through tenant-scoped Backstage data. `shopify_stores.tenant_id` must be present for the storefront store context.
- Storefront UX expectations:
  - All non-hero images use a shared premium border radius; hero/banner imagery remains square.
  - A left-fixed floating drawer stack stays visible sitewide, with `Reviews` first and `Wishlist` directly beneath it.
  - On product pages, the floating reviews drawer defaults to that product's reviews and offers a `See all reviews` path into the sitewide feed.
  - Wishlist entry points keep using the existing persistent wishlist contract and can render inside the floating wishlist drawer without redirecting to the account page.
  - On PDP meta stacks, reviews render on the left and wishlist actions on the right on desktop; they stack gracefully on smaller breakpoints.
- Backstage review moderation now captures admin responses (with responder + timestamps), exposes customer review counts/filters, and sends a single customer notification email the first time a response is posted. Responses surface in the Backstage list/detail views and in the approved storefront review payload.

Cutover/removal notes:
- Theme/runtime Growave widgets must stay disabled or removed.
- Any remaining Growave Shopify app embed or ScriptTag output must be removed operationally after deploy.
- Growave historical reviews/wishlists may still be imported into canonical Backstage rows, but live storefront reads/writes must use only Backstage-owned tables.

Observed live state on 2026-03-31:
- The production app proxy is returning the native review contract (`Write a review`, `$1.00` reward) and guest wishlist flows are succeeding end to end.
- The Shopify live theme path at `modernforestry.myshopify.com` is serving the cutover theme (`159310446851`) and no longer requests Growave assets at runtime.
- The remaining sign-off blocker is the custom storefront domain `theforestrystudio.com`, which still serves stale HTML from the older `Prestige` theme (`136487764227`) with Growave loader output still present.
- That stale body persists under cache-busting query params and `Cache-Control: no-cache`, and it also persists when the custom host is forced directly to Shopify's edge IP (`23.227.38.65`) via `curl --resolve`.
- Shopify response headers on the custom-domain path still report live theme `159310446851`, so the remaining mismatch is now best described as a custom-host Shopify render/cache/routing problem outside the app/backend repo, not a remaining proxy-contract defect.
- Treat the final cutover issue as an external operational blocker. The Backstage proxy contract is live and aligned.
- Exact next action: purge or bypass the custom-domain cache layer and re-test `theforestrystudio.com`; if the body still reports theme `136487764227`, escalate to Shopify support with the host-specific mismatch evidence.

## Deployment (GitHub Actions -> Production)
This repository deploys with `.github/workflows/deploy.yml`.

Triggers:
- Push to `main` (automatic deploy)
- Manual run via `workflow_dispatch` in GitHub Actions (with optional `run_tests` toggle)

Owner workflow:
```bash
git add .
git commit -m "Describe change"
git push origin main
```

Required GitHub secrets (configure in the `production` environment):
- `DEPLOY_HOST`
- `DEPLOY_USER`
- `DEPLOY_PORT`
- `DEPLOY_PATH`
- `DEPLOY_SSH_KEY` (private key for SSH access to the server)

Optional test prerequisites:
- `FLUX_USERNAME`
- `FLUX_LICENSE_KEY`

These are only needed for CI test/build when private Flux packages are required.

What Flux is doing in this project:
- The app depends on the private `livewire/flux` UI package from Composer.
- Flux provides the Blade UI components used throughout the Laravel/Livewire shell, including login/auth screens, settings pages, headers, sidebars, nav, modals, badges, buttons, inputs, and other shared interface pieces.
- In this repo you can see that dependency in `composer.json`, the CSS import in `resources/css/app.css`, and many `<flux:...>` components under `resources/views/`.
- Practically, buying Flux is paying for the UI component library and private package access that this app already uses, plus the ability for GitHub Actions and fresh environments to run `composer install` without failing on that private dependency.
- If you do not want to buy Flux, the alternative is to remove/replace those Flux components and styles across the app with another UI system.

Server prerequisites:
- Git with the app already cloned at `DEPLOY_PATH`
- PHP 8.2+ and required extensions
- Composer 2
- Node.js + npm (this app uses Vite)
- Writable Laravel directories (`storage`, `bootstrap/cache`)
- Database connectivity from the server
- Queue worker process manager (Supervisor/systemd) if queues are active

Server deploy command sequence:
- `git fetch origin main`
- `git checkout main`
- `git pull --ff-only origin main`
- `composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev`
- `npm ci`
- `npm run build`
- `rm -f public/hot`
- `php artisan migrate --force`
- `php artisan route:clear`
- `php artisan config:cache`
- `php artisan view:cache`
- `php artisan queue:restart`

Notes:
- `route:cache` is intentionally not used because the app currently has closure routes.
- Deploy is fail-fast and concurrency-guarded so only one production deploy runs at a time.

Known push/deploy pitfalls (2026-03-26):
- GitHub Action fails before deploy steps with missing-input errors:
  - Cause: one or more required `DEPLOY_*` secrets are missing in the `production` environment.
  - Check: GitHub -> Settings -> Environments -> `production` -> Secrets and variables.
  - Required: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PORT`, `DEPLOY_PATH`, `DEPLOY_SSH_KEY`.
- Deploy runs but production code is stale:
  - Cause: server repo is not on `main` (for example, left on a temporary branch).
  - Check on server:
    - `cd "$DEPLOY_PATH"`
    - `git branch --show-current`
    - `git rev-parse --short HEAD`
    - `git rev-parse --short origin/main`
  - Recovery:
    - `git fetch origin main`
    - `git checkout main`
    - `git pull --ff-only origin main`
- Push succeeds but no production rollout occurs:
  - Check whether the `Deploy Production` workflow is disabled.
  - Check Actions run status and inspect the first failed step (most failures here are config/secrets, not app code).
- Security hygiene for server remotes:
  - Do not keep personal access tokens embedded in `origin` URLs on production.
  - If a tokenized remote URL is found, rotate the token and switch to SSH/deploy-key auth.

Manual deploy:
1. Go to GitHub -> Actions -> `Deploy Production`.
2. Click `Run workflow`.
3. Choose `main` and (optionally) set `run_tests` to false.

Temporarily disable deploy:
- In GitHub -> Actions -> `Deploy Production` -> `...` menu -> `Disable workflow`.

## Shopify Embedded Session Cookies
- Embedded Shopify Admin requests run inside an `admin.shopify.com` iframe, so production session cookies must allow secure cross-site usage.
- Production env should include:
  - `SESSION_SECURE_COOKIE=true`
  - `SESSION_SAME_SITE=none`
  - `SESSION_PARTITIONED_COOKIE=true`
- After changing those values, run:
  - `php artisan optimize:clear`
  - `php artisan config:clear`

## Twilio SMS Configuration
- Set `TWILIO_ACCOUNT_SID` and `TWILIO_AUTH_TOKEN`.
- Preferred: configure `MARKETING_TWILIO_SENDERS` as a JSON array of sender objects. All senders share the same `TWILIO_ACCOUNT_SID` and `TWILIO_AUTH_TOKEN`.
  - MG sender:
    - `[{"key":"toll_free","label":"Toll-free","type":"toll_free","status":"active","enabled":true,"default":true,"phone_number_sid":"PN...","messaging_service_sid":"MG..."}]`
  - Direct sender:
    - `[{"key":"local","label":"Local","type":"local","status":"active","enabled":true,"phone_number_sid":"PN...","from_number":"+15555550123"}]`
  - Mixed sender config:
    - `[{"key":"toll_free","label":"Toll-free","type":"toll_free","status":"active","enabled":true,"default":true,"phone_number_sid":"PN...","messaging_service_sid":"MG..."},{"key":"local","label":"Local","type":"local","status":"pending","enabled":false,"default":false,"phone_number_sid":"PN...","from_number":"+15555550123"}]`
  - `phone_number_sid` is metadata only.
- Optional: set `MARKETING_TWILIO_DEFAULT_SENDER` to force the default sender key.
- Backward-compatible migration fallback:
  - `TWILIO_MESSAGING_SERVICE_SID` (recommended, must start with `MG`), or
  - `TWILIO_FROM_NUMBER` (E.164 format like `+18339625949`).
- Enable provider flags:
  - `MARKETING_SMS_ENABLED=true`
  - `MARKETING_TWILIO_ENABLED=true`
- Operational verification:
  - `php artisan marketing:send-test-sms +15551234567 "Test message" --sender=toll_free`

## Candle Cash Gift Reporting
- Gift transactions now persist `gift_intent`, `gift_origin`, `notified_via`, `notification_status`, and `campaign_key` in `candle_cash_transactions`.
- Backstage surfaces a `Gift insights` tab under Candle Cash (`/marketing/candle-cash/gifts-report`) that summarizes total gifts, intent/origin/notification breakdowns, actor attribution, recent gift rows, and a simple post-gift conversion proxy.
- Use the date filters on that page to focus on a specific window and understand whether gifted customers later placed orders.

## Tenant Rewards Policy Layer
- Tenant rewards management extends the existing Candle Cash execution engine (wallet, issuance, redemption, reconciliation) instead of creating a parallel rewards system.
- Embedded Shopify API now includes tenant policy endpoints:
  - `GET /shopify/app/api/rewards/policy`
  - `PATCH /shopify/app/api/rewards/policy`
  - `POST /shopify/app/api/rewards/policy/review`
  - `POST /shopify/app/api/rewards/policy/defaults/alpha`
- Writes are entitlement-aware and fail closed:
  - eligible tenants can edit policy
  - non-eligible tenants are read-only/upsell
- Policy domains are stored in existing tenant settings keyspace (`tenant_marketing_settings`) with runtime-compatible keys such as:
  - `candle_cash_program_config`
  - `candle_cash_notification_config`
  - `candle_cash_finance_config`
  - `candle_cash_access_state`
- Phase 2 policy safety and operator UX additions:
  - field control metadata (`editable`, `editable_with_warning`, `restricted`) returned from service layer
  - plain-English summary generation via `TenantRewardsPolicySummaryService`
  - business warning model with `errors` / `warnings` / `info`
  - customer message previews for SMS + email rendered from live policy
  - lightweight policy versioning in tenant settings (`candle_cash_policy_version_meta`, `candle_cash_policy_versions`)
  - append-only audit history for policy changes via existing operator action audit model
  - review-and-publish flow in embedded rewards notifications UI
  - alpha starter defaults path (`Candle Cash`, second-order `$10`, minimum spend `$50`, 90-day expiry)
- Phase 3 launch-readiness additions:
  - tenant reminder scheduling via `TenantRewardsReminderScheduleService`
  - reminder event history reusing `marketing_automation_events` through `TenantRewardsReminderLogService`
  - launch readiness evaluation via `TenantRewardsPolicyReadinessService`
  - separate email vs text reminder timing (`email_reminder_offsets_days`, `sms_reminder_offsets_days`) with legacy `reminder_offsets_days` backward compatibility
  - policy-version traceability included in reminder previews/history (`runtime_traceability`, reminder event `policy_version`)
  - live readiness panel, publish change preview, and customer reminder history in the embedded rewards notifications workspace
  - Alpha starter confirmation summary showing the active recommended setup in plain business language
- Phase 4 operational additions:
  - live reminder dispatch orchestration via `TenantRewardsReminderDispatchService` using the existing SendGrid and Twilio delivery paths
  - hourly reminder processor command: `php artisan marketing:process-tenant-rewards-reminders`
  - reminder activity + analytics reporting in the embedded rewards workspace via `TenantRewardsReminderAnalyticsService`
  - rewards reminder outcomes logged as sent / failed / skipped with version-aware reminder keys in `marketing_automation_events`
  - stronger merchant-managed exclusions for limited releases, collections, product tags, and specific products
  - clearer channel strategy controls (`online_only`, `show_issued_online_redeemed`, `exclude_shows`) with unsupported combinations shown as unavailable instead of enabled
  - Alpha launch checklist, launch summary, and recommended next steps in plain business language
  - operator-safe support actions through the reminder processor command (`--dry-run`, `--reward`, `--profile`, `--channel`, `--mark-skipped`, `--force`)
- Phase 5 control, observability, and finance additions:
  - reminder explainability/debug support built on the existing schedule + dispatch services, exposed through embedded rewards support actions
  - finance visibility via `TenantRewardsFinanceSummaryService`:
    - estimated outstanding rewards liability
    - issued vs redeemed vs unredeemed value
    - breakage estimate
    - realized discount value
    - expiring-soon reward value
  - CSV exports for:
    - reminder history
    - reward issuance
    - reward redemption
    - expiring rewards
    - finance summary
  - filterable reminder reporting in the embedded rewards workspace:
    - date range
    - channel
    - status
    - reward type
  - launch/readiness health signals such as:
    - SMS not configured
    - no reminders sent recently
    - high skip rate
    - large expiring reward volume
  - lightweight impact view showing projected reminder volume, expiring rewards, and redemption exposure from recent data
  - optional queued reminder dispatch hardening:
    - `php artisan marketing:process-tenant-rewards-reminders --queue`
    - queued job `DispatchTenantRewardsReminderJob`
    - idempotent unique reminder jobs by tenant/reward/channel/timing/version
    - safe retry/backoff without duplicating reminder sends
  - narrow support tooling in the existing embedded rewards workflow:
    - explain a specific reminder outcome
    - load reminder history for one customer
    - requeue one eligible reminder
    - mark one reminder as skipped with a reason
  - support/operator actions remain append-only and traceable through the existing landlord operator audit model
- Phase 6 automation, trust, and monetization readiness additions:
  - tenant operations/runtime state via `TenantRewardsOperationsService` using the existing tenant settings layer:
    - `candle_cash_operations_config`
    - `candle_cash_team_access_config`
    - `candle_cash_automation_state`
  - automatic reminder processing status for eligible live tenants:
    - automation mode (`automatic` / `manual`)
    - default mode is `manual` until a tenant explicitly switches automation on
    - last run time
    - last success time
    - last failure time
    - failure count
    - scheduler respects tenant automation mode so manual tenants are skipped by cron
    - "Automation is running" / "Automation is off" / "Automation needs attention" messaging in the rewards workspace
  - scheduled finance-report delivery reusing the existing export + email stack:
    - `php artisan marketing:send-tenant-rewards-finance-reports`
    - daily / weekly finance snapshots
    - signed CSV download links included in scheduled finance emails
  - finance exports now include a signed-download path for scheduled delivery:
    - `GET /rewards/policy/exports/signed/{tenant}/{type}`
  - alert surfacing built on existing reminder reporting + finance summary state:
    - no reminders sent in the configured window while automation mode is automatic
    - high skip rate
    - failure spike
    - large expiring reward volume
    - liability above threshold
    - optional alert email delivery through the existing SendGrid path
  - lightweight team access rules now cover:
    - who can edit program settings
    - who can publish live changes
    - who can switch automation mode
    - who can use reminder support tools
  - lightweight team access controls in the existing rewards workspace:
    - who can edit program settings
    - who can publish live changes
    - who can use reminder support tools
  - module-usage visibility for commercial readiness:
    - rewards module enabled state
    - rewards issued
    - rewards reminders sent
    - included-limit watch/high states where limit data is available
  - lightweight "What happens if..." simulation from recent tenant data:
    - reward value change impact
    - expiration change impact
    - projected reminder volume
    - near-term expiring reward value
- Architecture and rollout details: `docs/architecture/tenant-rewards-management-layer.md`.

## Email Provider-Context Reporting
- Birthday analytics and exports expose provider context directly from canonical delivery metadata in `marketing_email_deliveries`.
- Supported reporting dimensions include:
  - `provider_resolution_source` (`tenant`, `fallback`, `none`, `unknown`)
  - `provider_readiness_status` (`ready`, `unsupported`, `incomplete`, `error`, `not_configured`, `unknown`)
- Embedded birthday analytics filters now include provider resolution/readiness context, and exports include matching breakdown rows.
- Campaign delivery diagnostics now show provider resolution/readiness/runtime-path summaries per campaign.
- Customer email timeline now shows row-level provider-context labels plus summary chips (tenant vs fallback paths, unsupported/incomplete attempts, and legacy/unknown rows) on `marketing.customers.show`.
- Customer email timeline now supports operator filters for `provider_resolution_source` and `provider_readiness_status`.
- Customer email timeline CSV export now has filter parity via `marketing.customers.email-deliveries.export` (`/marketing/customers/{marketingProfile}/email-deliveries/export`) and includes provider-context labels for legacy/unknown rows.
- Architecture details: `docs/architecture/birthday-provider-context-reporting.md`.

## Operational Architecture Guidance
- For cross-domain boundary decisions (tenant/platform vs reusable ops primitives vs candle-specific logic), use:
  - `docs/architecture/operational-multi-tenant-direction.md`
- This guidance is intended for future implementation runs touching customers, inventory/internal ops, order workflows, and lifecycle communications.

## Storefront Rewards Sidecar (Theme Repo)
- The current `/pages/rewards` UI sidecar lives in the separate Shopify theme repository:
  - `/Users/johncollins/projects/modernforestry-live-theme`
  - implemented in `assets/forestry-rewards.js` + `assets/forestry-rewards.css`
- Recent sidecar update delivered:
  - compact theme toggle/dropdown
  - removed top rewards-summary clutter
  - unified birthday reward + intake into one expandable card
  - collapsible opportunity cards for compact default scan
  - removed task/reward history blocks from page layout
  - mobile/desktop spacing and hierarchy polish
- Backend remains canonical for identity/rewards state; sidecar only presents and invokes existing contracts.

## Rewards Status Stall + Cart CTA Remediation (2026-04-07)
This section records what behavior was observed, what was changed, and what should be monitored for side effects.

### What the site was doing before this fix
- Storefront rewards status calls were intermittently timing out from the custom domain:
  - `GET /apps/forestry/candle-cash/status`
  - `GET /apps/forestry/rewards/available`
- At the same time, lighter app-proxy routes were healthy:
  - `GET /apps/forestry/health`
  - `GET /apps/forestry/customer/status`
- On cart/rewards surfaces, fallback state often rendered:
  - `cta_label = "Check reward status"`
  - message about checking redemption access
  - CTA looked non-actionable (disabled-looking pending state for some served JS/HTML combinations).

### Root cause pattern identified
- Storefront runtime was still hitting a heavy tenant policy resolve path intended for richer Backstage/admin payloads.
- That heavier path made storefront reward/status responses more vulnerable to latency spikes/timeouts.
- A separate custom-domain HTML/asset cache drift could intermittently re-serve stale fallback snapshots and stale script revisions, creating mixed behavior even after code changes were pushed.

### Backend changes shipped
- `TenantRewardsPolicyService`:
  - added lightweight `storefrontSnapshot(?int $tenantId)` accessor for storefront-safe payload needs only.
- `MarketingShopifyIntegrationController`:
  - `storefrontProgramPayload()` now uses the lightweight snapshot instead of full `resolve()` path.
  - added slow-request instrumentation for storefront scopes:
    - `available_rewards`
    - `candle_cash_status`
  - warns when request duration exceeds `marketing.shopify.storefront_slow_request_ms` (default `1500ms`, floor `250ms`).
- Added operational command for canonical page refresh:
  - `php artisan marketing:touch-shopify-page-cache retail --handle=rewards`
  - supports `--dry-run`.
  - note: Shopify GraphQL mutation uses `pageUpdate(id: ..., page: ...)` signature.

### Theme/runtime changes shipped
- `assets/forestry-rewards.js`:
  - pending/neutral coming-soon state now renders actionable retry button:
    - `data-action="refresh-status"`
  - retry action forces re-hydration:
    - `loadAndRender(root, { force: true })`
  - fail-closed behavior remains intact (no accidental redemption enablement when API is unavailable).
- Additional cache-hardening edits were shipped in theme layout/cart templates to reduce stale script pinning risk from cached page shells.

### What changed in user-facing behavior
- Before:
  - pending state could appear dead/non-clickable.
  - users could remain stuck on stale fallback copy while status API recovered.
- After:
  - pending state has explicit retry behavior.
  - storefront status endpoints are on lighter code path and return promptly under normal load.
  - fallback remains neutral and safe while API is unavailable.

### Operational notes from this rollout
- The active live theme during this incident was `#159737250051` (`rewards-cache-reset-20260407`), not the older previously-tracked ID.
- Custom-domain cache drift still needs operational handling when stale HTML/script revisions reappear.
- The page-touch command successfully forces canonical `/pages/rewards` regeneration, but does not by itself guarantee custom-domain CDN/edge purges for every cached shell.

### Areas to re-check for possible side effects
- Storefront contract payload integrity:
  - `program`, `copy`, `redemption_access`, and reward/task rendering paths across page/cart/drawer surfaces.
- Any logic that depends on rich policy metadata:
  - storefront now intentionally receives only lightweight policy subset.
  - Backstage/admin flows should continue using full policy resolve path.
- Latency instrumentation volume:
  - monitor warning rates for `shopify storefront request slow`.
- Theme shell consistency across hosts:
  - `/pages/rewards`
  - `/cart`
  - ensure custom domain and myshopify domain serve the same current asset versions.

### Cross-feature sanity checklist (because this touched shared rewards plumbing)
- Google review reward behavior:
  - auto mode still uses `POST /apps/forestry/google-business/review/start`.
  - manual fallback (when approval/sync not ready) still routes through task submission with pending manual review.
- Internal product review rewards:
  - verified review submit path still creates/awards Candle Cash once per qualifying rule.
- Consent/signup bonuses:
  - login/register and rewards CTA opt-in flows still call `POST /apps/forestry/consent/opt-in` and award configured bonuses.
- Redemption access authority:
  - access state remains API-authoritative (`/apps/forestry/candle-cash/status`) and fail-closed during API failure.
- Admin surfaces:
  - Backstage policy/analytics pages must keep full `resolve()` behavior (no storefront fast-path substitution).

### Verification commands used in this remediation
- Endpoint health/latency checks:
  - `curl -sS -m 8 https://theforestrystudio.com/apps/forestry/candle-cash/status`
  - `curl -sS -m 8 https://theforestrystudio.com/apps/forestry/rewards/available`
  - `curl -sS -m 8 https://theforestrystudio.com/apps/forestry/health`
- Canonical rewards page touch:
  - `php artisan marketing:touch-shopify-page-cache retail --handle=rewards`

## Candle Cash GA Rollout (2026-04-08)
This update removes the temporary beta redemption rollout gate and keeps Candle Cash redeemable for normal eligible users.

### What behavior existed before
- Storefront/public redemption access depended on `marketing.candle_cash.temporary_storefront_live_email_allowlist`.
- Non-allowlisted users could be hard-blocked with `coming_soon` states and `COMING SOON!` CTA copy.
- Public rewards lookup UI included selected-account fallback copy.

### What changed
- `CandleCashAccessGate` now treats storefront redemption access as GA (`redeem_enabled=true` by default runtime path).
- Shopify and public redemption controllers no longer emit beta-only `coming_soon` copy/codes for rollout gating.
- Public rewards lookup view no longer defaults to selected-account/`COMING SOON!` copy.
- Legacy env/config key `MARKETING_CANDLE_CASH_TEMP_LIVE_EMAIL_ALLOWLIST` is retained only for compatibility and is now effectively ignored by access gating.

### What remained intentionally unchanged
- All redemption integrity/safety controls in `CandleCashService`:
  - active reward checks
  - insufficient-balance enforcement
  - single-open-code controls (`max_open_codes`)
  - duplicate/idempotent task protections
  - Shopify discount-sync fail-safe with automatic balance restoration

## Future Purchasable Add-Ons (Tenant-Scoped)
- Build future apps/modules as tenant-scoped add-ons attached to the shared platform shell.
- Reuse canonical identity and marketing architecture:
  - `marketing_profiles`
  - `customer_external_profiles`
  - `marketing_profile_links`
  - existing sync/service pipelines
- Prefer extending existing signed storefront/API contracts before adding new surfaces.
- Feature access must be tenant-scoped and billing-aware (no global hardcoded availability).
- Do not fork per-tenant architecture; use one reusable module with tenant-level configuration.

## Shopify Embedded Messaging Workspace (Tenant-Gated Add-On)
- New embedded workspace route/tab:
  - page route: `/shopify/app/messaging`
  - nav registration: `app/Services/Shopify/ShopifyEmbeddedPageRegistry.php` (`key=messaging`, label `Messages`, `requires_enabled_access=true`)
- Gating model:
  - module key: `messaging`
  - add-on mapping: `module_catalog.addons.messaging.modules=['messaging']`
  - commercial/Stripe readiness mapping: `commercial.addons.messaging` + `commercial.stripe_mapping.addons.messaging`
  - non-enabled tenants do not see the tab and cannot use messaging APIs.
- Modern Forestry default:
  - migration `2026_04_03_091000_seed_modern_forestry_messaging_entitlement.php` seeds an enabled entitlement for tenant slug `modern-forestry`.
- Customer search reuse:
  - reuses existing Customers-grid query conventions via `ShopifyEmbeddedCustomersGridService::searchProfilesForMessaging`.
- Group model/persistence:
  - tenant-scoped saved groups in `marketing_message_groups`
  - members in `marketing_message_group_members`
  - tenant rail fields added by `2026_04_03_090000_extend_marketing_message_groups_for_tenant_workspace.php`.
- Automatic audience:
  - `All Subscribed` is optional (never auto-selected) and channel-aware.
  - audience derivation uses effective consent truth:
    - canonical channel consent flags, plus
    - legacy-import subscribed signals (`yotpo_contacts_import`, `square_marketing_import`, `square_customer_sync`) when no newer opt-out/revoked event supersedes them.
  - channel sendability still requires valid contact identity:
    - SMS eligible: consent + valid phone normalization/E.164
    - Email eligible: consent + normalized valid email
  - audience diagnostics endpoint:
    - `GET /shopify/app/api/messaging/audience-summary`
    - returns displayed count + query candidate + resolved sendable count by channel
- Preview/confirmation flow:
  - `POST /shopify/app/api/messaging/preview/group` returns recipient estimate before final send
  - group sends dispatch only after explicit preview confirmation in the Messaging UI
- Send pipeline reuse:
  - SMS: existing `TwilioSmsService`
  - Email: existing `SendGridEmailService`/tenant email dispatch path
  - history/logging uses `marketing_message_deliveries` and `marketing_email_deliveries`.
- Implementation/testing details:
  - `docs/architecture/shopify-embedded-messaging-workspace.md`

## Legacy Subscription Reconciliation (Yotpo + Square)
- Tenant-scoped maintenance command:
  - `php artisan marketing:reconcile-legacy-subscriptions --tenant-id={id} [--dry-run] [--limit=500]`
- Purpose:
  - reconcile legacy imported subscribed customers back into canonical profile consent flags when historical import truth exists but current flags are off.
- Legacy source truth used:
  - `marketing_consent_events.event_type=imported` from:
    - `yotpo_contacts_import`
    - `square_marketing_import`
    - `square_customer_sync`
- Safety rails:
  - command fails closed without `--tenant-id`
  - channels with newer explicit opt-out/revoked events are not re-enabled
  - reconciliation updates are tagged with `source_type=legacy_import_reconciliation`
  - context includes reward/task suppression intent (`suppress_subscription_rewards`, `do_not_issue_candle_cash`, `do_not_enqueue_new_subscriber_tasks`)
- Canonical behavior:
  - reconciliation mode re-enables imported subscribed channels and clears stale `*_opted_out_at` values only for channels being reconciled
  - standard opt-in flows still keep their normal reward behavior (`email-signup`, SMS consent bonus)
