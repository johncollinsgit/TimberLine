<?php

use App\Jobs\ReleaseDueAutomationWorkflowRunItemsJob;
use App\Models\TenantWholesaleSetting;
use App\Services\FieldService\WorkspaceAssetService;
use App\Services\Wholesale\WholesaleSuggestionGenerator;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Square -> canonical marketing profile sync cadence.
Schedule::command('marketing:sync-square-customers')
    ->dailyAt('01:10')
    ->withoutOverlapping(240)
    ->runInBackground();

Schedule::command('marketing:sync-square-orders', [
    '--since' => '3 days ago',
])
    ->everyThirtyMinutes()
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::command('marketing:sync-square-payments', [
    '--since' => '3 days ago',
])
    ->everyThirtyMinutes()
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::command('marketing:sync-profiles', [
    '--source' => 'square',
    '--chunk' => 1000,
])
    ->hourlyAt(20)
    ->withoutOverlapping(180)
    ->runInBackground();

// Drain default queued profile/order jobs even if a daemon worker is not running.
Schedule::command('queue:work database --queue=default --stop-when-empty --tries=1 --sleep=1 --timeout=120')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

// Polling is the fallback for delayed/missed customers/merge webhooks. The
// coordinator is idempotent, so a webhook and this poller may safely overlap.
Schedule::command('marketing:reconcile-pending-customer-merges', ['--limit' => 25])
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

// Liveness heartbeat: proves the scheduler cron itself is running. Web requests check
// its freshness (see EvaluateSchedulerHeartbeat) so a stopped cron becomes detectable.
Schedule::command('scheduler:heartbeat')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// A data-free information_schema signature catches unexpected live DDL. It is
// intentionally read-only and shares the existing Forge scheduler heartbeat.
Schedule::command('schema:fingerprint')
    ->dailyAt('03:10')
    ->withoutOverlapping(20)
    ->runInBackground();

// Shopify webhook subscription drift audit for BOTH storefronts (non-destructive;
// repair is manual). Auditing wholesale too keeps its real-time order webhooks healthy.
foreach (['retail', 'wholesale'] as $shopifyStoreKey) {
    Schedule::command('shopify:webhooks:verify', [
        '--store' => $shopifyStoreKey,
    ])
        ->dailyAt('01:35')
        ->withoutOverlapping(60)
        ->runInBackground();
}

// Keep Shopify order snapshots fresh for BOTH storefronts (retail: theforestrystudio.com,
// wholesale: modernforestrywholesale.com) so their orders import automatically.
// Scheduled per-store on purpose: the no-arg import only covers active_store_keys
// (retail by default), which would silently skip wholesale entirely.
foreach (['retail', 'wholesale'] as $shopifyStoreKey) {
    Schedule::command('shopify:import-orders', [
        '--store' => $shopifyStoreKey,
        '--days' => 14,
        '--status' => 'any',
        '--limit' => 250,
    ])
        ->everyThirtyMinutes()
        ->withoutOverlapping(120)
        ->runInBackground();
}

// Surface stale/stopped Shopify order imports per store (expired token, broken cron,
// revoked scopes) as integration health alerts, and auto-clear them when imports resume.
Schedule::command('shopify:import-health', [
    '--stale-after' => 90,
])
    ->hourlyAt(15)
    ->withoutOverlapping(30)
    ->runInBackground();

// Refresh only evidence-based wholesale reorder/risk suggestions. The command
// reads through WholesaleQualifiedOrderScope, so retail and ambiguous legacy
// records cannot influence the queue.
Schedule::call(function (): void {
    $generator = app(WholesaleSuggestionGenerator::class);
    TenantWholesaleSetting::query()
        ->whereNotNull('confirmed_at')
        ->orderBy('tenant_id')
        ->pluck('tenant_id')
        ->each(fn ($tenantId) => $generator->refresh((int) $tenantId));
})
    ->name('wholesale:suggestions:refresh-active-tenants')
    ->dailyAt('06:15')
    ->withoutOverlapping(60);

// Reconcile click->order attributions after order imports and webhook drift.
Schedule::command('marketing:sync-message-order-attributions', [
    '--days' => 14,
])
    ->everyThirtyMinutes()
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::command('marketing:sync-google-business-reviews')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->runInBackground();

