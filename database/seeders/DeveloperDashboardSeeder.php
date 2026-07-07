<?php

namespace Database\Seeders;

use App\Models\AgenticChange;
use App\Models\ReadinessChecklistItem;
use App\Models\VisionIdea;
use Illuminate\Database\Seeder;

/**
 * Populates the landlord Developer Control Center with the real recent change log
 * and the current vision board. Idempotent (keyed on slug) — safe to re-run on prod
 * via: php artisan db:seed --class=DeveloperDashboardSeeder
 */
class DeveloperDashboardSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->changes() as $change) {
            AgenticChange::query()->updateOrCreate(['slug' => $change['slug']], $change);
        }

        foreach ($this->ideas() as $index => $idea) {
            VisionIdea::query()->updateOrCreate(
                ['slug' => $idea['slug']],
                $idea + ['sort_order' => $index + 1]
            );
        }

        foreach ($this->checklistItems() as $index => $item) {
            ReadinessChecklistItem::query()->updateOrCreate(
                ['slug' => $item['slug']],
                $item + ['sort_order' => $index + 1]
            );
        }
    }

    /**
     * Production-readiness checklist. When a vision-board idea ships, flip the
     * matching item here to 'done' (and set the VisionIdea status to 'done' so it
     * drops off the board). See README_FOR_AGENTS.md "Developer Control Center".
     *
     * @return array<int, array<string, string>>
     */
    private function checklistItems(): array
    {
        return [
            ['slug' => 'version-control', 'label' => 'Version control (GitHub)', 'category' => 'Foundation', 'status' => 'done', 'detail' => 'All code is tracked in the johncollinsgit/TimberLine GitHub repository.'],
            ['slug' => 'laravel-current', 'label' => 'Modern framework (Laravel 12)', 'category' => 'Foundation', 'status' => 'done', 'detail' => 'Running Laravel 12 on PHP 8.4 — current and supported.'],
            ['slug' => 'automated-tests', 'label' => 'Automated test suite', 'category' => 'Foundation', 'status' => 'done', 'detail' => 'A Pest test suite (~287 test files) covers the backend.'],
            ['slug' => 'ci-gate', 'label' => 'CI runs tests before every deploy', 'category' => 'Foundation', 'status' => 'done', 'detail' => 'The deploy workflow now runs the full test suite and asset build on every push to main and blocks the deploy on failure.'],
            ['slug' => 'automated-deploys', 'label' => 'Automated deploys from main', 'category' => 'Foundation', 'status' => 'done', 'detail' => 'Pushing to main deploys to production via GitHub Actions over SSH.'],

            ['slug' => 'https-tls', 'label' => 'HTTPS / TLS everywhere', 'category' => 'Infrastructure', 'status' => 'done', 'detail' => 'All domains are served over HTTPS behind Cloudflare.'],
            ['slug' => 'managed-mysql', 'label' => 'MySQL database', 'category' => 'Infrastructure', 'status' => 'done', 'detail' => 'Production runs on MySQL 8.4.'],
            ['slug' => 'daily-backups', 'label' => 'Automated daily database backups', 'category' => 'Infrastructure', 'status' => 'done', 'detail' => 'Daily database backups run via Laravel Forge (Business plan) to object storage.'],
            ['slug' => 'backup-restore', 'label' => 'Backup restore tested', 'category' => 'Infrastructure', 'status' => 'todo', 'detail' => 'A backup you have never restored is only a hopeful guess. Restore one into a throwaway database to prove the recovery path works.'],
            ['slug' => 'right-sized-server', 'label' => 'Right-sized production server', 'category' => 'Infrastructure', 'status' => 'done', 'detail' => 'Resized to 8 GB RAM / 4 vCPU — comfortable headroom for app, database, queue, and builds.'],

            ['slug' => 'scheduler-monitoring', 'label' => 'Scheduler health monitoring', 'category' => 'Observability', 'status' => 'done', 'detail' => 'A per-minute heartbeat detects a stalled scheduler and raises a health event.'],
            ['slug' => 'integration-health', 'label' => 'Integration health tracking', 'category' => 'Observability', 'status' => 'done', 'detail' => 'Shopify imports, webhooks, and other integrations open auto-resolving health events when they break.'],
            ['slug' => 'error-tracking', 'label' => 'Application error tracking (Sentry)', 'category' => 'Observability', 'status' => 'todo', 'detail' => 'No application error tracking yet — errors are only visible in log files. Wiring Sentry is the next observability layer (on the vision board).'],
            ['slug' => 'uptime-alerting', 'label' => 'External uptime alerting', 'category' => 'Observability', 'status' => 'todo', 'detail' => 'No external dead-man\'s-switch/uptime alert yet. Add healthchecks.io or Forge monitoring so an outage pages you even if the app is fully down.'],

            ['slug' => 'authentication', 'label' => 'Authentication with 2FA', 'category' => 'Security', 'status' => 'done', 'detail' => 'Laravel Fortify handles auth with email verification and confirmed two-factor authentication.'],
            ['slug' => 'password-policy', 'label' => 'Strong password policy', 'category' => 'Security', 'status' => 'done', 'detail' => 'Production enforces 12+ character passwords with mixed case, numbers, symbols, and a HaveIBeenPwned check.'],
            ['slug' => 'google-oauth', 'label' => 'Google OAuth single sign-on', 'category' => 'Security', 'status' => 'partial', 'detail' => 'Google SSO is implemented via Socialite but currently returns invalid_client — the OAuth client secret needs to be corrected in production before it can be relied on.'],
            ['slug' => 'encrypted-secrets', 'label' => 'Encrypted tokens & server-side secrets', 'category' => 'Security', 'status' => 'done', 'detail' => 'Shopify/Google/email provider tokens are stored encrypted; secrets stay server-side.'],
            ['slug' => 'tenant-isolation', 'label' => 'Enforced tenant data isolation', 'category' => 'Security', 'status' => 'partial', 'detail' => 'Opt-in scoping (->forTenant()) with a guardrail test. The module audit found the candle-ops surface queries unscoped and some tables lack tenant_id. Converting HasTenantScope to an enforced global scope is the keystone item on the vision board.'],

            ['slug' => 'module-entitlement-gate', 'label' => 'Enforced module entitlements', 'category' => 'Multi-tenant', 'status' => 'todo', 'detail' => 'module_catalog defines plans/modules but nothing enforces them at the request layer — routes are role-gated only and canAccess() just hides nav. A single module:{key} route gate closes it.'],
            ['slug' => 'standard-module-contract', 'label' => 'Standard module contract', 'category' => 'Multi-tenant', 'status' => 'todo', 'detail' => '~2/3 exists (module_catalog declarations + the access resolver + shared module-* UI). Missing: declarative nav/routes, a per-tenant config schema, and a thin module interface + registry so every module plugs in uniformly.'],
            ['slug' => 'per-tenant-integrations', 'label' => 'Per-tenant integration connections', 'category' => 'Multi-tenant', 'status' => 'partial', 'detail' => 'Email/SendGrid is per-tenant (the good pattern). SMS/Twilio is one global account; Shopify + Google Business are single-account (Modern Forestry). A normalized integration_connections table + Socialite generalizes it so each tenant connects their own accounts.'],
            ['slug' => 'tenant-neutral-core', 'label' => 'Tenant-neutral core, verticals gated', 'category' => 'Multi-tenant', 'status' => 'partial', 'detail' => 'Core auth/tenancy/customers/marketing-identity generalize; the candle-ops modules are single-tenant verticals (correct if entitlement-gated + isolated), but candle vocabulary still leaks into some shared views.'],
            ['slug' => 'provable-isolation', 'label' => 'Provable isolation (adversarial 2nd tenant)', 'category' => 'Multi-tenant', 'status' => 'todo', 'detail' => 'Multi-tenant readiness stays an estimate until a real second tenant shares the database and isolation holds under attack.'],

            ['slug' => 'api-integrations', 'label' => 'External API integrations', 'category' => 'Integrations', 'status' => 'partial', 'detail' => 'Live integrations with Shopify, Twilio, SendGrid, Square, Google — but several (SMS, Shopify, Google Business) are wired to a single global/flagship account rather than per-tenant. See Per-tenant integration connections.'],
            ['slug' => 'versioned-api', 'label' => 'Versioned API layer', 'category' => 'Integrations', 'status' => 'todo', 'detail' => 'Mobile, webhook, and storefront JSON endpoints still live in the single web.php. Extracting a versioned API layer is on the vision board.'],
            ['slug' => 'gdpr-redaction', 'label' => 'GDPR redaction automation', 'category' => 'Integrations', 'status' => 'todo', 'detail' => 'Shopify privacy webhooks are recorded for manual review with no automated deletion yet.'],

            ['slug' => 'supervised-queue', 'label' => 'Supervised queue worker', 'category' => 'Foundation', 'status' => 'partial', 'detail' => 'The queue is drained by an every-minute scheduled command rather than a persistent supervised worker — fine for now, a ceiling later.'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function changes(): array
    {
        return [
            [
                'slug' => 'structure-first-professionalization',
                'title' => 'Structure-first plan: from kit car to Toyota racing car',
                'category' => 'architecture',
                'status' => 'in-progress',
                'impact' => 'high',
                'reference' => 'docs/architecture/module-standardization-and-readiness-2026-07-07.md',
                'changed_at' => '2026-07-07 12:00:00',
                'summary' => "Reprioritized toward structural professionalism over feature polish. A professional multi-tenant needs three things: enforced isolation (a forgotten filter can't leak), a tenant-neutral core with entitlement-gated vertical modules, and provable tenant separation. The monolith itself is sound (Laravel 12, 326 services, ~287 tests, CI gate). The keystone gaps are enforced tenant isolation and enforced module entitlements — two consistent fixes, not sixteen bespoke ones.",
            ],
            [
                'slug' => 'module-readiness-audit',
                'title' => 'Multi-tenant readiness audit of all 16 modules',
                'category' => 'architecture',
                'status' => 'shipped',
                'impact' => 'high',
                'reference' => 'docs/architecture/module-standardization-and-readiness-2026-07-07.md',
                'changed_at' => '2026-07-07 11:30:00',
                'summary' => "Audited every module for its ability to be used by a tenant other than Modern Forestry. Finding: almost NO tenant_id===1 hardcoding — flagship coupling is candle vocabulary in shared code, operational tables (markets/pouring/retail) missing a tenant_id column, unscoped queries, and slug-based nav gating. Every module independently surfaced the same two systemic gaps: isolation isn't enforced and module entitlements aren't enforced (they only hide nav). customers is the reference for 'done'; SMS/Twilio, Google Business, and the candle-ops surface are the reddest.",
            ],
            [
                'slug' => 'standard-module-contract',
                'title' => 'Defined the Standard Module Contract',
                'category' => 'architecture',
                'status' => 'shipped',
                'impact' => 'high',
                'reference' => 'module_catalog + TenantModuleAccessResolver',
                'changed_at' => '2026-07-07 11:45:00',
                'summary' => "The system is ~2/3 of the way to a real module framework: the decision + presentation layer (module_catalog declarations, TenantModuleAccessResolver's uniform active/setup/locked/coming-soon states, shared module-* UI cards, audited enable/disable) is already a cohesive contract. Missing is the wiring: declarative nav/routes, a per-tenant config schema, a single module:{key} route gate, enforced data scoping, and a thin module interface + registry. Documented the target standard every module plugs into and the smallest incremental path to adopt it.",
            ],
            [
                'slug' => 'production-deploy-live',
                'title' => 'Shipped to production: dashboard, CI gate, new-user fix, popup',
                'category' => 'reliability',
                'status' => 'shipped',
                'impact' => 'high',
                'reference' => 'PR #16 · deploy success',
                'changed_at' => '2026-07-07 03:46:00',
                'summary' => "Merged and deployed to production through the new CI gate (it ran the full suite + build, then deployed over SSH in 51s). Live: the new-user misassignment fix (memberless users create their own workspace, no longer absorbed into Modern Forestry) and this Developer Control Center. Deployed but off behind FEATURE_FIRST_LOGIN_MODAL: the personable popup workspace flow. Owner's Modern Forestry membership verified explicit before the auth change went live.",
            ],
            [
                'slug' => 'developer-control-center',
                'title' => 'Developer Control Center dashboard',
                'category' => 'operator',
                'status' => 'shipped',
                'impact' => 'medium',
                'reference' => 'landlord.developer',
                'changed_at' => '2026-07-06 17:00:00',
                'summary' => 'Added a landlord-only operator dashboard at /landlord/developer showing live system status (scheduler heartbeat), last database backup, open integration issues, and last Shopify import — plus a clickable log of recent agentic changes and a forward-looking vision board. Read-only: it reports status and history but does not deploy, mutate tenants, or change billing.',
            ],
            [
                'slug' => 'ci-deploy-gate',
                'title' => 'CI now gates every deploy',
                'category' => 'reliability',
                'status' => 'shipped',
                'impact' => 'high',
                'reference' => '.github/workflows/deploy.yml',
                'changed_at' => '2026-07-06 16:30:00',
                'summary' => 'Previously a push to main deployed to production without running the test suite (tests only ran on manual dispatch). The deploy workflow now runs the full Pest suite and asset build on every push to main and blocks the deploy if anything fails. Missing Flux CI secrets now fail loudly instead of silently skipping. A deliberate emergency-hotfix bypass remains available via manual dispatch.',
            ],
            [
                'slug' => 'daily-database-backups',
                'title' => 'Automated daily database backups enabled',
                'category' => 'reliability',
                'status' => 'shipped',
                'impact' => 'high',
                'reference' => 'Forge Business · ops:record-backup',
                'changed_at' => '2026-07-06 15:30:00',
                'summary' => 'Production had no automated database backups — the entire business sat on one server with no recovery point. Enabled daily database backups via Laravel Forge (Business plan) to object storage, and added an ops:record-backup command so backup completions surface on the operator dashboard. Next: a one-time restore drill to prove the backups actually restore.',
            ],
            [
                'slug' => 'production-server-resize',
                'title' => 'Production server resized to 8 GB / 4 vCPU',
                'category' => 'infrastructure',
                'status' => 'shipped',
                'impact' => 'medium',
                'reference' => 'Laravel VPS growth → 8GB',
                'changed_at' => '2026-07-06 15:00:00',
                'summary' => 'The production Laravel VPS was a 1 vCPU / 2 GB box sitting ~74% full at idle, which starved Vite asset builds (exit-137 out-of-memory failures during deploys). Resized to 8 GB RAM / 4 vCPU, giving comfortable headroom for the app, MySQL, the queue, scheduled imports, and asset builds all sharing one host.',
            ],
            [
                'slug' => 'infra-audit-two-droplets',
                'title' => 'Production infrastructure audit',
                'category' => 'infrastructure',
                'status' => 'shipped',
                'impact' => 'medium',
                'reference' => '129.212.138.111 · Laravel VPS',
                'changed_at' => '2026-07-06 14:30:00',
                'summary' => "Mapped where production actually runs. It is a single Laravel-managed VPS (Forge server backstage-pfw, 129.212.138.111) serving every domain from one nginx with MySQL on-box and the scheduler cron confirmed active. Discovered a separate, blank, never-provisioned DigitalOcean droplet — also named 'Backstage' — that had been billing for months in error; flagged for destruction.",
            ],
            [
                'slug' => 'smarter-scent-matching',
                'title' => 'Smarter scent mapping (stopwords + acronyms)',
                'category' => 'operations',
                'status' => 'shipped',
                'impact' => 'high',
                'reference' => 'ResolveScentMatchService',
                'changed_at' => '2026-07-06 12:00:00',
                'summary' => "Raw order lines carry inconsistent names ('… mason candle 4oz', 'one AMB', 'Beard Soy Candle') that employees had to map to canonical scents by hand. Added candle-domain stopword stripping and initialism/acronym matching to the live ResolveScentMatchService so these now resolve automatically. Next lever: write resolved mappings back to scent_aliases so the engine self-learns.",
            ],
            [
                'slug' => 'orders-prune',
                'title' => 'Safe scoped order pruning command',
                'category' => 'operations',
                'status' => 'shipped',
                'impact' => 'medium',
                'reference' => 'orders:prune',
                'changed_at' => '2026-07-06 11:00:00',
                'summary' => 'The legacy orders:purge command truncated all orders and left orphaned rows across eight referencing tables. Built orders:prune — a year- and tenant-scoped delete (dry-run + backup first) that cleanly removes order-owned rows and nulls value-bearing references. Ready to remove pre-2026 orders from production after a dry-run review.',
            ],
            [
                'slug' => 'both-store-imports',
                'title' => 'Order imports now cover both storefronts',
                'category' => 'operations',
                'status' => 'shipped',
                'impact' => 'high',
                'reference' => 'routes/console.php',
                'changed_at' => '2026-07-06 10:00:00',
                'summary' => 'The scheduled Shopify order import only polled the retail store — wholesale orders were silently never imported, and the daily webhook-drift audit was retail-only too. Fixed the scheduler to import and verify both retail and wholesale explicitly, guarded by a regression test.',
            ],
            [
                'slug' => 'shopify-import-health',
                'title' => 'Per-store Shopify import health check',
                'category' => 'reliability',
                'status' => 'shipped',
                'impact' => 'medium',
                'reference' => 'shopify:import-health',
                'changed_at' => '2026-07-06 09:30:00',
                'summary' => "Added an hourly check that inspects each storefront's last successful import and raises an auto-resolving health event when a store goes stale or never imports — catching expired tokens, broken cron, or revoked scopes before they become silent data gaps.",
            ],
            [
                'slug' => 'scheduler-heartbeat',
                'title' => 'Dead-scheduler detection (heartbeat)',
                'category' => 'reliability',
                'status' => 'shipped',
                'impact' => 'medium',
                'reference' => 'scheduler:heartbeat',
                'changed_at' => '2026-07-06 09:00:00',
                'summary' => "Closed a circular blind spot where a dead scheduler would silently stop imports AND any scheduler-based health check. A per-minute heartbeat now stamps a timestamp, and a lightweight middleware on organic traffic raises a health event if the heartbeat goes stale — powering the 'System status' widget on this dashboard.",
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ideas(): array
    {
        return [
            [
                'slug' => 'sentry-error-tracking',
                'title' => 'Add Sentry error tracking + alerting',
                'impact' => 'high',
                'effort' => 'low',
                'category' => 'reliability',
                'source' => 'System inventory · observability gap',
                'status' => 'proposed',
                'pitch' => 'There is currently no application error tracking anywhere — production errors are only visible if someone tails log files. Wire Sentry into Laravel (and later the React SPAs and mobile apps) and alert on webhook/queue/integration failures. With one tenant, you are the alarm system; that does not scale. This is the single highest-value observability upgrade and the natural next layer above the integration health events already shown here.',
            ],
            [
                'slug' => 'enforced-tenant-scope',
                'title' => 'Enforce tenant isolation (the keystone)',
                'impact' => 'high',
                'effort' => 'medium',
                'category' => 'security',
                'source' => 'Module audit · the #1 structural gap',
                'status' => 'proposed',
                'pitch' => "THE move that turns discipline into architecture. Tenant scoping is opt-in (->forTenant()) and a null tenant silently means 'all tenants'. The candle-ops surface (Orders/Shipping/Pouring/Retail/Markets) queries completely unscoped and some of its tables have no tenant_id at all. Convert HasTenantScope to an enforced global scope with an audited forAllTenants() escape hatch, and fix the Tier-1 IDOR sites. This PROTECTS the untested candle modules — it makes their single-tenant queries physically unable to reach another tenant, without redesigning them. No outside tenant's data in the shared DB until this lands.",
            ],
            [
                'slug' => 'module-entitlement-enforcement',
                'title' => 'Enforce module entitlements (module:{key} gate)',
                'impact' => 'high',
                'effort' => 'low',
                'category' => 'architecture',
                'source' => 'Module audit · every module found the same gap',
                'status' => 'proposed',
                'pitch' => 'module_catalog defines plans/modules/billing and TenantModuleAccessResolver produces a correct per-module decision — but nothing enforces it at the request layer. Every route is role-gated only; canAccess() just hides nav. Add one module:{key} route middleware (+ a @moduleEnabled Blade directive) reading the resolver decision, so a tenant only reaches modules it actually has. Highest leverage/effort ratio — the decision engine already exists; this is wiring. Also keeps the flagship candle modules invisible/inaccessible to tenants without the candle entitlement.',
            ],
            [
                'slug' => 'per-tenant-integration-layer',
                'title' => 'Standardize per-tenant integrations / OAuth',
                'impact' => 'high',
                'effort' => 'medium',
                'category' => 'architecture',
                'source' => 'Integration audit · SMS/Shopify/GBP are single-account',
                'status' => 'proposed',
                'pitch' => "There's no universal e-commerce OAuth library — the standard is Laravel Socialite (already installed) + a normalized per-tenant connections table. Today SMS/Twilio is one global account, Shopify and Google Business are single-account (Modern Forestry's), and OAuth is four copy-pasted flows. Build one integration_connections table (tenant_id, provider, encrypted tokens, refresh) behind a ProviderConnector interface, so every tenant connects their OWN Shopify/Square/QuickBooks/Google. Email/SendGrid already does this per-tenant — generalize that pattern.",
            ],
            [
                'slug' => 'standard-module-contract',
                'title' => 'Adopt the Standard Module Contract',
                'impact' => 'high',
                'effort' => 'medium',
                'category' => 'architecture',
                'source' => 'Module framework audit · ~2/3 already there',
                'status' => 'proposed',
                'pitch' => 'Make the system cohesive and modular: every module declares its nav/routes/config-schema/setup in module_catalog, owns tenant-scoped data, resolves access through the single gate, and renders through the shared module-* UI. The decision + presentation layer is already a real contract; the missing wiring is declarative nav, a per-tenant config schema, the module gate, and a thin module interface + registry. Adopt it on one reference module, then module-by-module. See docs/architecture/module-standardization-and-readiness-2026-07-07.md.',
            ],
            [
                'slug' => 'candle-ops-tenant-columns',
                'title' => 'Backfill tenant_id onto operational tables',
                'impact' => 'medium',
                'effort' => 'medium',
                'category' => 'security',
                'source' => 'Module audit · ~11 tables have no tenant_id',
                'status' => 'proposed',
                'pitch' => "The markets/events/pouring/retail tables (retail_plans, markets, market_plans, events, event_instances, pour_batches, pour_requests, pouring_measurements) have no tenant_id column at all — they're architecturally single-tenant. Add tenant_id + HasTenantScope with a backfill to tenant 1, so isolation can be enforced there too. Prerequisite before those flagship modules could ever be safely scoped; safe to stage behind the enforced global scope.",
            ],
            [
                'slug' => 'scent-alias-self-learning',
                'title' => 'Self-learning scent aliases',
                'impact' => 'high',
                'effort' => 'medium',
                'category' => 'operations',
                'source' => 'Project vision · priority #2',
                'status' => 'proposed',
                'pitch' => "The scent matcher got smarter with stopwords and acronyms, but it still does not learn. When an employee maps a messy order line to a canonical scent, write that mapping back to the scent_aliases table so the engine recognizes it automatically next time. Over weeks this drives hand-mapping toward zero — directly serving the goal of making the flagship tenant's tools effortless.",
            ],
            [
                'slug' => 'supervised-queue-worker',
                'title' => 'Move to a supervised queue worker',
                'impact' => 'medium',
                'effort' => 'medium',
                'category' => 'reliability',
                'source' => 'System inventory · throughput ceiling',
                'status' => 'proposed',
                'pitch' => "The queue is currently drained by an every-minute 'queue:work --stop-when-empty', which is fragile under load and long jobs. Now that the server has real headroom (8 GB / 4 vCPU), replace it with a supervised daemon (Forge worker, or Redis/Horizon) and keep the scheduler for cron only. Removes a reliability and throughput ceiling before more tenants arrive.",
            ],
            [
                'slug' => 'backup-restore-drill',
                'title' => 'Run a backup restore drill',
                'impact' => 'high',
                'effort' => 'low',
                'category' => 'reliability',
                'source' => 'Operator follow-up',
                'status' => 'proposed',
                'pitch' => 'Daily backups are on, but an untested backup is only a hopeful guess. Restore one backup into a throwaway database and confirm the data comes back intact. Do it once now to prove the recovery path works — you only want to discover a broken backup during a drill, never during a real incident.',
            ],
            [
                'slug' => 'env-example-repair',
                'title' => 'Repair .env.example + config doctor',
                'impact' => 'medium',
                'effort' => 'low',
                'category' => 'developer-experience',
                'source' => 'System inventory · onboarding gap',
                'status' => 'proposed',
                'pitch' => '.env.example is missing the required SHOPIFY_RETAIL_* block and SQUARE_* keys, so a fresh setup cannot configure the primary store. Reconcile .env.example with the live environment and add a config-doctor command that asserts the required keys per environment — unblocking clean onboarding and preventing production misconfiguration.',
            ],
            [
                'slug' => 'gdpr-redaction',
                'title' => 'Implement GDPR redaction workflow',
                'impact' => 'medium',
                'effort' => 'medium',
                'category' => 'compliance',
                'source' => 'System inventory · compliance gap',
                'status' => 'proposed',
                'pitch' => "Shopify privacy webhooks (customers/redact, shop/redact) are currently recorded for 'manual review' with no automated deletion. Turn those records into a real redaction workflow with an audit trail, or formally document the manual SLA — required before the app can be publicly listed on the Shopify App Store.",
            ],
            [
                'slug' => 'versioned-api-layer',
                'title' => 'Extract a versioned API layer',
                'impact' => 'medium',
                'effort' => 'high',
                'category' => 'architecture',
                'source' => 'System inventory · web.php footgun',
                'status' => 'proposed',
                'pitch' => 'All 710 routes — web pages, mobile APIs, and webhooks — live in a single 1,788-line web.php with CSRF exemptions scattered per-route. Split the mobile, webhook, and storefront JSON endpoints into a versioned API layer with consistent token/signature middleware, and retire the duplicate shopify/marketing vs /v1 routes. Reduces risk every time the routing changes.',
            ],
            [
                'slug' => 'onboard-tenant-two',
                'title' => 'Onboard tenant #2',
                'impact' => 'high',
                'effort' => 'high',
                'category' => 'platform',
                'source' => 'Project vision · north star',
                'status' => 'proposed',
                'pitch' => "The whole point of the platform is that other companies onboard as their own tenants. Once Modern Forestry's order→pour workflow is smooth and tenant isolation is enforced, take a second real business through onboarding. Nothing surfaces tenant-1-specific shortcuts ('Forestry bias') faster than a second tenant — and it is the proof the platform actually generalizes.",
            ],
        ];
    }
}
