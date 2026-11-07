<?php

use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\Dashboard\UnifiedDashboardService;
use App\Services\Marketing\CustomerProfileAccess;
use App\Services\Navigation\UnifiedAppNavigationService;
use App\Services\Search\GlobalSearchCoordinator;
use App\Services\Search\Providers\CustomersSearchProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function coreDiscoveryWorkspace(string $role = 'member', ?string $focus = null): array
{
    $tenant = Tenant::create(['name' => 'Fictional Core Customers', 'slug' => 'core-discovery-'.Str::lower(Str::random(10))]);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct',
        'metadata' => $focus ? ['workspace_focus' => $focus] : []]);
    $member = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $member->tenants()->attach($tenant, ['role' => $role, 'membership_active' => true]);
    $customer = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Ada', 'last_name' => 'Core',
        'email' => 'ada-core@example.test', 'normalized_email' => 'ada-core@example.test',
        'phone' => '(864) 555-0101', 'normalized_phone' => '+18645550101', 'notes' => 'PRIVATE marketing-only note']);

    return [$tenant, $member, $customer];
}

function coreDiscoveryRequest(Tenant $tenant, User $member): Request
{
    $request = Request::create('https://app.example.test/workspace/customers?tenant='.$tenant->slug);
    $request->attributes->set('current_tenant', $tenant);
    $request->attributes->set('current_tenant_id', $tenant->id);
    $request->setUserResolver(fn () => $member);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
});

test('verified tenant roles discover Core customers without a global marketing or operations role', function (string $role) {
    [$tenant, $member, $customer] = coreDiscoveryWorkspace($role);
    expect($member->canAccessMarketing())->toBeFalse()->and($member->isAdmin())->toBeFalse()->and($member->isManager())->toBeFalse()
        ->and(app(CustomerProfileAccess::class)->allows($member, $tenant))->toBeTrue();
    $request = coreDiscoveryRequest($tenant, $member);
    $navigation = app(UnifiedAppNavigationService::class)->build($request, $member);
    $entry = collect($navigation['items'])->firstWhere('key', 'customers');
    expect($entry['href'])->toBe(route('customers.directory', ['tenant' => $tenant->slug]))
        ->and($navigation['marketing_sub_groups'])->toBe([])
        ->and(collect($navigation['quick_actions'])->firstWhere('label', 'Find customer')['href'])->toBe($entry['href']);
    $this->actingAs($member)->get(route('customers.directory', ['tenant' => $tenant->slug, 'query' => 'Ada']))
        ->assertOk()->assertSeeText('Ada Core')->assertDontSeeText('PRIVATE marketing-only note');
    $this->get(route('customers.profile', ['tenant' => $tenant->slug, 'profile' => $customer->id]))
        ->assertOk()->assertSeeText('Ada Core')->assertDontSeeText('PRIVATE marketing-only note');
    Http::assertNothingSent();
})->with(['owner', 'tenant_owner', 'admin', 'member', 'manager']);

test('main global search returns safe Core summaries and no marketing-only entities for a tenant member', function () {
    [$tenant, $member, $customer] = coreDiscoveryWorkspace();
    MarketingImportRun::create(['tenant_id' => $tenant->id, 'type' => 'customer_csv', 'status' => 'completed',
        'source_label' => 'Ada private marketing import', 'file_name' => 'Ada-private.csv']);
    [$otherTenant, $otherMember, $otherCustomer] = coreDiscoveryWorkspace();
    $otherCustomer->update(['last_name' => 'Foreign secret']);
    $response = $this->actingAs($member)->getJson(route('app.search', ['tenant' => $tenant->slug, 'q' => 'Ada']));
    $response->assertOk();
    $results = collect($response->json('results'));
    expect($results->pluck('type')->all())->toContain('customer')->not->toContain('import', 'module', 'event')
        ->and($results->firstWhere('type', 'customer')['url'])->toBe(route('customers.profile', ['tenant' => $tenant->slug, 'profile' => $customer->id]));
    $raw = json_encode($response->json(), JSON_THROW_ON_ERROR);
    expect($raw)->not->toContain('PRIVATE marketing-only note', 'Foreign secret', 'Ada-private.csv', '/marketing/customers');
    $this->get(route('customers.profile', ['tenant' => $tenant->slug, 'profile' => $otherCustomer->id]))->assertNotFound();
    $this->get(route('customers.profile', ['tenant' => $otherTenant->slug, 'profile' => $otherCustomer->id]))->assertForbidden();
});

test('Core discovery reuses normalized phone and alias matching and hides archived customers', function () {
    [$tenant, $member, $customer] = coreDiscoveryWorkspace();
    MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'FormerAlias', 'last_name' => 'Name',
        'merged_into_profile_id' => $customer->id, 'merged_at' => now()]);
    MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'FormerAlias', 'last_name' => 'Retained',
        'merged_into_profile_id' => $customer->id, 'merged_at' => now()]);
    MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'FormerAlias', 'last_name' => 'Archived', 'archived_at' => now()]);
    $context = ['tenant_id' => $tenant->id, 'user' => $member, 'request' => coreDiscoveryRequest($tenant, $member)];
    foreach (['8645550101', '+1 (864) 555-0101', 'FormerAlias'] as $query) {
        $results = collect(app(GlobalSearchCoordinator::class)->search($query, $context)['results'])->where('type', 'customer')->values();
        expect($results)->toHaveCount(1)->and($results[0]['meta']['profile_id'])->toBe($customer->id)
            ->and($results[0]['title'])->toBe('Ada Core');
    }
    $customer->update(['archived_at' => now()]);
    expect(app(CustomersSearchProvider::class)->search('FormerAlias', $context))->toBe([]);
});

