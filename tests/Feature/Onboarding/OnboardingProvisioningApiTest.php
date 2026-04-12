<?php

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantOnboardingBlueprint;
use App\Models\User;
use App\Services\Onboarding\TenantOnboardingBlueprintStore;
use App\Services\Tenancy\TenantCommercialExperienceService;

test('provision production tenant endpoint requires authentication', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $this->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
        'final_blueprint_id' => 1,
    ])->assertStatus(401);
});

test('provision production tenant endpoint requires tenant access', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenantA = Tenant::query()->create(['name' => 'Demo Tenant A', 'slug' => 'demo-a']);
    $tenantB = Tenant::query()->create(['name' => 'Demo Tenant B', 'slug' => 'demo-b']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenantA->id => ['role' => 'admin']]);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-b']), [
            'final_blueprint_id' => 1,
        ])
        ->assertStatus(403);
});

test('provisioning consumes a finalized demo blueprint and creates a fresh production tenant without copying demo data', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $sourceTenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $sourceTenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $sourceTenant->id => ['role' => 'admin']]);

    // Seed some operational/demo data in the demo tenant to ensure it is NOT copied.
    MarketingProfile::factory()->create([
        'tenant_id' => (int) $sourceTenant->id,
    ]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $sourceTenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => true,
            'mobile_roles_needed' => ['owner'],
            'mobile_jobs_requested' => ['customer_lookup'],
        ],
    ], (int) $user->id, [
        'source' => 'test',
        'flow' => 'wizard',
    ]);

    expect($final->status)->toBe('final')
        ->and($final->account_mode)->toBe('demo');

    $beforeTenantCount = Tenant::query()->count();

    $response = $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk()
        ->json();

    $newTenantId = (int) data_get($response, 'result.provisioned_tenant.id');

    expect($newTenantId)->toBeGreaterThan(0)
        ->and($newTenantId)->not->toBe((int) $sourceTenant->id)
        ->and(Tenant::query()->count())->toBe($beforeTenantCount + 1)
        ->and(data_get($response, 'result.provisioned_tenant.account_mode'))->toBe('production')
        ->and(data_get($response, 'result.source_blueprint.id'))->toBe((int) $final->id)
        ->and(data_get($response, 'result.lineage.source_blueprint_id'))->toBe((int) $final->id)
        ->and(data_get($response, 'result.lineage.no_demo_data_migrated'))->toBeTrue();

    $newTenant = Tenant::query()->with(['accessProfile'])->findOrFail($newTenantId);
    expect($newTenant->accessProfile?->operating_mode)->toBe('direct')
        ->and(data_get($newTenant->accessProfile?->metadata, 'account_mode'))->toBe('production')
        ->and((int) data_get($newTenant->accessProfile?->metadata, 'onboarding.provisioned_from.source_blueprint_id'))->toBe((int) $final->id)
        ->and(data_get($newTenant->accessProfile?->metadata, 'onboarding.template_key'))->toBe('candle')
        ->and(data_get($newTenant->accessProfile?->metadata, 'onboarding.selected_modules'))->toContain('customers');

    // Ensure the source demo tenant did not have its account mode flipped.
    $sourceAccessProfile = $sourceTenant->fresh(['accessProfile'])->accessProfile;
    expect($sourceAccessProfile?->metadata['account_mode'] ?? null)->toBe('demo');

    // Ensure operational/demo data is NOT copied into the provisioned tenant.
    expect(MarketingProfile::query()->where('tenant_id', $newTenantId)->count())->toBe(0);
});

test('provisioning rejects non-final blueprints', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $draft = TenantOnboardingBlueprint::query()->create([
        'tenant_id' => (int) $tenant->id,
        'created_by_user_id' => (int) $user->id,
        'status' => 'draft',
        'account_mode' => 'demo',
        'rail' => 'direct',
        'blueprint_version' => 1,
        'payload' => [
            'rail' => 'direct',
            'template_key' => 'candle',
        ],
        'origin' => [
            'revision' => 1,
        ],
    ]);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $draft->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.final_blueprint_id.0', 'Blueprint must be finalized before it can be consumed.');
});

test('repeated provisioning from the same finalized blueprint is blocked by policy', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $sourceTenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $sourceTenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $sourceTenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $sourceTenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk();

    $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.blueprint.0', 'This finalized blueprint has already been consumed to provision a production tenant.');
});

