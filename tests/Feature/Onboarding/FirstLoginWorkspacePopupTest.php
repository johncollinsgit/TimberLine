<?php

use App\Models\Tenant;
use App\Models\User;

beforeEach(function (): void {
    config()->set('features.first_login_modal', true);
});

test('the popup workspace flow renders for a memberless user when the flag is on', function (): void {
    $user = User::factory()->tenantAdmin()->create([
        'email_verified_at' => now(),
        'is_active' => true,
        'approved_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('workspace.first-login'))
        ->assertOk()
        ->assertSeeText('Set up your workspace')
        ->assertSeeText('Guided launch')
        ->assertSeeText('What kind of business are you building?')
        ->assertSeeText('Pick the tools that sound useful')
        ->assertSeeText('Want a hand setting it up?');
});

test('the popup creates a domain-neutral workspace and records tool picks as interests only', function (): void {
    $user = User::factory()->tenantAdmin()->create([
        'name' => 'Jamie Rivera',
        'email_verified_at' => now(),
        'is_active' => true,
        'approved_at' => now(),
    ]);

    // A non-Forestry business type, chosen from config-driven blueprints (no code change).
    $response = $this->actingAs($user)->post(route('workspace.first-login.store'), [
        'workspace_name' => 'Green Thumb Landscaping',
        'template_key' => 'landscaping',
        'team_size' => '2_5',
        'hardest_part' => 'keeping_up_with_customers',
        'start_path' => 'self',
        'module_choices' => ['customers', 'field_service', 'billing', 'reporting'],
    ]);

    $response->assertRedirect();

    $tenant = Tenant::query()->where('name', 'Green Thumb Landscaping')->first();
    expect($tenant)->not->toBeNull();

    $this->assertDatabaseHas('tenant_user', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'role' => 'admin',
    ]);

    $this->assertDatabaseHas('tenant_access_profiles', [
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
    ]);

    // Doctrine: tool picks are recorded as interests only — never auto-enabled.
    $this->assertDatabaseMissing('tenant_module_states', [
        'tenant_id' => $tenant->id,
        'enabled_override' => 1,
    ]);

    $setupStatus = $tenant->fresh()->setupStatus;
    expect($setupStatus)->not->toBeNull()
        ->and($setupStatus->module_interests)->toContain('field_service')
        ->and($setupStatus->module_interests)->toContain('customers');

    $this->assertDatabaseHas('tenant_onboarding_blueprints', [
        'tenant_id' => $tenant->id,
        'status' => 'final',
    ]);

    expect(data_get($tenant->fresh()->accessProfile?->metadata, 'tenant_blueprint.workspace_profile'))->toBe('field_service_trades')
        ->and(data_get($tenant->fresh()->accessProfile?->metadata, 'tenant_blueprint.blueprint_review_status'))->toBe('needs_follow_up');

    // The user is now an admin of their OWN new workspace (never Modern Forestry).
    expect($user->fresh()->tenants()->whereKey($tenant->id)->exists())->toBeTrue();
});

test('other business onboarding stores a neutral custom base and its plain-language context', function (): void {
    $user = User::factory()->tenantAdmin()->create([
        'email_verified_at' => now(),
        'is_active' => true,
        'approved_at' => now(),
    ]);

    $this->actingAs($user)->post(route('workspace.first-login.store'), [
        'workspace_name' => 'Northside Community Studio',
        'template_key' => 'custom',
        'custom_business_type' => 'Community arts nonprofit',
        'business_description' => 'We organize member workshops and shared studio time.',
        'customer_label' => 'Members',
        'work_label' => 'Workshop',
        'team_size' => '2_5',
        'hardest_part' => 'team_and_work_tracking',
        'start_path' => 'guided',
    ])->assertRedirect();

    $tenant = Tenant::query()->where('slug', 'northside-community-studio')->firstOrFail();
    $blueprint = data_get($tenant->fresh()->accessProfile?->metadata, 'tenant_blueprint');

    expect(data_get($blueprint, 'workspace_profile'))->toBe('generic_custom')
        ->and(data_get($blueprint, 'custom_business_type'))->toBe('Community arts nonprofit')
        ->and(data_get($blueprint, 'business_description'))->toBe('We organize member workshops and shared studio time.')
        ->and(data_get($blueprint, 'customer_label'))->toBe('Members')
        ->and(data_get($blueprint, 'work_label'))->toBe('Workshop')
        ->and((array) data_get($blueprint, 'capability_packs'))->toBe([])
        ->and(data_get($blueprint, 'blueprint_review_status'))->toBe('needs_follow_up');
});
