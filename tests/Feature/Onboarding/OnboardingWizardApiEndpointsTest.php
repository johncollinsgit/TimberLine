<?php

use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantDiscoveryProfile;
use App\Models\User;

test('onboarding wizard endpoints require authentication', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Tenant A',
        'slug' => 'tenant-a',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $this->getJson(route('onboarding.api.contract', ['tenant' => 'tenant-a']))
        ->assertStatus(401);

    $this->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'tenant-a']), [
        'rail' => 'direct',
    ])->assertStatus(401);

    $this->postJson(route('onboarding.api.blueprint.finalize', ['tenant' => 'tenant-a']))
        ->assertStatus(401);
});

test('onboarding wizard endpoints are tenant-safe and do not leak across tenants', function (): void {
    $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenantA->id => ['role' => 'admin']]);

    $this->actingAs($user)
        ->getJson(route('onboarding.api.contract', ['tenant' => 'tenant-b']))
        ->assertStatus(403);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'tenant-b']), [
            'rail' => 'direct',
            'selected_modules' => ['customers'],
        ])
        ->assertStatus(403);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.finalize', ['tenant' => 'tenant-b']))
        ->assertStatus(403);
});

test('GET contract seeds the electrician defaults for the direct onboarding surface', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'production',
        ],
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $response = $this->actingAs($user)
        ->getJson(route('onboarding.api.contract', ['tenant' => 'tenant-a']))
        ->assertOk()
        ->json();

    expect(data_get($response, 'contract.defaults.template_key'))->toBe('electrician')
        ->and(data_get($response, 'contract.defaults.data_source'))->toBe('manual')
        ->and(data_get($response, 'contract.defaults.selected_modules'))->toContain('customers')
        ->and(data_get($response, 'contract.defaults.setup_preferences.label_overrides.customer_label'))->toBe('Customer')
        ->and(data_get($response, 'contract.defaults.setup_preferences.client_brand.logo_alt'))->toBe('Company logo')
        ->and(data_get($response, 'contract.defaults.setup_preferences.client_brand.display_name'))->toBeNull()
        ->and(data_get($response, 'contract.defaults.setup_preferences.client_brand.logo_url'))->toBeNull()
        ->and(data_get($response, 'contract.steps'))->toHaveCount(3)
        ->and(collect(data_get($response, 'contract.steps'))->pluck('step_key')->all())->not->toContain('mobile_intent');
});

test('GET contract only returns the current users onboarding draft', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $userA = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $userB = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $userA->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);
    $userB->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $this->actingAs($userA)
        ->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'tenant-a']), [
            'rail' => 'direct',
            'template_key' => 'electrician',
            'desired_outcome_first' => 'Get the electrician workspace ready.',
            'selected_modules' => ['customers'],
            'data_source' => 'manual',
            'setup_preferences' => [
                'client_brand' => [
                    'display_name' => 'Private Electric',
                    'logo_url' => 'https://cdn.example.test/private-electric-logo.png',
                    'logo_alt' => 'Private Electric logo',
                ],
            ],
            'mobile_intent' => [
                'needs_mobile_access' => false,
            ],
        ])
        ->assertOk();

    $response = $this->actingAs($userB)
        ->getJson(route('onboarding.api.contract', ['tenant' => 'tenant-a']))
        ->assertOk()
        ->json();

    expect(data_get($response, 'draft'))->toBeNull()
        ->and(data_get($response, 'contract.defaults.setup_preferences.client_brand.display_name'))->toBeNull();
});

test('onboarding wizard endpoints reject tenant users without onboarding roles', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'pouring',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'staff']]);

    $this->actingAs($user)
        ->getJson(route('onboarding.api.contract', ['tenant' => 'tenant-a']))
        ->assertStatus(403);
});

test('POST autosave rejects modules hidden from the safe onboarding surface', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'tenant-a']), [
            'rail' => 'direct',
            'template_key' => 'electrician',
            'selected_modules' => ['settings'],
            'data_source' => 'manual',
            'mobile_intent' => [
                'needs_mobile_access' => false,
            ],
        ])
        ->assertStatus(422);
});