test('provisioning status endpoint requires authentication', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $this->getJson(route('onboarding.api.blueprint.provisioning-status', ['tenant' => 'demo-tenant', 'final_blueprint_id' => 1]))
        ->assertStatus(401);
});

test('provisioning status endpoint returns not_provisioned for a final blueprint with no provisioning row and does not create side effects', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $beforeProvisionings = \App\Models\TenantOnboardingBlueprintProvisioning::query()->count();

    $response = $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.provisioning-status', [
            'tenant' => 'demo-tenant',
            'final_blueprint_id' => (int) $final->id,
        ]))
        ->assertOk()
        ->json();

    expect(data_get($response, 'final_blueprint_id'))->toBe((int) $final->id)
        ->and(data_get($response, 'status'))->toBe('not_provisioned')
        ->and(data_get($response, 'provisioned_tenant'))->toBeNull();

    expect(\App\Models\TenantOnboardingBlueprintProvisioning::query()->count())->toBe($beforeProvisionings);
});

test('provisioning status endpoint returns provisioned and includes provisioned tenant info after provisioning', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk();

    $response = $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.provisioning-status', [
            'tenant' => 'demo-tenant',
            'final_blueprint_id' => (int) $final->id,
        ]))
        ->assertOk()
        ->json();

    expect(data_get($response, 'status'))->toBe('provisioned')
        ->and(data_get($response, 'provisioned_tenant.id'))->toBeGreaterThan(0)
        ->and(data_get($response, 'provisioned_tenant.slug'))->not->toBeNull()
        ->and(data_get($response, 'provisioned_tenant.provisioned_at'))->not->toBeNull()
        ->and(data_get($response, 'policy.key'))->not->toBeNull();
});

test('provisioning status endpoint rejects draft blueprints', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $draft = TenantOnboardingBlueprint::query()->create([
        'tenant_id' => (int) $tenant->id,
        'created_by_user_id' => (int) $user->id,
        'status' => 'draft',
        'account_mode' => 'demo',
        'rail' => 'direct',
        'blueprint_version' => 1,
        'payload' => [
            'rail' => 'direct',
            'template_key' => 'candle',
        ],
        'origin' => [
            'revision' => 1,
        ],
    ]);

    $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.provisioning-status', [
            'tenant' => 'demo-tenant',
            'final_blueprint_id' => (int) $draft->id,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.final_blueprint_id.0', 'Blueprint must be finalized to query provisioning status.');
});

test('provisioning status endpoint denies cross-tenant blueprint lookup', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenantA = Tenant::query()->create(['name' => 'Demo Tenant A', 'slug' => 'demo-a']);
    $tenantB = Tenant::query()->create(['name' => 'Demo Tenant B', 'slug' => 'demo-b']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenantA->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $finalB = $store->finalize((int) $tenantB->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.provisioning-status', [
            'tenant' => 'demo-a',
            'final_blueprint_id' => (int) $finalB->id,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.final_blueprint_id.0', 'Unknown blueprint id for this tenant.');
});

test('provisioning handoff endpoint requires authentication', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $this->getJson(route('onboarding.api.blueprint.provisioning-handoff', ['tenant' => 'demo-tenant', 'final_blueprint_id' => 1]))
        ->assertStatus(401);
});

test('provisioning handoff returns not_provisioned with null route when no provisioning exists', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $beforeProvisionings = \App\Models\TenantOnboardingBlueprintProvisioning::query()->count();

    $response = $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.provisioning-handoff', [
            'tenant' => 'demo-tenant',
            'final_blueprint_id' => (int) $final->id,
        ]))
        ->assertOk()
        ->json();

    expect(data_get($response, 'status'))->toBe('not_provisioned')
        ->and(data_get($response, 'handoff.route_name'))->toBeNull()
        ->and(data_get($response, 'handoff.path'))->toBeNull();

    expect(\App\Models\TenantOnboardingBlueprintProvisioning::query()->count())->toBe($beforeProvisionings);
});