// Bound integration-health event volume by pruning old resolved records.
Schedule::command('integration-health:prune')
    ->dailyAt('02:20')
    ->withoutOverlapping(30)
    ->runInBackground();

// Location data is intentionally short-lived. Each tenant setting is capped at
// 30 days and this command permanently removes points past that retention limit.
Schedule::command('fleet-tracking:prune-location-points')
    ->dailyAt('02:35')
    ->withoutOverlapping(20)
    ->runInBackground();

Schedule::command('marketing:process-tenant-rewards-reminders', [
    '--limit' => 200,
])
    ->hourlyAt(10)
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::command('marketing:send-tenant-rewards-finance-reports')
    ->dailyAt('06:10')
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::command('marketing:send-modern-forestry-scent-quiz-report')
    ->weeklyOn(1, '08:15')
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::command('operator:send-weekly-snapshot')
    ->weeklyOn(1, '08:00')
    ->timezone('America/New_York')
    ->withoutOverlapping(30)
    ->runInBackground();

Schedule::command('marketing:send-modern-forestry-bag-reminders', [
    '--tenant' => 1,
    '--limit' => 100,
])
    ->hourlyAt(25)
    ->withoutOverlapping(120)
    ->runInBackground();

// Native Everbranch replacement for Happy Birthday: issue the annual reward
// at the prior 10:00 AM cadence, then send one consent-gated reminder only
// for unredeemed expiring code rewards. Each delivery is idempotent.
Schedule::command('marketing:issue-birthday-rewards', [
    '--tenant-id' => 1,
    '--limit' => 500,
])
    ->dailyAt('10:00')
    ->timezone('America/New_York')
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::command('marketing:send-birthday-followups', [
    '--tenant-id' => 1,
    '--limit' => 500,
])
    ->dailyAt('10:15')
    ->timezone('America/New_York')
    ->withoutOverlapping(120)
    ->runInBackground();

// Zap-style internal workflow automations (Asana -> Google Calendar, etc).
Schedule::command('automation:dispatch')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->runInBackground();

// Resume delayed and retryable v2 run items from their durable checkpoints.
Schedule::job(new ReleaseDueAutomationWorkflowRunItemsJob)
    ->everyMinute()
    ->withoutOverlapping(5);

// Native event payloads are encrypted but can include customer data. Mark old
// events only after every cursor-based consumer has advanced, then retain that
// acknowledgement for a short audit/recovery window before deletion.
Schedule::command('automation:prune-domain-events')
    ->dailyAt('02:40')
    ->withoutOverlapping(30)
    ->runInBackground();

// QuickBooks remains read-only. Only tenant connections explicitly enabled by
// their owner are included, and each connection also has its own cache lock.
Schedule::command('quickbooks:sync-enabled')
    ->hourlyAt(35)
    ->withoutOverlapping(55)
    ->runInBackground();

Schedule::command('quickbooks:sync-enabled', ['--full' => true])
    ->weeklyOn(0, '02:50')
    ->withoutOverlapping(240)
    ->runInBackground();

Schedule::command('field-service:send-upcoming-reminders')
    ->everyFifteenMinutes()
    ->withoutOverlapping(20)
    ->runInBackground();

Schedule::command('field-service:scan-equipment-maintenance')
    ->dailyAt('09:05')
    ->withoutOverlapping(30)
    ->runInBackground();

// Resumable document chunks are short-lived staging data. Expired sessions
// and any deterministic final file left by an interrupted promotion are safe
// to remove because completed assets use a different, durable database state.
Schedule::call(fn (): int => app(WorkspaceAssetService::class)->pruneExpiredUploads(250))
    ->name('workspace-assets:prune-expired-uploads')
    ->everyThirtyMinutes()
    ->withoutOverlapping(10);

// Accepted contract allowances reset by calendar month. Once a period closes,
// create one auditable Stripe invoice for any metered overage and link it back
// to the immutable usage periods so retries cannot bill the same usage twice.
Schedule::command('messaging:invoice-closed-usage', ['--send' => true])
    ->dailyAt('09:15')
    ->withoutOverlapping(30)
    ->runInBackground();

Schedule::command('ai:invoice-closed-usage', ['--send' => true])
    ->dailyAt('09:20')
    ->withoutOverlapping(30)
    ->runInBackground();
