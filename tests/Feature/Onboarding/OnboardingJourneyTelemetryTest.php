<?php

use App\Models\MarketingProfile;
use App\Models\ShopifyImportRun;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantOnboardingJourneyEvent;
use App\Models\User;
use App\Services\Onboarding\OnboardingJourneyTelemetryService;
use App\Services\Onboarding\TenantOnboardingBlueprintStore;
use App\Services\Tenancy\TenantCommercialExperienceService;

require_once __DIR__ . '/../ShopifyEmbeddedTestHelpers.php';

test('first open acknowledgment emits telemetry exactly once (idempotent)', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);
    config()->set('features.onboarding_journey_telemetry', true);

    $sourceTenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $sourceTenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => ['account_mode' => 'demo'],
    ]);

    $user = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $user->tenants()->syncWithoutDetaching([(int) $sourceTenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $sourceTenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => ['needs_mobile_access' => false],
    ], (int) $user->id);

    $provision = $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk()
        ->json();

    $provisionedTenantId = (int) data_get($provision, 'result.provisioned_tenant.id');
    expect($provisionedTenantId)->toBeGreaterThan(0);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.acknowledge-first-open', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
            'payload_anchor' => 'merchant_journey',
            'opened_path' => '/dashboard',
        ])
        ->assertOk()
        ->assertJsonPath('already_acknowledged', false);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.acknowledge-first-open', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
            'payload_anchor' => 'merchant_journey',
            'opened_path' => '/dashboard',
        ])
        ->assertOk()
        ->assertJsonPath('already_acknowledged', true);

    expect(TenantOnboardingJourneyEvent::query()
        ->where('tenant_id', $provisionedTenantId)
        ->where('event_key', OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK)
        ->count())->toBe(1);
});

test('recommended phase telemetry emits only when phase changes', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);
    config()->set('features.onboarding_journey_telemetry', true);

    $sourceTenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $sourceTenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => ['account_mode' => 'demo'],
    ]);

    $user = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $user->tenants()->syncWithoutDetaching([(int) $sourceTenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $sourceTenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => ['needs_mobile_access' => false],
    ], (int) $user->id);

    $provision = $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk()
        ->json();

    $provisionedTenantId = (int) data_get($provision, 'result.provisioned_tenant.id');
    $experience = app(TenantCommercialExperienceService::class);

    // Initial observation records phase once.
    $experience->merchantJourneyPayload($provisionedTenantId);
    $experience->merchantJourneyPayload($provisionedTenantId);

    expect(TenantOnboardingJourneyEvent::query()
        ->where('tenant_id', $provisionedTenantId)
        ->where('event_key', OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED)
        ->count())->toBe(1);

    // Acknowledge first open -> phase changes from handoff -> ongoing_setup; should emit one more.
    $this->actingAs($user)
        ->postJson(route('onboarding.api.acknowledge-first-open', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
            'payload_anchor' => 'merchant_journey',
            'opened_path' => '/dashboard',
        ])
        ->assertOk();

    $experience->merchantJourneyPayload($provisionedTenantId);
    $experience->merchantJourneyPayload($provisionedTenantId);

    expect(TenantOnboardingJourneyEvent::query()
        ->where('tenant_id', $provisionedTenantId)
        ->where('event_key', OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED)
        ->count())->toBe(2);
});

test('import started/completed telemetry is idempotent and derived from canonical import summary', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);
    config()->set('features.onboarding_journey_telemetry', true);

    $sourceTenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $sourceTenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
        'metadata' => ['account_mode' => 'demo'],
    ]);

    $user = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $user->tenants()->syncWithoutDetaching([(int) $sourceTenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $sourceTenant->id, [
        'rail' => 'shopify',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_sync',
        'selected_modules' => ['customers'],
        'data_source' => 'shopify',
        'mobile_intent' => ['needs_mobile_access' => true],
    ], (int) $user->id);

    $provision = $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk()
        ->json();

    $provisionedTenantId = (int) data_get($provision, 'result.provisioned_tenant.id');
    expect($provisionedTenantId)->toBeGreaterThan(0);

    // Ensure a Shopify store key is associated with the provisioned tenant for shopify_import_runs.
    configureEmbeddedRetailStore($provisionedTenantId);

    ShopifyImportRun::query()->create([
        'store_key' => 'retail',
        'source' => 'test',
        'is_dry_run' => false,
        'imported_count' => 0,
        'updated_count' => 0,
        'lines_count' => 0,
        'merged_lines_count' => 0,
        'mapping_exceptions_count' => 0,
        'started_at' => now(),
        'finished_at' => null,
    ]);

    $experience = app(TenantCommercialExperienceService::class);
    $experience->forgetTenantCache($provisionedTenantId);
    $experience->merchantJourneyPayload($provisionedTenantId);
    $experience->merchantJourneyPayload($provisionedTenantId);

    expect(TenantOnboardingJourneyEvent::query()
        ->where('tenant_id', $provisionedTenantId)
        ->where('event_key', OnboardingJourneyTelemetryService::EVENT_IMPORT_STARTED)
        ->count())->toBe(1);

    // Completing import is derived from having profiles (canonical importSummary logic).
    MarketingProfile::factory()->create(['tenant_id' => $provisionedTenantId]);
    $experience->forgetTenantCache($provisionedTenantId);
    $experience->merchantJourneyPayload($provisionedTenantId);
    $experience->merchantJourneyPayload($provisionedTenantId);

    expect(TenantOnboardingJourneyEvent::query()
        ->where('tenant_id', $provisionedTenantId)
        ->where('event_key', OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED)
        ->count())->toBe(1);
});

test('first active module reached telemetry emits once when a non-default-enabled module becomes active', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);
    config()->set('features.onboarding_journey_telemetry', true);
    // Make the provisioned tenant start on growth so rewards becomes active (default_enabled=false).
    config()->set('entitlements.default_plan', 'growth');

    $sourceTenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $sourceTenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => ['account_mode' => 'demo'],
    ]);

    $user = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $user->tenants()->syncWithoutDetaching([(int) $sourceTenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $sourceTenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers', 'rewards'],
        'data_source' => 'csv',
        'mobile_intent' => ['needs_mobile_access' => false],
    ], (int) $user->id);

    $provision = $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk()
        ->json();

    $provisionedTenantId = (int) data_get($provision, 'result.provisioned_tenant.id');
    $experience = app(TenantCommercialExperienceService::class);

    $experience->merchantJourneyPayload($provisionedTenantId);
    $experience->merchantJourneyPayload($provisionedTenantId);

    $event = TenantOnboardingJourneyEvent::query()
        ->where('tenant_id', $provisionedTenantId)
        ->where('event_key', OnboardingJourneyTelemetryService::EVENT_FIRST_ACTIVE_MODULE)
        ->first();

    expect($event)->not->toBeNull()
        ->and((array) ($event?->payload ?? []))->toHaveKey('active_module_keys')
        ->and((array) data_get($event?->payload, 'active_module_keys', []))->toContain('rewards');

    // Idempotent: no duplicates on repeated payload calls.
    expect(TenantOnboardingJourneyEvent::query()
        ->where('tenant_id', $provisionedTenantId)
        ->where('event_key', OnboardingJourneyTelemetryService::EVENT_FIRST_ACTIVE_MODULE)
        ->count())->toBe(1);
});