test('provisioning handoff returns canonical embedded Start Here for provisioned Shopify tenants', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
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
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'shopify',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_sync',
        'selected_modules' => ['customers'],
        'data_source' => 'shopify',
        'mobile_intent' => [
            'needs_mobile_access' => true,
            'mobile_roles_needed' => ['owner'],
            'mobile_jobs_requested' => ['alerts_notifications'],
        ],
    ], (int) $user->id);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk();

    $response = $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.provisioning-handoff', [
            'tenant' => 'demo-tenant',
            'final_blueprint_id' => (int) $final->id,
        ]))
        ->assertOk()
        ->json();

    expect(data_get($response, 'status'))->toBe('provisioned')
        ->and(data_get($response, 'handoff.route_name'))->toBe('shopify.app.start')
        ->and(data_get($response, 'handoff.path'))->toContain('/shopify/app/start')
        ->and(data_get($response, 'handoff.payload_anchor'))->toBe('onboarding');
});

test('provisioning handoff returns dashboard landing for provisioned direct tenants and does not invent routes', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk();

    $response = $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.provisioning-handoff', [
            'tenant' => 'demo-tenant',
            'final_blueprint_id' => (int) $final->id,
        ]))
        ->assertOk()
        ->json();

    expect(data_get($response, 'handoff.route_name'))->toBe('dashboard')
        ->and(data_get($response, 'handoff.path'))->toContain('/dashboard')
        ->and(data_get($response, 'handoff.payload_anchor'))->toBe('merchant_journey');
});

test('provisioning handoff denies cross-tenant blueprint access', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenantA = Tenant::query()->create(['name' => 'Demo Tenant A', 'slug' => 'demo-a']);
    $tenantB = Tenant::query()->create(['name' => 'Demo Tenant B', 'slug' => 'demo-b']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenantA->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $finalB = $store->finalize((int) $tenantB->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.provisioning-handoff', [
            'tenant' => 'demo-a',
            'final_blueprint_id' => (int) $finalB->id,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.final_blueprint_id.0', 'Unknown blueprint id for this tenant.');
});

test('provisioning handoff payload endpoint requires authentication', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $this->getJson(route('onboarding.api.blueprint.provisioning-handoff-payload', [
        'tenant' => 'demo-tenant',
        'final_blueprint_id' => 1,
    ]))->assertStatus(401);
});

test('provisioning handoff payload returns null payload when not provisioned and does not create side effects', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $beforeProvisionings = \App\Models\TenantOnboardingBlueprintProvisioning::query()->count();

    $response = $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.provisioning-handoff-payload', [
            'tenant' => 'demo-tenant',
            'final_blueprint_id' => (int) $final->id,
        ]))
        ->assertOk()
        ->json();

    expect(data_get($response, 'status'))->toBe('not_provisioned')
        ->and(data_get($response, 'payload'))->toBeNull();

    expect(\App\Models\TenantOnboardingBlueprintProvisioning::query()->count())->toBe($beforeProvisionings);
});

test('provisioning handoff payload returns a payload for the provisioned tenant and respects payload_anchor override', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $provision = $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk()
        ->json();

    $newTenantId = (int) data_get($provision, 'result.provisioned_tenant.id');
    expect($newTenantId)->toBeGreaterThan(0);

    $defaultAnchorResponse = $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.provisioning-handoff-payload', [
            'tenant' => 'demo-tenant',
            'final_blueprint_id' => (int) $final->id,
        ]))
        ->assertOk()
        ->json();

    expect(data_get($defaultAnchorResponse, 'status'))->toBe('provisioned')
        ->and((int) data_get($defaultAnchorResponse, 'provisioned_tenant_id'))->toBe($newTenantId)
        ->and(data_get($defaultAnchorResponse, 'payload_anchor'))->toBe('merchant_journey')
        ->and((int) data_get($defaultAnchorResponse, 'payload.tenant_id'))->toBe($newTenantId);

    $onboardingAnchorResponse = $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.provisioning-handoff-payload', [
            'tenant' => 'demo-tenant',
            'final_blueprint_id' => (int) $final->id,
            'payload_anchor' => 'onboarding',
        ]))
        ->assertOk()
        ->json();

    expect(data_get($onboardingAnchorResponse, 'payload_anchor'))->toBe('onboarding')
        ->and((int) data_get($onboardingAnchorResponse, 'payload.tenant_id'))->toBe($newTenantId)
        ->and(data_get($onboardingAnchorResponse, 'payload.content'))->not->toBeNull();
});

test('provisioning open-context endpoint requires authentication', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $this->getJson(route('onboarding.api.blueprint.provisioning-open-context', [
        'tenant' => 'demo-tenant',
        'final_blueprint_id' => 1,
    ]))->assertStatus(401);
});

