<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantWorkspaceChangeRequest;
use App\Models\User;
use App\Services\Onboarding\TenantSetupStatusService;
use App\Services\Tenancy\TenantBlueprintProfileService;

beforeEach(function (): void {
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['platform_admin', 'admin']);
    config()->set('tenancy.landlord.operator_emails', []);
});

function workspaceChangeRequestTenant(string $slug = 'workspace-change-request-co'): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => 'Workspace Change Request Co',
        'slug' => $slug,
    ]);
    $profile = TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    $blueprints = app(TenantBlueprintProfileService::class);

    $blueprints->applyBlueprint(
        $tenant,
        $profile,
        app(TenantSetupStatusService::class)->forTenant($tenant),
        $blueprints->blueprintFromInput([
            'business_template' => 'generic',
            'workspace_profile' => 'generic_custom',
            'operating_mode' => 'direct',
            'data_source_preference' => 'manual',
        ]),
        'production'
    );

    return $tenant->fresh();
}

function workspaceChangeRequestOwner(Tenant $tenant, string $pivotRole = 'owner'): User
{
    $owner = User::factory()->tenantAdmin()->create();
    $owner->tenants()->attach($tenant->id, ['role' => $pivotRole]);

    return $owner;
}

test('an owner can update workspace language but cannot change the live profile or packs', function (): void {
    $tenant = workspaceChangeRequestTenant();
    $owner = workspaceChangeRequestOwner($tenant);

    $this->actingAs($owner)
        ->put("http://{$tenant->slug}.theeverbranch.com/start/workspace-details", [
            'custom_business_type' => 'Property management',
            'primary_outcome' => 'Keep every property and resident request organized.',
            'business_description' => 'We manage rental homes across three counties.',
            'customer_label' => 'Resident',
            'work_label' => 'Property request',
            'workspace_profile' => 'retail_commerce',
            'capability_packs' => ['retail_commerce'],
            'wants_task_management' => '1',
        ])
        ->assertRedirect();

    $blueprint = data_get($tenant->fresh()->accessProfile?->metadata, 'tenant_blueprint');
    expect(data_get($blueprint, 'custom_business_type'))->toBe('Property management')
        ->and(data_get($blueprint, 'customer_label'))->toBe('Resident')
        ->and(data_get($blueprint, 'workspace_profile'))->toBe('generic_custom')
        ->and((array) data_get($blueprint, 'capability_packs'))->toBe([])
        ->and(data_get($blueprint, 'work_management_intent.wants_task_management'))->toBeTrue();
});

test('a requested conversion remains pending and leaves the live workspace unchanged', function (): void {
    $tenant = workspaceChangeRequestTenant('pending-workspace-change');
    $owner = workspaceChangeRequestOwner($tenant);

    $this->actingAs($owner)
        ->post("http://{$tenant->slug}.theeverbranch.com/start/workspace-change-requests", [
            'requested_template_key' => 'electrician',
            'request_note' => 'We now schedule field visits and need a service workspace.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $request = TenantWorkspaceChangeRequest::query()->where('tenant_id', $tenant->id)->firstOrFail();
    $blueprint = data_get($tenant->fresh()->accessProfile?->metadata, 'tenant_blueprint');

    expect($request->status)->toBe(TenantWorkspaceChangeRequest::STATUS_PENDING)
        ->and($request->requested_template_key)->toBe('electrician')
        ->and(data_get($request->requested_context, 'suggested_workspace_profile'))->toBe('field_service_trades')
        ->and(data_get($blueprint, 'workspace_profile'))->toBe('generic_custom')
        ->and((array) data_get($blueprint, 'capability_packs'))->toBe([]);

    $this->actingAs($owner)
        ->get("http://{$tenant->slug}.theeverbranch.com/start")
        ->assertOk()
        ->assertSee('Workspace details')
        ->assertSee('Request awaiting review')
        ->assertSee('Your current workspace stays exactly as it is until we approve the change.');
});

test('a landlord approval applies the reviewed profile while a manager cannot request a conversion', function (): void {
    $tenant = workspaceChangeRequestTenant('approved-workspace-change');
    $manager = workspaceChangeRequestOwner($tenant, 'manager');

    $this->actingAs($manager)
        ->post("http://{$tenant->slug}.theeverbranch.com/start/workspace-change-requests", [
            'requested_template_key' => 'electrician',
            'request_note' => 'We now perform on-site service work.',
        ])
        ->assertForbidden();

    $owner = workspaceChangeRequestOwner($tenant);
    $request = TenantWorkspaceChangeRequest::query()->create([
        'tenant_id' => $tenant->id,
        'requested_by_user_id' => $owner->id,
        'requested_template_key' => 'electrician',
        'requested_context' => ['suggested_workspace_profile' => 'field_service_trades'],
        'request_note' => 'We now perform on-site service work.',
        'status' => TenantWorkspaceChangeRequest::STATUS_PENDING,
        'requested_at' => now(),
    ]);
    $operator = User::factory()->platformAdmin()->create();

    $this->actingAs($operator)
        ->post("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}/workspace-change-requests/{$request->id}/approve", [
            'workspace_profile' => 'field_service_trades',
            'capability_packs' => ['service_reputation'],
            'decision_note' => 'Approved after reviewing the service workflow.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $blueprint = data_get($tenant->fresh()->accessProfile?->metadata, 'tenant_blueprint');
    expect($request->fresh()->status)->toBe(TenantWorkspaceChangeRequest::STATUS_APPROVED)
        ->and(data_get($blueprint, 'business_template'))->toBe('electrician')
        ->and(data_get($blueprint, 'workspace_profile'))->toBe('field_service_trades')
        ->and((array) data_get($blueprint, 'capability_packs'))->toContain('service_reputation')
        ->and(data_get($blueprint, 'blueprint_review_status'))->toBe('reviewed');
});

test('an owner can cancel a pending request and a cancellation keeps the current profile', function (): void {
    $tenant = workspaceChangeRequestTenant('cancel-workspace-change');
    $owner = workspaceChangeRequestOwner($tenant);
    $request = TenantWorkspaceChangeRequest::query()->create([
        'tenant_id' => $tenant->id,
        'requested_by_user_id' => $owner->id,
        'requested_template_key' => 'candle_maker',
        'requested_context' => ['suggested_workspace_profile' => 'maker_production'],
        'request_note' => 'We would like a production workspace.',
        'status' => TenantWorkspaceChangeRequest::STATUS_PENDING,
        'requested_at' => now(),
    ]);

    $this->actingAs($owner)
        ->delete("http://{$tenant->slug}.theeverbranch.com/start/workspace-change-requests/{$request->id}")
        ->assertRedirect();

    $blueprint = data_get($tenant->fresh()->accessProfile?->metadata, 'tenant_blueprint');
    expect($request->fresh()->status)->toBe(TenantWorkspaceChangeRequest::STATUS_CANCELLED)
        ->and(data_get($blueprint, 'workspace_profile'))->toBe('generic_custom');
});
