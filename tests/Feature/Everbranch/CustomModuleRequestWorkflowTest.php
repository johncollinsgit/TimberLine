<?php

use App\Models\CustomModuleRequest;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();

    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.public_host', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('commercial.billing_readiness.checkout_active', false);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', false);
});

function customRequestTenant(string $slug = 'custom-tenant'): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug,
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    return $tenant;
}

function customRequestUser(Tenant $tenant, string $role = 'manager'): User
{
    $user = User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $user->tenants()->attach($tenant->id, ['role' => $role]);

    return $user;
}

function customRequestPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Field photo checklist',
        'problem_summary' => 'We need to capture setup photos and notes before a job is marked complete.',
        'current_workaround' => 'The team sends pictures by text and someone copies notes later.',
        'desired_outcome' => 'Attach photos and notes to the customer workflow for Everbranch review.',
        'tools_involved' => 'Phone photos, Shopify customers, spreadsheets',
        'users_impacted' => 'Owner and field team',
        'frequency' => 'weekly',
        'urgency' => 'medium',
        'budget_range' => 'prefer_discussion',
        'reusable_module_interest' => '1',
        'mobile_relevance' => 'future_mobile_companion',
    ], $overrides);
}

test('tenant can submit a custom module request without module or billing activation', function (): void {
    $tenant = customRequestTenant('acme');
    $user = customRequestUser($tenant);

    $this->actingAs($user)
        ->post('http://acme.theeverbranch.com/custom-module-requests?tenant=acme', customRequestPayload([
            'related_module_key' => 'sms',
        ]))
        ->assertRedirect();

    $request = CustomModuleRequest::query()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($request->title)->toBe('Field photo checklist')
        ->and($request->related_module_key)->toBe('sms')
        ->and($request->status)->toBe('new')
        ->and($request->reusable_module_interest)->toBeTrue()
        ->and($request->mobile_relevance)->toBe('future_mobile_companion')
        ->and(TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->exists())->toBeFalse()
        ->and(config('commercial.billing_readiness.checkout_active'))->toBeFalse()
        ->and(config('commercial.billing_readiness.lifecycle_mutations_enabled'))->toBeFalse();
});

test('tenant can list and view only their own custom module requests', function (): void {
    $tenant = customRequestTenant('acme');
    $otherTenant = customRequestTenant('other');
    $user = customRequestUser($tenant);

    $own = CustomModuleRequest::query()->create([
        'tenant_id' => $tenant->id,
        'requested_by_user_id' => $user->id,
        'title' => 'Owner report builder',
        'problem_summary' => 'Need a custom owner report.',
        'status' => 'new',
        'mobile_relevance' => 'none',
    ]);
    $other = CustomModuleRequest::query()->create([
        'tenant_id' => $otherTenant->id,
        'title' => 'Other tenant request',
        'problem_summary' => 'Should not leak.',
        'status' => 'new',
        'mobile_relevance' => 'none',
    ]);

    $this->actingAs($user)
        ->get('http://acme.theeverbranch.com/custom-module-requests?tenant=acme')
        ->assertOk()
        ->assertSeeText('Owner report builder')
        ->assertDontSeeText('Other tenant request');

    $this->actingAs($user)
        ->get("http://acme.theeverbranch.com/custom-module-requests/{$own->id}?tenant=acme")
        ->assertOk()
        ->assertSeeText('Owner report builder');

    $this->actingAs($user)
        ->get("http://acme.theeverbranch.com/custom-module-requests/{$other->id}?tenant=acme")
        ->assertNotFound();
});

test('landlord admin can view and triage all custom module requests', function (): void {
    $tenant = customRequestTenant('acme');
    $otherTenant = customRequestTenant('other');
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $request = CustomModuleRequest::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Quote calculator',
        'problem_summary' => 'Need custom estimating workflow.',
        'status' => 'new',
        'mobile_relevance' => 'field_work',
        'reusable_module_interest' => true,
    ]);
    CustomModuleRequest::query()->create([
        'tenant_id' => $otherTenant->id,
        'title' => 'Client communication portal',
        'problem_summary' => 'Future idea only.',
        'status' => 'needs_discovery',
        'mobile_relevance' => 'both',
    ]);

    $this->actingAs($admin)
        ->get('http://app.theeverbranch.com/landlord/custom-module-requests')
        ->assertOk()
        ->assertSeeText('Quote calculator')
        ->assertSeeText('Client communication portal')
        ->assertSeeText('Status updates do not create modules');

    $this->actingAs($admin)
        ->post("http://app.theeverbranch.com/landlord/custom-module-requests/{$request->id}", [
            'status' => 'needs_discovery',
            'next_action' => 'Schedule discovery call.',
            'landlord_notes' => 'Ask about field crew workflow.',
        ])
        ->assertRedirect(route('landlord.custom-module-requests.index', ['filter' => 'needs_discovery']));

    $request->refresh();

    expect($request->status)->toBe('needs_discovery')
        ->and($request->next_action)->toBe('Schedule discovery call.')
        ->and($request->landlord_notes)->toBe('Ask about field crew workflow.')
        ->and($request->reviewed_by_user_id)->toBe($admin->id)
        ->and($request->reviewed_at)->not->toBeNull()
        ->and(TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('non landlord user cannot access landlord custom request triage', function (): void {
    $tenant = customRequestTenant('acme');
    $user = customRequestUser($tenant);

    $this->actingAs($user)
        ->get('http://app.theeverbranch.com/landlord/custom-module-requests')
        ->assertForbidden();
});

test('module store can launch request form with safe related module key', function (): void {
    $tenant = customRequestTenant('acme');
    $user = customRequestUser($tenant, 'marketing_manager');

    $this->actingAs($user)
        ->get('http://acme.theeverbranch.com/marketing/modules?tenant=acme')
        ->assertOk()
        ->assertSeeText('Request something custom')
        ->assertSeeText('Request customization');

    $this->actingAs($user)
        ->get('http://acme.theeverbranch.com/custom-module-requests/create?tenant=acme&related_module_key=sms')
        ->assertOk()
        ->assertSeeText('Related module: SMS');
});

test('unsafe internal related module key is rejected for tenant submissions', function (): void {
    $tenant = customRequestTenant('acme');
    $user = customRequestUser($tenant);

    $this->actingAs($user)
        ->post('http://acme.theeverbranch.com/custom-module-requests?tenant=acme', customRequestPayload([
            'related_module_key' => 'square',
        ]))
        ->assertSessionHasErrors('related_module_key');

    expect(CustomModuleRequest::query()->count())->toBe(0);
});

test('mobile relevance and terminal statuses are labels only', function (): void {
    $tenant = customRequestTenant('acme');
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $request = CustomModuleRequest::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Mobile job progress',
        'problem_summary' => 'Future job progress idea only.',
        'status' => 'approved',
        'mobile_relevance' => 'both',
        'reusable_module_interest' => true,
    ]);

    $this->actingAs($admin)
        ->post("http://app.theeverbranch.com/landlord/custom-module-requests/{$request->id}", [
            'status' => 'converted_to_reusable_module',
            'next_action' => 'Document reusable idea; no module is created by this status.',
            'landlord_notes' => 'Planning label only.',
        ])
        ->assertRedirect();

    expect($request->refresh()->status)->toBe('converted_to_reusable_module')
        ->and(Route::has('mobile.everbranch.products'))->toBeFalse()
        ->and(Route::has('mobile.modern-forestry.products'))->toBeTrue()
        ->and(TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->exists())->toBeFalse()
        ->and(config('commercial.billing_readiness.checkout_active'))->toBeFalse();
});