test('provisioning open-context returns not_provisioned with null open_context when no provisioning exists', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $beforeProvisionings = \App\Models\TenantOnboardingBlueprintProvisioning::query()->count();

    $response = $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.provisioning-open-context', [
            'tenant' => 'demo-tenant',
            'final_blueprint_id' => (int) $final->id,
        ]))
        ->assertOk()
        ->json();

    expect(data_get($response, 'status'))->toBe('not_provisioned')
        ->and(data_get($response, 'open_context'))->toBeNull()
        ->and((int) data_get($response, 'final_blueprint_id'))->toBe((int) $final->id);

    expect(\App\Models\TenantOnboardingBlueprintProvisioning::query()->count())->toBe($beforeProvisionings);
});

test('provisioning open-context returns a usable provisioned tenant context for direct provisioning without switching session', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $provision = $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk()
        ->json();

    $newTenantId = (int) data_get($provision, 'result.provisioned_tenant.id');
    $newTenantSlug = (string) data_get($provision, 'result.provisioned_tenant.slug');

    $response = $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.provisioning-open-context', [
            'tenant' => 'demo-tenant',
            'final_blueprint_id' => (int) $final->id,
        ]))
        ->assertOk()
        ->json();

    expect(data_get($response, 'status'))->toBe('provisioned')
        ->and((int) data_get($response, 'source_tenant_id'))->toBe((int) $tenant->id)
        ->and((int) data_get($response, 'provisioned_tenant_id'))->toBe($newTenantId)
        ->and((int) data_get($response, 'open_context.tenant_id'))->toBe($newTenantId)
        ->and((string) data_get($response, 'open_context.tenant_slug'))->toBe($newTenantSlug)
        ->and(data_get($response, 'open_context.switch_parameters.query.tenant'))->toBe($newTenantSlug)
        ->and(data_get($response, 'open_context.switch_parameters.headers.X-Tenant'))->toBe($newTenantSlug)
        ->and((bool) data_get($response, 'open_context.requires_switch'))->toBeTrue()
        ->and(data_get($response, 'open_context.open_mode'))->toBe('direct_web')
        ->and(data_get($response, 'open_context.first_screen_hint.route_name'))->toBe('dashboard');

    $recommended = (array) data_get($response, 'open_context.recommended_next_requests', []);
    expect($recommended)->not->toBeEmpty()
        ->and((string) data_get($recommended[0] ?? [], 'route_name'))->toBe('onboarding.api.blueprint.provisioning-status')
        ->and((string) data_get($recommended[0] ?? [], 'tenant_context'))->toBe('source_tenant');
});

test('provisioning open-context includes embedded context query values for provisioned Shopify tenants', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
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
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'shopify',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_sync',
        'selected_modules' => ['customers'],
        'data_source' => 'shopify',
        'mobile_intent' => [
            'needs_mobile_access' => true,
            'mobile_roles_needed' => ['owner'],
            'mobile_jobs_requested' => ['alerts_notifications'],
        ],
    ], (int) $user->id);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk();

    $url = route('onboarding.api.blueprint.provisioning-open-context', [
        'tenant' => 'demo-tenant',
        'final_blueprint_id' => (int) $final->id,
    ]);
    $url .= '&host=example-host&shop=example.myshopify.com&embedded=1';

    $response = $this->actingAs($user)
        ->getJson($url)
        ->assertOk()
        ->json();

    expect(data_get($response, 'status'))->toBe('provisioned')
        ->and(data_get($response, 'open_context.open_mode'))->toBe('shopify_embedded')
        ->and(data_get($response, 'open_context.embedded_context_query.host'))->toBe('example-host')
        ->and(data_get($response, 'open_context.embedded_context_query.shop'))->toBe('example.myshopify.com');
});

test('post-provisioning summary endpoint requires authentication', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $this->getJson(route('onboarding.api.blueprint.post-provisioning-summary', [
        'tenant' => 'demo-tenant',
        'final_blueprint_id' => 1,
    ]))->assertStatus(401);
});

