<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\FormSubmission;
use App\Models\ReplacementEvidence;
use App\Models\ShopifyStore;
use App\Models\StockistLocation;
use App\Models\StockistLocatorSetting;
use App\Models\Tenant;
use App\Services\Replacements\OmniumLocatorImportService;
use App\Services\Replacements\ReplacementActivationService;
use App\Services\Replacements\ReplacementReadinessService;
use App\Services\Replacements\StoreifyFormImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

function replacementTenantAndStore(): array
{
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $store = ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'store_role' => 'retail',
        'shop_domain' => 'modernforestry.myshopify.com',
        'access_token' => 'test-token',
    ]);

    return [$tenant, $store];
}

it('creates store-scoped modules with every readiness gate pending', function (): void {
    [$tenant, $store] = replacementTenantAndStore();

    $modules = app(ReplacementReadinessService::class)->ensureCatalog($tenant->id);

    expect($modules)->toHaveCount(9)
        ->and($modules->pluck('shopify_store_id')->unique()->all())->toBe([$store->id])
        ->and($modules->firstWhere('module_key', 'recharge')->status)->toBe('draft')
        ->and($modules->firstWhere('module_key', 'storeify_forms')->evidence->where('required', true)->every(fn (ReplacementEvidence $e): bool => $e->status === 'pending'))->toBeTrue();
});

it('counts shared replacement savings once across retail and wholesale stores', function (): void {
    [$tenant, $retailStore] = replacementTenantAndStore();
    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'wholesale',
        'store_role' => 'wholesale',
        'shop_domain' => 's2vscq-rf.myshopify.com',
        'access_token' => 'wholesale-test-token',
    ]);

    $payload = app(ReplacementReadinessService::class)->dashboard($tenant->id, $retailStore->id);

    expect($payload['incremental_savings_per_year'])->toBe(383.76)
        ->and($payload['projected_savings_per_year'])->toBe(1859.04)
        ->and(collect($payload['modules'])->where('module_key', 'shipping'))->toHaveCount(2);
});

it('renders the retail replacement center and keeps every pending activation disabled', function (): void {
    [$tenant] = replacementTenantAndStore();
    configureEmbeddedRetailStore($tenant->id);
    $this->withoutVite();

    $this->get(route('shopify.app.replacements', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Replacement Readiness')
        ->assertSeeText('Retail forms')
        ->assertSeeText('Subscriptions')
        ->assertSeeText('Production activation release gate is off');
});

it('preserves required gate status across versioned evidence and refuses activation while the release gate is off', function (): void {
    [$tenant] = replacementTenantAndStore();
    $module = app(ReplacementReadinessService::class)->ensureCatalog($tenant->id)->firstWhere('module_key', 'storeify_forms');
    $readiness = app(ReplacementReadinessService::class);

    $readiness->recordEvidence($module, 'check', 'source_snapshot', 'passed', ['message' => 'first'], 'test');
    $second = $readiness->recordEvidence($module, 'check', 'source_snapshot', 'failed', ['message' => 'new mismatch'], 'test');

    expect($second->version)->toBe(2)->and($second->required)->toBeTrue()
        ->and($readiness->currentEvidence($module->fresh())->where('evidence_key', 'source_snapshot'))->toHaveCount(1);
    $third = $readiness->recordEvidence($module, 'check', 'source_snapshot', 'passed', ['message' => 'mismatch resolved'], 'test');
    expect($third->version)->toBe(3)->and($third->required)->toBeTrue();
    config()->set('replacement_readiness.activation_enabled', false);

    expect(fn () => app(ReplacementActivationService::class)->activate($module, new \App\Models\User, 'test', 'key'))
        ->toThrow(RuntimeException::class, 'The production replacement activation gate is off.');
});

it('imports all 338 Storeify rows idempotently without publishing forms or sending notifications', function (): void {
    [$tenant, $store] = replacementTenantAndStore();
    $root = storage_path('framework/testing/storeify-'.bin2hex(random_bytes(4)));
    mkdir($root, 0777, true);
    $files = [
        'contact-us-5367.xlsx' => [90, ['Sent', 'Your Name', 'Your Email', 'Message']],
        'forestry-weekend-16798.xlsx' => [41, ['Sent', 'First and Last name', 'Your Email', 'Type of Vendor']],
        'job-opportunity-5426.xlsx' => [108, ['Sent', 'First Name', 'Last Name', 'Email']],
        'wholesale-application-5368.xlsx' => [99, ['Sent', 'Business Name', 'First and Last name', 'Email']],
    ];
    foreach ($files as $filename => [$count, $headers]) {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        for ($i = 1; $i <= $count; $i++) {
            $sheet->fromArray(['2026-01-01 12:00:00', 'Person '.$i, 'person'.$i.'@example.test', 'Row '.$i], null, 'A'.($i + 1));
        }
        (new Xlsx($sheet->getParent()))->save($root.'/'.$filename);
    }

    $service = app(StoreifyFormImportService::class);
    $first = $service->import($tenant, $store, $root, 'test');
    $second = $service->import($tenant, $store, $root, 'test');

    expect($first['status'])->toBe('completed')
        ->and($first['imported_count'])->toBe(338)
        ->and($second['idempotent_replay'])->toBeTrue()
        ->and(FormSubmission::query()->forTenantId($tenant->id)->where('source', 'storeify_import')->count())->toBe(338)
        ->and($tenant->fresh()->shopifyStores()->whereKey($store->id)->exists())->toBeTrue();
    expect(\App\Models\TenantForm::query()->forTenantId($tenant->id)->where('channel', 'retail_storefront_candidate')->where('status', 'draft')->count())->toBe(2)
        ->and(\App\Models\TenantForm::query()->forTenantId($tenant->id)->where('channel', 'history_only')->where('status', 'archived')->count())->toBe(2);
});

it('imports 44 Omnium locations and settings as an unpublished candidate', function (): void {
    [$tenant, $store] = replacementTenantAndStore();
    $path = storage_path('framework/testing/omnium-'.bin2hex(random_bytes(4)).'.json');
    $items = [];
    for ($i = 1; $i <= 44; $i++) {
        $items[] = ['lid' => $i, 't' => 'Stockist '.$i, 'b' => '<p>'.$i.' Main St</p>', 'lt' => 34.0 + ($i / 100), 'lg' => -82.0 - ($i / 100), 'featured' => $i === 1, 'v' => true, 'filters' => []];
    }
    file_put_contents($path, json_encode(['options' => ['layout' => 'map_list', 'directions' => true, 'api_key' => 'must-not-persist'], 'items' => $items], JSON_THROW_ON_ERROR));

    $first = app(OmniumLocatorImportService::class)->import($tenant, $store, $path, 'test');
    $second = app(OmniumLocatorImportService::class)->import($tenant, $store, $path, 'test');
    $settings = StockistLocatorSetting::query()->forTenantId($tenant->id)->where('shopify_store_id', $store->id)->firstOrFail();

    expect($first['status'])->toBe('completed')
        ->and($first['imported_count'])->toBe(44)
        ->and($second['idempotent_replay'])->toBeTrue()
        ->and(StockistLocation::query()->forTenantId($tenant->id)->where('shopify_store_id', $store->id)->count())->toBe(44)
        ->and(StockistLocation::query()->forTenantId($tenant->id)->where('published', true)->count())->toBe(0)
        ->and($settings->status)->toBe('draft')
        ->and($settings->configuration['api_key'])->toBe('[RECONNECT_REQUIRED]');
});
