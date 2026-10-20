<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleAccessRequest;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set('commercial.billing_readiness.checkout_active', false);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', false);
});

function branchSettingsFixture(string $role = 'admin', string $pivot = 'owner'): array
{
    $tenant = Tenant::query()->create(['name' => 'Fictional Quality Workspace', 'slug' => 'branch-settings-quality']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'direct_starter', 'operating_mode' => 'direct', 'source' => 'test']);
    $user = User::factory()->create(['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => $pivot]);

    return [$tenant, $user];
}

test('branch settings reads isolate pending requests and never activate access', function (): void {
    [$tenant, $user] = branchSettingsFixture();
    $other = Tenant::query()->create(['name' => 'Foreign Workspace', 'slug' => 'foreign-branch-settings']);
    foreach ([[$tenant->id, 'diagnostics_advanced'], [$other->id, 'sms']] as [$id, $module]) {
        TenantModuleAccessRequest::query()->create(['tenant_id' => $id, 'module_key' => $module, 'status' => 'pending', 'requested_by' => $user->id, 'source' => 'test']);
    }
    $response = $this->actingAs($user)->get('http://branch-settings-quality.theeverbranch.com/marketing/modules?tab=invalid');
    $response->assertOk()->assertSeeText('Your plan')->assertSeeText('Your branches')->assertSeeText('Add software')->assertSeeText('Setup')->assertSeeText('Review your agreement and receipts');
    $modules = collect($response->viewData('moduleStorePayload')['modules'])->keyBy('module_key');
    expect($modules['diagnostics_advanced']['request_pending'])->toBeTrue()
        ->and($modules['sms']['request_pending'])->toBeFalse()
        ->and($response->viewData('hostedBillingReady'))->toBeFalse()
        ->and(TenantModuleAccessRequest::query()->count())->toBe(2)
        ->and(TenantModuleEntitlement::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('branch settings members can review guides without financial or mutation controls', function (): void {
    [$tenant, $user] = branchSettingsFixture('member', 'member');
    $response = $this->actingAs($user)->get('http://branch-settings-quality.theeverbranch.com/marketing/modules');
    $response->assertOk()->assertDontSeeText('Review your agreement and receipts')->assertDontSee(route('custom-module-requests.create'), false)->assertSeeText('Ask your workspace administrator to make changes.');
    expect($response->viewData('canManageBranches'))->toBeFalse()->and($response->viewData('canViewAgreement'))->toBeFalse();
    $this->post(route('marketing.modules.activate', ['moduleKey' => 'sms']))->assertForbidden();
    expect(TenantModuleEntitlement::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('messaging quotes its exact product and never substitutes the sms bundle when disabled', function (): void {
    [$tenant] = branchSettingsFixture();
    $service = app(TenantModuleCatalogService::class);
    $modules = collect($service->tenantStorePayload($tenant->id, 'marketing')['modules'])->keyBy('module_key');
    expect($modules['messaging']['purchase'])->toMatchArray(['addon_key' => 'messaging', 'recurring_price_cents' => 1999, 'setup_price_cents' => 0])
        ->and($modules['sms']['purchase'])->toMatchArray(['addon_key' => 'sms', 'recurring_price_cents' => 9900, 'setup_price_cents' => 9900]);
    config()->set('module_catalog.addons.messaging.purchase_enabled', false);
    $modules = collect($service->tenantStorePayload($tenant->id, 'marketing')['modules'])->keyBy('module_key');
    expect($modules['messaging']['purchase'])->toBe([]);
    Http::assertNothingSent();
});

test('export synthetic branch settings browser fixtures', function (string $name): void {
    if (! getenv('BRANCH_SETTINGS_FIXTURES')) {
        $this->markTestSkipped('Fixture export is opt-in for isolated browser verification.');
    }
    $this->withVite();
    [$tenant, $user] = branchSettingsFixture(in_array($name, ['owner', 'premium-owner', 'premium-unknown'], true) ? 'admin' : 'member', in_array($name, ['owner', 'premium-owner', 'premium-unknown'], true) ? 'owner' : 'member');
    if (str_starts_with($name, 'premium-')) {
        foreach (['team_work', 'teams_wholesale_workspace', 'markets_and_makers', 'trajectory'] as $key) {
            TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => $key, 'enabled_status' => 'enabled', 'availability_status' => 'available', 'billing_status' => 'included']);
            \App\Models\TenantModuleState::create(['tenant_id' => $tenant->id, 'module_key' => $key, 'setup_status' => $key === 'teams_wholesale_workspace' ? 'not_started' : 'configured']);
        }
        config(['team_work.enabled' => true, 'team_work.tenant_allowlist' => [(string) $tenant->id], 'trajectory.enabled' => true, 'marketplace.enabled' => false]);
        \App\Models\Trajectory\Space::create(['tenant_id' => $tenant->id, 'owner_user_id' => $user->id, 'kind' => 'business', 'name' => 'Private synthetic finance space', 'enabled' => true]);
        if ($name === 'premium-unknown') {
            config()->set('module_catalog.offers.standard.premium_products.2.module_keys', ['invalid_fixture_mapping']);
        }
    }
    TenantModuleAccessRequest::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'diagnostics_advanced', 'status' => 'pending', 'requested_by' => $user->id, 'source' => 'test']);
    $directory = storage_path('framework/testing/branch-settings');
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    $response = $this->actingAs($user)->get('http://branch-settings-quality.theeverbranch.com/marketing/modules');
    $response->assertOk();
    file_put_contents($directory.'/'.$name.'.html', $response->getContent());
    Http::assertNothingSent();
})->with(['owner', 'member', 'premium-owner', 'premium-member', 'premium-unknown']);