test('post-provisioning summary returns coherent not_provisioned shape with null payload/open_context and no side effects', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $beforeProvisionings = \App\Models\TenantOnboardingBlueprintProvisioning::query()->count();

    $response = $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.post-provisioning-summary', [
            'tenant' => 'demo-tenant',
            'final_blueprint_id' => (int) $final->id,
        ]))
        ->assertOk()
        ->json();

    expect(data_get($response, 'status'))->toBe('not_provisioned')
        ->and((bool) data_get($response, 'summary.is_provisioned'))->toBeFalse()
        ->and((bool) data_get($response, 'summary.ready_for_open'))->toBeFalse()
        ->and(data_get($response, 'payload'))->toBeNull()
        ->and(data_get($response, 'open_context'))->toBeNull()
        ->and(data_get($response, 'handoff.status'))->toBe('not_provisioned')
        ->and(data_get($response, 'provisioning_status.status'))->toBe('not_provisioned');

    expect(\App\Models\TenantOnboardingBlueprintProvisioning::query()->count())->toBe($beforeProvisionings);
});

test('post-provisioning summary returns provisioned handoff + open_context + payload for provisioned tenant (not source) and is read-only', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $provision = $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk()
        ->json();

    $newTenantId = (int) data_get($provision, 'result.provisioned_tenant.id');
    $beforeProvisionings = \App\Models\TenantOnboardingBlueprintProvisioning::query()->count();

    $response = $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.post-provisioning-summary', [
            'tenant' => 'demo-tenant',
            'final_blueprint_id' => (int) $final->id,
        ]))
        ->assertOk()
        ->json();

    expect(data_get($response, 'status'))->toBe('provisioned')
        ->and((bool) data_get($response, 'summary.is_provisioned'))->toBeTrue()
        ->and((bool) data_get($response, 'summary.ready_for_open'))->toBeTrue()
        ->and((int) data_get($response, 'provisioned_tenant_id'))->toBe($newTenantId)
        ->and((int) data_get($response, 'open_context.tenant_id'))->toBe($newTenantId)
        ->and((int) data_get($response, 'payload.tenant_id'))->toBe($newTenantId)
        ->and(data_get($response, 'summary.recommended_first_screen.route_name'))->toBe('dashboard');

    // Read-only: summary endpoint must not create new provisioning rows.
    expect(\App\Models\TenantOnboardingBlueprintProvisioning::query()->count())->toBe($beforeProvisionings);
});

test('acknowledge-first-open endpoint records first_opened_at exactly once (idempotent) and updates summary fields', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk();

    $first = $this->actingAs($user)
        ->postJson(route('onboarding.api.acknowledge-first-open', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
            'payload_anchor' => 'merchant_journey',
            'opened_path' => '/dashboard',
        ])
        ->assertOk()
        ->json();

    expect((bool) data_get($first, 'acknowledged'))->toBeTrue()
        ->and((bool) data_get($first, 'already_acknowledged'))->toBeFalse()
        ->and(data_get($first, 'first_opened_at'))->not->toBeNull()
        ->and(data_get($first, 'payload_anchor'))->toBe('merchant_journey')
        ->and(data_get($first, 'opened_path'))->toBe('/dashboard')
        ->and((int) data_get($first, 'acknowledged_by_user_id'))->toBe((int) $user->id);

    $second = $this->actingAs($user)
        ->postJson(route('onboarding.api.acknowledge-first-open', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
            'payload_anchor' => 'merchant_journey',
            'opened_path' => '/dashboard',
        ])
        ->assertOk()
        ->json();

    expect((bool) data_get($second, 'already_acknowledged'))->toBeTrue()
        ->and((string) data_get($second, 'first_opened_at'))->toBe((string) data_get($first, 'first_opened_at'));

    $summary = $this->actingAs($user)
        ->getJson(route('onboarding.api.blueprint.post-provisioning-summary', [
            'tenant' => 'demo-tenant',
            'final_blueprint_id' => (int) $final->id,
        ]))
        ->assertOk()
        ->json();

    expect((bool) data_get($summary, 'summary.first_open_acknowledged'))->toBeTrue()
        ->and((string) data_get($summary, 'summary.first_opened_at'))->toBe((string) data_get($first, 'first_opened_at'));
});

test('acknowledge-first-open rejects non-provisioned final blueprints', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.acknowledge-first-open', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
            'payload_anchor' => 'merchant_journey',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.final_blueprint_id.0', 'Blueprint has not provisioned a production tenant yet.');
});

test('acknowledge-first-open rejects invalid payload_anchor values', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.acknowledge-first-open', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
            'payload_anchor' => 'not_real',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.payload_anchor.0', 'The selected payload anchor is invalid.');
});

