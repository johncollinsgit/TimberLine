<?php

use App\Models\FieldServiceJob;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServiceTask;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;

beforeEach(function (): void {
    $this->withoutVite();
});

test('base workspace tenant can open the field service start page', function (): void {
    [$tenant, $user] = fieldServiceTenantAndUser();

    $this->actingAs($user)
        ->get(route('field-service.index', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertSeeText('Field Service')
        ->assertSeeText('Add a customer job')
        ->assertSeeText('Create job')
        ->assertSeeText('Materials and parts')
        ->assertDontSeeText('Pour Lists');
});

test('field service creates a tenant scoped customer job task and material', function (): void {
    [$tenant, $user] = fieldServiceTenantAndUser();

    $this->actingAs($user)
        ->post(route('field-service.jobs.store', ['tenant' => $tenant->slug]), [
            'customer_name' => 'Pat Electric',
            'customer_email' => 'pat@example.com',
            'customer_phone' => '555-111-2222',
            'title' => 'Kitchen outlet repair',
            'description' => 'Breaker trips when microwave starts.',
            'service_address_line_1' => '100 Main Street',
            'service_city' => 'Fort Wayne',
            'service_state' => 'IN',
            'service_postal_code' => '46802',
            'assigned_user_id' => $user->id,
            'first_task' => 'Check GFCI and breaker',
            'first_material' => '20A breaker',
        ])
        ->assertRedirect();

    $profile = MarketingProfile::query()->where('tenant_id', $tenant->id)->where('normalized_email', 'pat@example.com')->first();
    expect($profile)->not->toBeNull();

    $job = FieldServiceJob::query()->where('tenant_id', $tenant->id)->where('title', 'Kitchen outlet repair')->first();
    expect($job)->not->toBeNull()
        ->and((int) $job->marketing_profile_id)->toBe((int) $profile->id)
        ->and((int) $job->assigned_user_id)->toBe((int) $user->id);

    expect(FieldServiceTask::query()->where('tenant_id', $tenant->id)->where('field_service_job_id', $job->id)->count())->toBe(1)
        ->and(FieldServiceMaterial::query()->where('tenant_id', $tenant->id)->where('field_service_job_id', $job->id)->count())->toBe(1);
});

test('field service blocks tenants that did not buy the module', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Shopify Starter',
        'slug' => 'shopify-starter',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);

    $this->actingAs($user)
        ->get(route('field-service.index', ['tenant' => $tenant->slug]))
        ->assertForbidden();
});

/**
 * @return array{0:Tenant,1:User}
 */
function fieldServiceTenantAndUser(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Bright Wire Electric',
        'slug' => 'bright-wire-electric',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);

    return [$tenant, $user];
}
