<?php

use App\Models\LandlordOperatorAction;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Services\Tenancy\WorkspacePresentationService;

beforeEach(function (): void {
    config(['workspace_presentation.owner_email' => 'owner@example.invalid',
        'tenancy.landlord.operator_roles' => ['platform_admin'],
        'tenancy.landlord.operator_emails' => ['owner@example.invalid']]);
});

test('only the configured active platform owner can change a client template', function (): void {
    $tenant = Tenant::create(['name' => 'Example Studio', 'slug' => 'example-studio']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'metadata' => ['preserved' => true]]);
    $owner = User::factory()->platformAdmin()->create(['email' => 'owner@example.invalid']);

    $this->actingAs($owner)->put(route('landlord.tenants.presentation.update', $tenant), ['template' => 'studio', 'summary' => 'Your clients, work, and next steps.'])->assertRedirect();
    $tenant->refresh();
    expect(data_get($tenant->accessProfile->metadata, 'workspace_presentation.template'))->toBe('studio')
        ->and($tenant->accessProfile->metadata['preserved'])->toBeTrue()
        ->and($tenant->accessProfile->plan_key)->toBe('base');
    $action = LandlordOperatorAction::withoutGlobalScopes()->where('action_type', 'workspace_presentation_updated')->sole();
    expect($action->actor_user_id)->toBe($owner->id)->and($action->context['billing_impact'])->toBe('none');
    $this->actingAs($owner)->put(route('landlord.tenants.presentation.update', $tenant), ['template' => 'studio', 'summary' => 'Your clients, work, and next steps.'])->assertRedirect();
    expect(LandlordOperatorAction::withoutGlobalScopes()->where('action_type', 'workspace_presentation_updated')->count())->toBe(1);

    $other = User::factory()->platformAdmin()->create(['email' => 'other@example.invalid']);
    $this->actingAs($other)->put(route('landlord.tenants.presentation.update', $tenant), ['template' => 'retail'])->assertForbidden();
    $customer = User::factory()->tenantAdmin()->create();
    $customer->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    $this->actingAs($customer)->put(route('landlord.tenants.presentation.update', $tenant), ['template' => 'care'])->assertForbidden();
    expect($tenant->accessProfile->fresh()->metadata['workspace_presentation']['template'])->toBe('studio');
});

test('unconfigured owner and inactive owner cannot edit templates', function (): void {
    $owner = User::factory()->platformAdmin()->create(['email' => 'owner@example.invalid']);
    $service = app(WorkspacePresentationService::class);
    config(['workspace_presentation.owner_email' => null]);
    expect($service->canEdit($owner))->toBeFalse();
    config(['workspace_presentation.owner_email' => $owner->email]);
    $owner->forceFill(['is_active' => false])->save();
    expect($service->canEdit($owner))->toBeFalse();
});

test('template selection rejects unknown keys and preserves the flagship contract', function (): void {
    $owner = User::factory()->platformAdmin()->create(['email' => 'owner@example.invalid']);
    $tenant = Tenant::create(['name' => 'Example Client', 'slug' => 'example-client']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->actingAs($owner)->put(route('landlord.tenants.presentation.update', $tenant), ['template' => 'untrusted'])->assertSessionHasErrors('template');
    $flagship = Tenant::create(['name' => 'Existing Flagship', 'slug' => config('tenancy.auth.flagship_tenant_slug')]);
    $this->actingAs($owner)->put(route('landlord.tenants.presentation.update', $flagship), ['template' => 'retail'])->assertUnprocessable();
});

test('an unassigned field client receives a field presentation without changing its plan', function (): void {
    $tenant = Tenant::create(['name' => 'Example Electric', 'slug' => 'example-electric']);
    $profile = TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'metadata' => ['preserved' => true]]);
    $presentation = app(WorkspacePresentationService::class)->forTenant($tenant);
    expect($presentation['key'])->toBe('field')->and($presentation['collection'])->toBe('Everbranch Field')
        ->and($profile->fresh()->metadata)->toBe(['preserved' => true]);
});
