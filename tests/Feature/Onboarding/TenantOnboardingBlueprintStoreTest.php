<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Services\Onboarding\TenantOnboardingBlueprintStore;
use Illuminate\Http\Request;

test('blueprint store stages and finalizes demo blueprints without mutating tenant commercial state', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Demo Tenant',
        'slug' => 'demo-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);

    $request = Request::create('/onboarding?tenant=demo-tenant');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->start();

    $draft = $store->stageDraftForRequest($request, $user, [
        'rail' => 'shopify',
        'selected_modules' => ['customers'],
    ], [
        'source' => 'test',
    ]);

    expect($draft)->not->toBeNull()
        ->and($draft->status)->toBe('draft')
        ->and($draft->account_mode)->toBe('demo')
        ->and(data_get($draft->payload, 'tenant_creation_policy'))->toBe('create_fresh_production_tenant');

    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'shopify',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_sync',
        'selected_modules' => ['customers'],
        'data_source' => 'shopify',
        'mobile_intent' => [
            'needs_mobile_access' => true,
            'mobile_roles_needed' => ['owner', 'field_staff'],
            'mobile_jobs_requested' => ['customer_lookup', 'photos_uploads'],
            'mobile_priority' => 'high',
        ],
        'setup_preferences' => [
            'intake_path' => 'shopify_sync',
        ],
    ], (int) $user->id, [
        'source' => 'test',
        'flow' => 'wizard',
    ]);

    expect($final->status)->toBe('final')
        ->and($final->account_mode)->toBe('demo')
        ->and(data_get($final->payload, 'mobile_intent.needs_mobile_access'))->toBeTrue()
        ->and(data_get($final->payload, 'mobile_intent.mobile_jobs_requested'))->toContain('photos_uploads')
        ->and(data_get($final->payload, 'tenant_creation_policy'))->toBe('create_fresh_production_tenant');

    $accessProfile = $tenant->fresh(['accessProfile'])->accessProfile;
    expect($accessProfile?->metadata['account_mode'] ?? null)->toBe('demo');
});

test('production blueprints default to use_existing_tenant policy', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Prod Tenant',
        'slug' => 'prod-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [],
    ]);

    $store = app(TenantOnboardingBlueprintStore::class);

    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'law',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ]);

    expect($final->account_mode)->toBe('production')
        ->and(data_get($final->payload, 'tenant_creation_policy'))->toBe('use_existing_tenant');
});