test('Core customer search treats percent and underscore literally and bounds summary results', function () {
    [$tenant, $member] = coreDiscoveryWorkspace();
    foreach (range(1, 8) as $index) {
        MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Literal%_Contact'.$index]);
    }
    MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Literal wildcard must not match']);
    $results = app(CustomersSearchProvider::class)->search('Literal%_', ['tenant_id' => $tenant->id, 'user' => $member]);
    expect($results)->toHaveCount(5);
    foreach ($results as $result) {
        expect($result['title'])->toStartWith('Literal%_Contact')->and(array_keys($result['meta']))->toBe(['profile_id']);
    }
    expect(app(CustomersSearchProvider::class)->search('', ['tenant_id' => $tenant->id, 'user' => $member]))->toBe([]);
});

test('inactive unverified foreign and unsupported tenant relationships fail Core discovery closed', function (string $denial) {
    [$tenant, $member] = coreDiscoveryWorkspace();
    match ($denial) {
        'inactive_user' => $member->update(['is_active' => false]),
        'unverified' => $member->forceFill(['email_verified_at' => null])->save(),
        'inactive_membership' => $member->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]),
        'unsupported_role' => $member->tenants()->updateExistingPivot($tenant->id, ['role' => 'viewer']),
        'foreign' => $member->tenants()->detach($tenant->id),
        'disabled' => TenantModuleState::create(['tenant_id' => $tenant->id, 'module_key' => 'customers', 'enabled_override' => false]),
    };
    expect(app(CustomerProfileAccess::class)->allows($member, $tenant))->toBeFalse()
        ->and(app(CustomersSearchProvider::class)->search('Ada', ['tenant_id' => $tenant->id, 'user' => $member]))->toBe([]);
    $navigation = app(UnifiedAppNavigationService::class)->build(coreDiscoveryRequest($tenant, $member), $member);
    expect(collect($navigation['items'])->firstWhere('key', 'customers'))->toBeNull();
    if (in_array($denial, ['inactive_user', 'inactive_membership', 'unsupported_role', 'disabled'], true)) {
        $this->actingAs($member)->get(route('customers.directory', ['tenant' => $tenant->slug]))->assertForbidden();
    }
})->with(['inactive_user', 'unverified', 'inactive_membership', 'unsupported_role', 'foreign', 'disabled']);

test('website sales owners find the shared profile without opening legacy marketing or operations', function () {
    [$tenant, $owner, $customer] = coreDiscoveryWorkspace('owner', 'website_sales');
    $request = coreDiscoveryRequest($tenant, $owner);
    $navigation = app(UnifiedAppNavigationService::class)->build($request, $owner);
    expect(collect($navigation['items'])->firstWhere('key', 'customers')['href'])->toBe(route('customers.directory', ['tenant' => $tenant->slug]))
        ->and($navigation['marketing_sub_groups'])->toBe([]);
    $results = app(GlobalSearchCoordinator::class)->search('Ada', ['tenant_id' => $tenant->id, 'user' => $owner, 'request' => $request])['results'];
    expect(collect($results)->firstWhere('type', 'customer')['meta']['profile_id'])->toBe($customer->id);
    $dashboard = app(UnifiedDashboardService::class)->forRequest($request, $owner);
    expect(collect($dashboard['next_actions'])->firstWhere('label', 'Open shared customers')['href'])->toBe(route('customers.directory', ['tenant' => $tenant->slug]));
    $this->actingAs($owner)->get(route('customers.profile', ['tenant' => $tenant->slug, 'profile' => $customer->id]))->assertOk();
});

test('Soccer focus stays isolated while explicit active verified platform administrators can read other Core tenants', function () {
    [$soccer, $member] = coreDiscoveryWorkspace('owner', 'soccer');
    expect(app(CustomerProfileAccess::class)->allows($member, $soccer))->toBeFalse()
        ->and(app(CustomersSearchProvider::class)->search('Ada', ['tenant_id' => $soccer->id, 'user' => $member]))->toBe([]);
    [$tenant, $owner, $customer] = coreDiscoveryWorkspace();
    $platform = User::factory()->platformAdmin()->create(['email_verified_at' => now()]);
    expect(app(CustomerProfileAccess::class)->allows($platform, $tenant))->toBeTrue()
        ->and(app(CustomersSearchProvider::class)->search('Ada', ['tenant_id' => $tenant->id, 'user' => $platform])[0]['meta']['profile_id'])->toBe($customer->id);
    $platform->update(['is_active' => false]);
    expect(app(CustomerProfileAccess::class)->allows($platform, $tenant))->toBeFalse();
});
