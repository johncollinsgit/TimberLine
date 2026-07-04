<?php

use App\Models\Tenant;
use App\Models\User;

test('first-login workspace flow opens for a verified user with no tenants', function (): void {
    $user = User::factory()->tenantAdmin()->create([
        'name' => 'Nathan Collins',
        'email' => 'collinselectric91@gmail.com',
        'email_verified_at' => now(),
        'role' => 'pouring',
        'requested_via' => 'google',
        'is_active' => true,
        'approved_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('workspace.first-login'));

    $response->assertOk()
        ->assertSeeText('What is the hardest part of running a small business?')
        ->assertSeeText('How many people are on your team?')
        ->assertSeeText('What do you need as a small business owner right now?')
        ->assertSeeText('Click the apps that most pertain to you.')
        ->assertSeeText('Electrical')
        ->assertSeeText('Plumbing')
        ->assertSeeText('Home / Residential');
});

test('first-login workspace flow creates a trades workspace and lands the user inside it', function (): void {
    $user = User::factory()->tenantAdmin()->create([
        'name' => 'Nathan Collins',
        'email' => 'collinselectric91@gmail.com',
        'email_verified_at' => now(),
        'role' => 'pouring',
        'requested_via' => 'google',
        'is_active' => true,
        'approved_at' => now(),
    ]);

    $response = $this->actingAs($user)->post(route('workspace.first-login.store'), [
        'workspace_name' => 'Collins Electric',
        'template_key' => 'electrician',
        'hardest_part' => 'too_many_apps',
        'team_size' => '2_5',
        'owner_need' => ['one_dashboard', 'customer_followup'],
        'start_path' => 'self',
        'module_choices' => ['customers', 'field_service', 'messaging', 'reporting'],
    ]);

    $response->assertRedirect();

    $tenant = Tenant::query()->where('name', 'Collins Electric')->first();
    expect($tenant)->toBeInstanceOf(Tenant::class);

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

    $this->assertDatabaseHas('tenant_module_states', [
        'tenant_id' => $tenant->id,
        'module_key' => 'messaging',
        'enabled_override' => 1,
    ]);

    $this->assertDatabaseHas('tenant_onboarding_blueprints', [
        'tenant_id' => $tenant->id,
        'status' => 'final',
    ]);

    $this->assertTrue($user->fresh()->tenants()->whereKey($tenant->id)->exists());
    $this->assertTrue($user->fresh()->role === 'admin');

    $answers = $user->fresh()->onboarding_guide_answers;
    expect($answers)->toBeArray()
        ->and(data_get($answers, 'questions.hardest_part.value'))->toBe('too_many_apps')
        ->and(data_get($answers, 'questions.team_size.value'))->toBe('2_5')
        ->and(data_get($answers, 'start_path'))->toBe('self')
        ->and(collect(data_get($answers, 'selected_modules', []))->pluck('key')->all())->toContain('field_service');
});
