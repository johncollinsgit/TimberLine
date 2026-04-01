<?php

use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;

test('global search is tenant scoped and returns mixed result types', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Search Tenant',
        'slug' => 'search-tenant',
    ]);
    $otherTenant = Tenant::query()->create([
        'name' => 'Other Search Tenant',
        'slug' => 'other-search-tenant',
    ]);

    foreach ([$tenant, $otherTenant] as $index => $currentTenant) {
        TenantAccessProfile::query()->create([
            'tenant_id' => $currentTenant->id,
            'plan_key' => 'starter',
            'operating_mode' => 'direct',
            'source' => 'test-'.$index,
        ]);
    }

    $user = User::factory()->create(['role' => 'admin']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $customer = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Jordan',
        'last_name' => 'Lake',
        'email' => 'jordan@example.test',
    ]);

    MarketingProfile::query()->create([
        'tenant_id' => $otherTenant->id,
        'first_name' => 'Jordan',
        'last_name' => 'Leak',
        'email' => 'jordan.other@example.test',
    ]);

    Order::query()->create([
        'tenant_id' => $tenant->id,
        'order_number' => 'JORD-100',
        'order_label' => 'Jordan follow-up order',
        'status' => 'paid',
        'total_price' => 89.00,
        'ordered_at' => now()->subDay(),
    ]);

    MarketingImportRun::query()->create([
        'tenant_id' => $tenant->id,
        'type' => 'customer_csv',
        'status' => 'completed',
        'source_label' => 'Jordan import',
        'file_name' => 'jordan.csv',
        'started_at' => now()->subHour(),
        'finished_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('app.search', ['q' => 'Jordan']));

    $response->assertOk()
        ->assertJsonPath('query', 'Jordan');

    $results = collect($response->json('results'));

    expect($results->pluck('type')->all())->toContain('customer', 'order', 'import')
        ->and($results->pluck('title')->all())->toContain('Jordan Lake')
        ->and($results->pluck('title')->all())->not->toContain('Jordan Leak');

    $customerResult = $results->firstWhere('type', 'customer');
    expect(data_get($customerResult, 'meta.profile_id'))->toBe($customer->id);
});

test('global search returns navigation and action suggestions for empty queries', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Suggestion Tenant',
        'slug' => 'suggestion-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create(['role' => 'marketing_manager']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $response = $this->actingAs($user)->getJson(route('app.search'));

    $response->assertOk();

    $results = collect($response->json('results'));

    expect($results->pluck('type')->all())->toContain('navigation', 'action');
});

test('global search fails closed for non marketing users on marketing entities while preserving scoped ops results', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Ops Search Tenant',
        'slug' => 'ops-search-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Morgan',
        'last_name' => 'Reed',
        'email' => 'morgan@example.test',
    ]);

    Order::query()->create([
        'tenant_id' => $tenant->id,
        'order_number' => 'OPS-100',
        'order_label' => 'Morgan order',
        'status' => 'reviewed',
        'total_price' => 44,
        'ordered_at' => now()->subDay(),
    ]);

    $user = User::factory()->create(['role' => 'manager']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $response = $this->actingAs($user)
        ->getJson(route('app.search', ['q' => 'Morgan']));

    $response->assertOk();

    $results = collect($response->json('results'));

    expect($results->pluck('type')->all())->toContain('order')
        ->and($results->pluck('type')->all())->not->toContain('customer', 'module');
});
