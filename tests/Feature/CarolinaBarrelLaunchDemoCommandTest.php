<?php

use App\Models\MarketingProfile;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates, refreshes, and removes only the marked Carolina Barrel launch-demo records', function () {
    $carolina = Tenant::query()->create(['name' => 'Carolina Barrel Co.', 'slug' => 'carolina-barrel-co']);
    $otherTenant = Tenant::query()->create(['name' => 'Another Tenant', 'slug' => 'another-tenant']);

    $realOrder = Order::query()->create([
        'tenant_id' => $carolina->id,
        'source' => 'manual',
        'order_number' => 'REAL-1001',
        'customer_name' => 'Real Customer',
        'ordered_at' => now(),
        'status' => 'complete',
        'total_price' => 100,
    ]);
    $otherTenantDemoOrder = Order::query()->create([
        'tenant_id' => $otherTenant->id,
        'source' => 'manual',
        'order_number' => 'CBC-DEMO-OUTSIDE-SCOPE',
        'ordered_at' => now(),
        'status' => 'complete',
        'total_price' => 100,
    ]);

    $this->artisan('everbranch:prepare-carolina-barrel-launch-demo', [
        'tenant' => 'carolina-barrel-co',
        '--mode' => 'seed',
        '--apply' => true,
        '--confirm' => 'CAROLINA-BARREL-DEMO',
    ])->assertSuccessful();

    expect(MarketingProfile::query()->forTenantId($carolina->id)->where('normalized_email', 'like', '%@demo.carolinabarrel.invalid')->count())->toBe(6);
    expect(Order::query()->forTenantId($carolina->id)->where('order_number', 'like', 'CBC-DEMO-%')->count())->toBe(10);
    expect(MarketingStorefrontEvent::query()->forTenantId($carolina->id)->where('source_id', 'like', 'session_started:carolina-barrel-demo-%')->count())->toBe(72);

    $this->artisan('everbranch:prepare-carolina-barrel-launch-demo', [
        'tenant' => 'carolina-barrel-co',
        '--mode' => 'refresh',
        '--apply' => true,
        '--confirm' => 'CAROLINA-BARREL-DEMO',
    ])->assertSuccessful();

    expect(Order::query()->forTenantId($carolina->id)->where('order_number', 'like', 'CBC-DEMO-%')->count())->toBe(10);
    expect(Order::query()->find($realOrder->id))->not->toBeNull();

    $this->artisan('everbranch:prepare-carolina-barrel-launch-demo', [
        'tenant' => 'carolina-barrel-co',
        '--mode' => 'remove',
        '--apply' => true,
        '--confirm' => 'CAROLINA-BARREL-DEMO',
    ])->assertSuccessful();

    expect(MarketingProfile::query()->forTenantId($carolina->id)->where('normalized_email', 'like', '%@demo.carolinabarrel.invalid')->count())->toBe(0);
    expect(Order::query()->forTenantId($carolina->id)->where('order_number', 'like', 'CBC-DEMO-%')->count())->toBe(0);
    expect(MarketingStorefrontEvent::query()->forTenantId($carolina->id)->where('source_id', 'like', 'session_started:carolina-barrel-demo-%')->count())->toBe(0);
    expect(Order::query()->find($realOrder->id))->not->toBeNull();
    expect(Order::query()->find($otherTenantDemoOrder->id))->not->toBeNull();
});

it('refuses to write the fixture without the exact confirmation', function () {
    Tenant::query()->create(['name' => 'Carolina Barrel Co.', 'slug' => 'carolina-barrel-co']);

    $this->artisan('everbranch:prepare-carolina-barrel-launch-demo', [
        'tenant' => 'carolina-barrel-co',
        '--mode' => 'seed',
        '--apply' => true,
        '--confirm' => 'not-the-confirmation',
    ])->assertFailed();

    expect(Order::query()->where('order_number', 'like', 'CBC-DEMO-%')->count())->toBe(0);
});

it('reports the fixture plan without writing by default', function () {
    Tenant::query()->create(['name' => 'Carolina Barrel Co.', 'slug' => 'carolina-barrel-co']);

    $this->artisan('everbranch:prepare-carolina-barrel-launch-demo', [
        'tenant' => 'carolina-barrel-co',
        '--mode' => 'refresh',
    ])->expectsOutputToContain('No records were changed.')->assertSuccessful();

    expect(Order::query()->where('order_number', 'like', 'CBC-DEMO-%')->count())->toBe(0);
});