test('GET contract returns contract payload and includes latest draft when present', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
        'metadata' => [
            'account_mode' => 'demo',
        ],
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'tenant-a.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $autosave = $this->actingAs($user)
        ->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'tenant-a']), [
            'template_key' => 'candle',
            'desired_outcome_first' => 'first_sync',
            'selected_modules' => ['customers', 'lead_capture'],
            'data_source' => 'shopify',
            'mobile_intent' => [
                'needs_mobile_access' => true,
                'mobile_roles_needed' => ['owner'],
                'mobile_jobs_requested' => ['photos_uploads'],
            ],
        ])
        ->assertOk()
        ->json();

    expect(data_get($autosave, 'draft.payload.selected_modules'))->toContain('lead_capture')
        ->and(data_get($autosave, 'draft.payload.tenant_creation_policy'))->toBe('create_fresh_production_tenant');

    $response = $this->actingAs($user)
        ->getJson(route('onboarding.api.contract', ['tenant' => 'tenant-a']))
        ->assertOk()
        ->json();

    expect(data_get($response, 'contract.context.rail'))->toBe('shopify')
        ->and(data_get($response, 'contract.context.account_mode'))->toBe('demo')
        ->and(data_get($response, 'contract.options.rails'))->toContain('shopify')
        ->and(data_get($response, 'contract.options.account_modes'))->toContain('demo')
        ->and(data_get($response, 'contract.options.data_sources'))->toContain('csv')
        ->and(data_get($response, 'contract.options.templates.0.key'))->not->toBeNull()
        ->and(data_get($response, 'contract.options.module_keys'))->toContain('customers')
        ->and(data_get($response, 'contract.options.mobile_roles'))->toContain('owner')
        ->and(data_get($response, 'contract.options.mobile_jobs'))->toContain('photos_uploads')
        ->and(data_get($response, 'draft.payload.mobile_intent.needs_mobile_access'))->toBeTrue();
});

test('POST autosave rejects invalid mobile intent values', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'tenant-a']), [
            'template_key' => 'candle',
            'mobile_intent' => [
                'needs_mobile_access' => true,
                'mobile_roles_needed' => ['janitor'],
            ],
        ])
        ->assertStatus(422);
});

test('draft autosave overwrites a stable per-user draft record', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $first = $this->actingAs($user)
        ->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'tenant-a']), [
            'rail' => 'direct',
            'template_key' => 'candle',
            'selected_modules' => ['customers'],
            'mobile_intent' => ['needs_mobile_access' => false],
        ])
        ->assertOk()
        ->json();

    $second = $this->actingAs($user)
        ->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'tenant-a']), [
            'rail' => 'direct',
            'template_key' => 'candle',
            'selected_modules' => ['customers', 'lead_capture'],
            'mobile_intent' => ['needs_mobile_access' => true],
        ])
        ->assertOk()
        ->json();

    expect(data_get($first, 'draft.id'))->toBe(data_get($second, 'draft.id'))
        ->and(data_get($second, 'draft.payload.selected_modules'))->toContain('lead_capture')
        ->and(data_get($second, 'draft.payload.mobile_intent.needs_mobile_access'))->toBeTrue();
});

test('draft autosave and finalize persist electrician client branding preferences', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $autosave = $this->actingAs($user)
        ->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'collins-electric']), [
            'rail' => 'direct',
            'template_key' => 'electrician',
            'desired_outcome_first' => 'Get the electrician workspace ready.',
            'selected_modules' => ['customers', 'lead_capture'],
            'data_source' => 'manual',
            'setup_preferences' => [
                'client_brand' => [
                    'display_name' => 'Collins Electric',
                    'logo_url' => 'https://cdn.example.test/collins-electric-logo.png',
                    'logo_alt' => 'Collins Electric logo',
                ],
                'label_overrides' => [
                    'work_label' => 'Service Call',
                ],
            ],
            'mobile_intent' => [
                'needs_mobile_access' => false,
            ],
        ])
        ->assertOk()
        ->json();

    expect(data_get($autosave, 'draft.payload.setup_preferences.client_brand.display_name'))->toBe('Collins Electric')
        ->and(data_get($autosave, 'draft.payload.setup_preferences.client_brand.logo_url'))->toBe('https://cdn.example.test/collins-electric-logo.png')
        ->and(data_get($autosave, 'draft.payload.setup_preferences.label_overrides.work_label'))->toBe('Service Call');

    $final = $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.finalize', ['tenant' => 'collins-electric']))
        ->assertOk()
        ->json();

    expect(data_get($final, 'final.payload.setup_preferences.client_brand.logo_alt'))->toBe('Collins Electric logo')
        ->and(data_get($final, 'final.payload.setup_preferences.client_brand.logo_url'))->toBe('https://cdn.example.test/collins-electric-logo.png');

    $profile = TenantDiscoveryProfile::query()->where('tenant_id', (int) $tenant->id)->first();

    expect($profile)->not->toBeNull()
        ->and($profile?->primary_brand_name)->toBe('Collins Electric')
        ->and($profile?->primary_logo_url)->toBe('https://cdn.example.test/collins-electric-logo.png')
        ->and($profile?->is_active)->toBeTrue();
});

test('finalize fails when no draft exists', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.finalize', ['tenant' => 'tenant-a']))
        ->assertStatus(422)
        ->assertJsonPath('errors.draft.0', 'No onboarding draft exists to finalize.');
});