test('acknowledge-first-open is gated by internal provisioning feature flag', function (): void {
    config()->set('features.internal_onboarding_provisioning', false);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.acknowledge-first-open', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => 1,
        ])
        ->assertStatus(404);
});

test('acknowledge-first-open denies non-creator access', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $creator = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $creator->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $other = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $other->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $tenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $creator->id);

    $this->actingAs($creator)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk();

    $this->actingAs($other)
        ->postJson(route('onboarding.api.acknowledge-first-open', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
            'payload_anchor' => 'merchant_journey',
        ])
        ->assertStatus(403);
});

test('acknowledge-first-open denies cross-tenant blueprint ids', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $tenantA = Tenant::query()->create(['name' => 'Demo Tenant A', 'slug' => 'demo-a']);
    $tenantB = Tenant::query()->create(['name' => 'Demo Tenant B', 'slug' => 'demo-b']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenantA->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $finalB = $store->finalize((int) $tenantB->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.acknowledge-first-open', ['tenant' => 'demo-a']), [
            'final_blueprint_id' => (int) $finalB->id,
            'payload_anchor' => 'merchant_journey',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.final_blueprint_id.0', 'Unknown blueprint id for this tenant.');
});

test('merchant journey and onboarding payloads expose first-open meta for provisioned tenants and react after acknowledgment', function (): void {
    config()->set('features.internal_onboarding_provisioning', true);

    $sourceTenant = Tenant::query()->create(['name' => 'Demo Tenant', 'slug' => 'demo-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $sourceTenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $sourceTenant->id => ['role' => 'admin']]);

    $store = app(TenantOnboardingBlueprintStore::class);
    $final = $store->finalize((int) $sourceTenant->id, [
        'rail' => 'direct',
        'template_key' => 'candle',
        'desired_outcome_first' => 'first_value',
        'selected_modules' => ['customers'],
        'data_source' => 'csv',
        'mobile_intent' => [
            'needs_mobile_access' => false,
        ],
    ], (int) $user->id);

    $provision = $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.provision-production', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
        ])
        ->assertOk()
        ->json();

    $provisionedTenantId = (int) data_get($provision, 'result.provisioned_tenant.id');
    expect($provisionedTenantId)->toBeGreaterThan(0);

    $experience = app(TenantCommercialExperienceService::class);

    $beforeOnboarding = $experience->onboardingPayload($provisionedTenantId);
    $beforeJourney = $experience->merchantJourneyPayload($provisionedTenantId);

    expect((bool) data_get($beforeOnboarding, 'onboarding.first_open_acknowledged'))->toBeFalse()
        ->and((bool) data_get($beforeOnboarding, 'onboarding.is_first_touch'))->toBeTrue()
        ->and((string) data_get($beforeOnboarding, 'onboarding.recommended_phase'))->toBe('handoff');

    expect((bool) data_get($beforeJourney, 'onboarding.first_open_acknowledged'))->toBeFalse()
        ->and((bool) data_get($beforeJourney, 'onboarding.is_first_touch'))->toBeTrue()
        ->and((string) data_get($beforeJourney, 'onboarding.recommended_phase'))->toBe('handoff');

    $ack = $this->actingAs($user)
        ->postJson(route('onboarding.api.acknowledge-first-open', ['tenant' => 'demo-tenant']), [
            'final_blueprint_id' => (int) $final->id,
            'payload_anchor' => 'merchant_journey',
            'opened_path' => '/dashboard',
        ])
        ->assertOk()
        ->json();

    expect((bool) data_get($ack, 'acknowledged'))->toBeTrue();

    $afterOnboarding = $experience->onboardingPayload($provisionedTenantId);
    $afterJourney = $experience->merchantJourneyPayload($provisionedTenantId);

    expect((bool) data_get($afterOnboarding, 'onboarding.first_open_acknowledged'))->toBeTrue()
        ->and((bool) data_get($afterOnboarding, 'onboarding.is_first_touch'))->toBeFalse();

    expect((bool) data_get($afterJourney, 'onboarding.first_open_acknowledged'))->toBeTrue()
        ->and((bool) data_get($afterJourney, 'onboarding.is_first_touch'))->toBeFalse();

    // Setup/import readiness still wins: a newly provisioned tenant has not imported yet.
    expect((string) data_get($afterJourney, 'import_summary.state'))->not->toBe('imported')
        ->and((string) data_get($afterJourney, 'onboarding.recommended_phase'))->toBe('ongoing_setup');
});
