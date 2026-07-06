<?php

use App\Models\IntegrationHealthEvent;
use App\Models\ShopifyImportRun;
use App\Models\ShopifyStore;
use App\Services\Shopify\ShopifyImportHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function installStore(string $key): void
{
    ShopifyStore::updateOrCreate(
        ['store_key' => $key],
        [
            'shop_domain' => $key.'.myshopify.com',
            'access_token' => 'token-'.$key,
        ],
    );
}

function recordImport(string $key, ?CarbonImmutable $finishedAt): void
{
    ShopifyImportRun::create([
        'store_key' => $key,
        'is_dry_run' => false,
        'imported_count' => 0,
        'updated_count' => 0,
        'lines_count' => 0,
        'merged_lines_count' => 0,
        'mapping_exceptions_count' => 0,
        'started_at' => $finishedAt?->subMinute(),
        'finished_at' => $finishedAt,
    ]);
}

function openImportAlerts(string $key): int
{
    return IntegrationHealthEvent::query()
        ->where('provider', 'shopify')
        ->where('event_type', ShopifyImportHealthService::EVENT_TYPE)
        ->where('store_key', $key)
        ->where('status', 'open')
        ->count();
}

it('reports a recent import as healthy and raises no alert', function () {
    installStore('retail');
    recordImport('retail', CarbonImmutable::now()->subMinutes(10));

    $rows = app(ShopifyImportHealthService::class)->evaluate(90, ['retail']);

    expect(collect($rows)->firstWhere('store_key', 'retail')['status'])->toBe('healthy');
    expect(openImportAlerts('retail'))->toBe(0);
});

it('raises an alert when the last import is older than the threshold', function () {
    installStore('retail');
    recordImport('retail', CarbonImmutable::now()->subMinutes(200));

    $rows = app(ShopifyImportHealthService::class)->evaluate(90, ['retail']);

    expect(collect($rows)->firstWhere('store_key', 'retail')['status'])->toBe('stale');
    expect(openImportAlerts('retail'))->toBe(1);
});

it('raises an error alert for an installed store that has never imported', function () {
    installStore('wholesale');

    app(ShopifyImportHealthService::class)->evaluate(90, ['wholesale']);

    $event = IntegrationHealthEvent::query()
        ->where('store_key', 'wholesale')
        ->where('event_type', ShopifyImportHealthService::EVENT_TYPE)
        ->first();

    expect($event)->not->toBeNull();
    expect($event->severity)->toBe('error');
});

it('does not alert on a store that is not installed', function () {
    $rows = app(ShopifyImportHealthService::class)->evaluate(90, ['wholesale']);

    expect(collect($rows)->firstWhere('store_key', 'wholesale')['status'])->toBe('not_installed');
    expect(IntegrationHealthEvent::where('store_key', 'wholesale')->count())->toBe(0);
});

it('auto-resolves the alert once imports resume', function () {
    installStore('retail');
    recordImport('retail', CarbonImmutable::now()->subMinutes(200));
    $service = app(ShopifyImportHealthService::class);

    $service->evaluate(90, ['retail']);
    expect(openImportAlerts('retail'))->toBe(1);

    recordImport('retail', CarbonImmutable::now()->subMinutes(5));
    $service->evaluate(90, ['retail']);

    expect(openImportAlerts('retail'))->toBe(0);
    expect(IntegrationHealthEvent::where('store_key', 'retail')->where('status', 'resolved')->count())->toBe(1);
});