test('finalize succeeds from a valid draft and creates append-only final snapshots', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [],
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $autosave = $this->actingAs($user)
        ->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'tenant-a']), [
            'rail' => 'direct',
            'template_key' => 'candle',
            'desired_outcome_first' => 'first_value',
            'selected_modules' => ['customers'],
            'data_source' => 'csv',
            'mobile_intent' => [
                'needs_mobile_access' => false,
            ],
        ])
        ->assertOk()
        ->json();

    $draftId = (int) data_get($autosave, 'draft.id');

    $first = $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.finalize', ['tenant' => 'tenant-a']))
        ->assertOk()
        ->json();

    $second = $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.finalize', ['tenant' => 'tenant-a']))
        ->assertOk()
        ->json();

    expect(data_get($first, 'final.id'))->not->toBeNull()
        ->and(data_get($second, 'final.id'))->not->toBeNull()
        ->and((int) data_get($first, 'final.id'))->not->toBe((int) data_get($second, 'final.id'))
        ->and((int) data_get($first, 'final.tenant_id'))->toBe((int) $tenant->id)
        ->and(data_get($first, 'final.status'))->toBe('final')
        ->and((int) data_get($first, 'final.origin.draft.id'))->toBe($draftId)
        ->and(data_get($first, 'final.origin.finalized_at'))->not->toBeNull()
        ->and(data_get($first, 'meta.blueprint_only'))->toBeTrue()
        ->and(data_get($first, 'final.payload.tenant_creation_policy'))->toBe('use_existing_tenant');

    $finalCount = \App\Models\TenantOnboardingBlueprint::query()
        ->forTenantId((int) $tenant->id)
        ->where('status', 'final')
        ->where('created_by_user_id', (int) $user->id)
        ->count();

    expect($finalCount)->toBe(2);

    $draft = \App\Models\TenantOnboardingBlueprint::query()
        ->forTenantId((int) $tenant->id)
        ->where('status', 'draft')
        ->where('created_by_user_id', (int) $user->id)
        ->latest('id')
        ->first();

    expect($draft)->not->toBeNull()
        ->and((int) $draft->id)->toBe($draftId);
});

test('finalize does not imply demo tenant conversion or tenant creation', function (): void {
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
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $beforeTenantCount = Tenant::query()->count();

    $this->actingAs($user)
        ->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'demo-tenant']), [
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
        ])
        ->assertOk();

    $response = $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.finalize', ['tenant' => 'demo-tenant']))
        ->assertOk()
        ->json();

    expect(data_get($response, 'final.account_mode'))->toBe('demo')
        ->and(data_get($response, 'final.payload.tenant_creation_policy'))->toBe('create_fresh_production_tenant');

    expect(Tenant::query()->count())->toBe($beforeTenantCount);

    $accessProfile = $tenant->fresh(['accessProfile'])->accessProfile;
    expect($accessProfile?->metadata['account_mode'] ?? null)->toBe('demo');
});

test('finalize rejects invalid draft payloads', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    \App\Models\TenantOnboardingBlueprint::query()->create([
        'tenant_id' => (int) $tenant->id,
        'created_by_user_id' => (int) $user->id,
        'status' => 'draft',
        'account_mode' => 'production',
        'rail' => 'direct',
        'blueprint_version' => 1,
        'payload' => [
            'rail' => 'direct',
            'template_key' => null,
        ],
        'origin' => [
            'revision' => 1,
        ],
    ]);

    $this->actingAs($user)
        ->postJson(route('onboarding.api.blueprint.finalize', ['tenant' => 'tenant-a']))
        ->assertStatus(422)
        ->assertJsonPath('errors.template_key.0', 'The template key field is required.');
});

test('finalize is scoped to the current user draft and cannot finalize another users draft', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $userA = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $userB = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $userA->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);
    $userB->tenants()->syncWithoutDetaching([(int) $tenant->id => ['role' => 'admin']]);

    $this->actingAs($userA)
        ->postJson(route('onboarding.api.draft.autosave', ['tenant' => 'tenant-a']), [
            'rail' => 'direct',
            'template_key' => 'candle',
            'desired_outcome_first' => 'first_value',
            'selected_modules' => ['customers'],
            'data_source' => 'csv',
            'mobile_intent' => [
                'needs_mobile_access' => false,
            ],
        ])
        ->assertOk();

    $this->actingAs($userB)
        ->postJson(route('onboarding.api.blueprint.finalize', ['tenant' => 'tenant-a']))
        ->assertStatus(422)
        ->assertJsonPath('errors.draft.0', 'No onboarding draft exists to finalize.');
});
