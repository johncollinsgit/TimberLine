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
        ->assertSeeText('What kind of work do you do?')
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

    // The user is now an admin of their OWN new workspace (never Modern Forestry).
    expect($user->fresh()->tenants()->whereKey($tenant->id)->exists())->toBeTrue();
});
