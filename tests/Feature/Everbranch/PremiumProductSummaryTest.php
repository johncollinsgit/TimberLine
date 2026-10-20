<?php

use App\Models\Marketplace\Setting;
use App\Models\Marketplace\Store;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Models\Trajectory\Space;
use App\Models\User;
use App\Services\Team\TeamWorkAccess;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantModuleCatalogService;
use App\Services\Tenancy\TenantPremiumProductSummaryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['team_work.enabled' => false, 'trajectory.enabled' => false, 'marketplace.enabled' => false, 'marketplace.host' => '', 'marketplace.checkout_provider' => 'everbranch']);
});

function premiumSummaryFixture(string $role = 'admin', string $pivot = 'owner'): array
{
    $tenant = Tenant::create(['name' => 'Fictional Premium Workspace', 'slug' => 'premium-summary-quality']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    $user = User::factory()->create(['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => $pivot, 'membership_active' => true]);

    return [$tenant, $user];
}

function premiumSummaryGrant(Tenant $tenant, string $key, string $setup = 'configured', array $entitlement = [], array $state = []): void
{
    TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => $key, 'enabled_status' => 'enabled', 'availability_status' => 'available', 'billing_status' => 'included', 'entitlement_source' => 'fixture_private_source', 'price_override_cents' => 7654321, ...$entitlement]);
    TenantModuleState::create(['tenant_id' => $tenant->id, 'module_key' => $key, 'setup_status' => $setup, ...$state]);
}

function premiumSummaryProducts(Tenant $tenant, User $user): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();
    $summary = app(TenantPremiumProductSummaryService::class)->forTenant($tenant->id, $user);
    $writes = collect(DB::getQueryLog())->filter(fn (array $q): bool => (bool) preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $q['query']));
    DB::disableQueryLog();
    expect($writes)->toBeEmpty();
    Http::assertNothingSent();

    return collect($summary['products'])->keyBy('key')->all();
}

test('recorded internal software is visible without adding public choices authorizing access or making writes', function (): void {
    [$tenant, $user] = premiumSummaryFixture();
    premiumSummaryGrant($tenant, 'markets_and_makers', 'configured', ['availability_status' => 'unavailable']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $products = premiumSummaryProducts($tenant, $user);
    $writes = collect(DB::getQueryLog())->filter(fn (array $q): bool => (bool) preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $q['query']));
    expect($writes)->toBeEmpty()->and($products['markets_makers'])->toMatchArray(['owned' => true, 'state' => 'gated', 'open_url' => null]);
    $state = app(TenantModuleAccessResolver::class)->module($tenant->id, 'markets_and_makers');
    expect($state['has_recorded_access'])->toBeTrue()->and($state['enabled'])->toBeFalse()
        ->and(app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'markets_and_makers'))->toBeFalse();
    $catalog = app(TenantModuleCatalogService::class);
    expect(array_column($catalog->publicCatalogPayload()['modules'], 'key'))->not->toContain('markets_and_makers')
        ->and(array_column($catalog->tenantStorePayload($tenant->id, 'marketing')['modules'], 'module_key'))->not->toContain('markets_and_makers')
        ->and(Setting::count())->toBe(0)->and(TenantModuleEntitlement::count())->toBe(1);
    $json = json_encode($products);
    expect($json)->not->toContain('fixture_private_source', '7654321', 'billing_status', 'entitlement_source', 'price_source');
    Http::assertNothingSent();
});

test('unassigned products remain distinct from the current workspace', function (): void {
    [$tenant, $user] = premiumSummaryFixture();
    foreach (premiumSummaryProducts($tenant, $user) as $row) {
        expect($row)->toMatchArray(['owned' => false, 'state' => 'unassigned', 'open_url' => null, 'components' => []]);
    }
    expect(TenantModuleEntitlement::count())->toBe(0);
    Http::assertNothingSent();
});

test('premium assignment does not cross tenants and foreign summaries are forbidden', function (): void {
    [$tenant, $user] = premiumSummaryFixture();
    $other = Tenant::create(['name' => 'Private Foreign Workspace', 'slug' => 'premium-foreign']);
    premiumSummaryGrant($other, 'markets_and_makers');
    expect(premiumSummaryProducts($tenant, $user)['markets_makers']['owned'])->toBeFalse();
    expect(fn () => app(TenantPremiumProductSummaryService::class)->forTenant($other->id, $user))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

test('expired future disabled and explicit override assignments are not recorded as current access', function (array $entitlement, array $override): void {
    [$tenant, $user] = premiumSummaryFixture();
    premiumSummaryGrant($tenant, 'markets_and_makers', 'configured', $entitlement, $override);
    $state = app(TenantModuleAccessResolver::class)->module($tenant->id, 'markets_and_makers');
    expect($state['has_recorded_access'])->toBeFalse()->and($state['enabled'])->toBeFalse()
        ->and(premiumSummaryProducts($tenant, $user)['markets_makers']['owned'])->toBeFalse();
    Http::assertNothingSent();
})->with([
    'expired' => [['ends_at' => now()->subDay()], []],
    'future' => [['starts_at' => now()->addDay()], []],
    'disabled grant overrides an enabled state override' => [['enabled_status' => 'disabled'], ['enabled_override' => true]],
    'disabled state overrides enabled assignment' => [[], ['enabled_override' => false]],
]);

test('Teams primary rollout and setup must independently permit the actual workspace', function (bool $enabled, bool $allowlisted, string $setup, string $expected): void {
    [$tenant, $user] = premiumSummaryFixture();
    premiumSummaryGrant($tenant, 'team_work', $setup);
    config(['team_work.enabled' => $enabled, 'team_work.tenant_allowlist' => $allowlisted ? [(string) $tenant->id] : []]);
    $row = premiumSummaryProducts($tenant, $user)['teams'];
    expect($row['owned'])->toBeTrue()->and($row['state'])->toBe($expected)
        ->and($row['open_url'] !== null)->toBe($expected === 'available')
        ->and($row['components'])->toHaveCount(1)
        ->and($row['components'][0]['name'])->toBe(app(TenantModuleAccessResolver::class)->module($tenant->id, 'team_work')['label']);
    Http::assertNothingSent();
})->with([[false, true, 'configured', 'gated'], [true, false, 'configured', 'gated'], [true, true, 'blocked', 'setup'], [true, true, 'not_started', 'setup'], [true, true, 'configured', 'available']]);

test('configured optional Teams software cannot open an unfinished primary workspace', function (): void {
    [$tenant, $user] = premiumSummaryFixture();
    premiumSummaryGrant($tenant, 'team_work', 'not_started');
    premiumSummaryGrant($tenant, 'teams_wholesale_workspace', 'configured');
    config(['team_work.enabled' => true, 'team_work.tenant_allowlist' => [(string) $tenant->id]]);
    $this->mock(TeamWorkAccess::class)->shouldNotReceive('context');
    $row = premiumSummaryProducts($tenant, $user)['teams'];
    expect($row)->toMatchArray(['owned' => true, 'state' => 'setup', 'open_url' => null, 'needs_attention' => true])
        ->and(array_column($row['components'], 'label'))->toBe(['Setup needed', 'Setup recorded'])
        ->and(count(array_unique(array_column($row['components'], 'name'))))->toBe(2);
    Http::assertNothingSent();
});

test('a ready Teams primary does not imply an optional component is configured or granted', function (bool $optionalAssigned): void {
    [$tenant, $user] = premiumSummaryFixture();
    premiumSummaryGrant($tenant, 'team_work');
    if ($optionalAssigned) {
        premiumSummaryGrant($tenant, 'teams_wholesale_workspace', 'not_started');
    }
    config(['team_work.enabled' => true, 'team_work.tenant_allowlist' => [(string) $tenant->id]]);
    $row = premiumSummaryProducts($tenant, $user)['teams'];
    expect($row['state'])->toBe('available')->and($row['open_url'])->toBe(route('team.app', ['tenant' => $tenant->slug]))
        ->and($row['components'])->toHaveCount($optionalAssigned ? 2 : 1)
        ->and($row['needs_attention'])->toBe($optionalAssigned);
    if ($optionalAssigned) {
        expect($row['components'][1]['label'])->toBe('Setup needed');
    }
    Http::assertNothingSent();
})->with([false, true]);

test('an optional Teams assignment does not invent a primary assignment', function (): void {
    [$tenant, $user] = premiumSummaryFixture();
    premiumSummaryGrant($tenant, 'teams_wholesale_workspace');
    config(['team_work.enabled' => true, 'team_work.tenant_allowlist' => [(string) $tenant->id]]);
    $row = premiumSummaryProducts($tenant, $user)['teams'];
    expect($row['owned'])->toBeTrue()->and($row['open_url'])->toBeNull()->and($row['components'])->toHaveCount(1)
        ->and($row['components'][0]['name'])->toBe(app(TenantModuleAccessResolver::class)->module($tenant->id, 'teams_wholesale_workspace')['label']);
});

test('Marketplace availability requires its verified current-tenant store and existing operator role', function (string $role, string $pivot, bool $sameTenant, string $expected): void {
    [$tenant, $user] = premiumSummaryFixture($role, $pivot);
    premiumSummaryGrant($tenant, 'markets_and_makers');
    $other = Tenant::create(['name' => 'Private Other Store', 'slug' => 'private-other-store']);
    if (! $sameTenant) {
        $user->tenants()->attach($other->id, ['role' => 'owner', 'membership_active' => true]);
    }
    Store::create(['tenant_id' => $sameTenant ? $tenant->id : $other->id, 'name' => 'Do not expose this store', 'host' => 'premium-market.example.test', 'verified_at' => now()]);
    config(['marketplace.enabled' => true, 'marketplace.host' => 'premium-market.example.test', 'app.url' => 'https://app.theeverbranch.com']);
    $row = premiumSummaryProducts($tenant, $user)['markets_makers'];
    expect($row['state'])->toBe($expected)->and($row['open_url'] !== null)->toBe($expected === 'available');
    if ($expected === 'available') {
        expect($row['open_url'])->toBe('https://app.theeverbranch.com/marketplace/operations');
    }
    expect(json_encode($row))->not->toContain('Do not expose this store', 'private-other-store')
        ->and(Setting::count())->toBe(0)->and(config('marketplace.checkout_enabled'))->toBeFalse();
    Http::assertNothingSent();
})->with([['admin', 'owner', true, 'available'], ['member', 'member', true, 'restricted'], ['admin', 'owner', false, 'setup']]);

test('Trajectory uses current-tenant financial policies and reveals no financial fields', function (string $role, string $pivot, bool $householdOwner, string $expected): void {
    [$tenant, $user] = premiumSummaryFixture($role, $pivot);
    premiumSummaryGrant($tenant, 'trajectory');
    config(['trajectory.enabled' => true]);
    Space::create(['tenant_id' => $tenant->id, 'owner_user_id' => $user->id, 'kind' => $householdOwner ? 'household' : 'business', 'name' => 'SECRET FINANCIAL NAME', 'enabled' => true, 'plan' => 'both']);
    $row = premiumSummaryProducts($tenant, $user)['trajectory'];
    expect($row['state'])->toBe($expected)->and($row['open_url'] !== null)->toBe($expected === 'available')
        ->and(json_encode($row))->not->toContain('SECRET FINANCIAL NAME', 'owner_user_id', 'balance', 'billing');
    if ($expected === 'available') {
        expect($row['open_url'])->toBe(route('trajectory.index'));
    }
    Http::assertNothingSent();
})->with([['admin', 'owner', false, 'available'], ['member', 'member', false, 'restricted'], ['member', 'member', true, 'available']]);

test('inactive memberships disclose no premium assignment or links', function (): void {
    [$tenant, $user] = premiumSummaryFixture();
    premiumSummaryGrant($tenant, 'markets_and_makers');
    $user->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
    foreach (premiumSummaryProducts($tenant, $user) as $row) {
        expect($row)->toMatchArray(['owned' => null, 'state' => 'restricted', 'open_url' => null, 'components' => []]);
    }
});

test('verification failures atomically become unknown with sanitized diagnostics and no Open links', function (): void {
    [$tenant, $user] = premiumSummaryFixture();
    Log::spy();
    $this->mock(TenantModuleAccessResolver::class)->shouldReceive('resolveForTenant')->once()
        ->with($tenant->id, ['team_work', 'teams_wholesale_workspace', 'trajectory', 'markets_and_makers'])
        ->andThrow(new RuntimeException('PRIVATE SQL credential fixture'));
    $summary = app(TenantPremiumProductSummaryService::class)->forTenant($tenant->id, $user);
    expect($summary['known'])->toBeFalse();
    foreach ($summary['products'] as $row) {
        expect($row)->toMatchArray(['owned' => null, 'state' => 'unknown', 'open_url' => null, 'components' => []]);
    }
    expect(json_encode($summary))->not->toContain('PRIVATE SQL', 'credential');
    Log::shouldHaveReceived('warning')->with('tenant.premium_summary.unavailable', ['exception_type' => 'RuntimeException']);
    Http::assertNothingSent();
});

test('Trajectory never uses another tenants authorized space to mark the current workspace available', function (): void {
    [$tenant, $user] = premiumSummaryFixture();
    premiumSummaryGrant($tenant, 'trajectory');
    config(['trajectory.enabled' => true]);
    $other = Tenant::create(['name' => 'Other private tenant', 'slug' => 'trajectory-foreign-summary']);
    premiumSummaryGrant($other, 'trajectory');
    $foreignSpace = Space::create(['tenant_id' => $other->id, 'owner_user_id' => $user->id, 'kind' => 'household', 'name' => 'FOREIGN PRIVATE SPACE', 'enabled' => true, 'plan' => 'both']);
    expect(app(\App\Services\Trajectory\FinanceAccess::class)->allows($user, $foreignSpace))->toBeTrue();
    $row = premiumSummaryProducts($tenant, $user)['trajectory'];
    expect($row['state'])->toBe('setup')->and($row['open_url'])->toBeNull()
        ->and(json_encode($row))->not->toContain('FOREIGN PRIVATE SPACE');
});

test('missing canonical assignment metadata is unknown rather than false ownership', function (): void {
    [$tenant, $user] = premiumSummaryFixture();
    $this->mock(TenantModuleAccessResolver::class)->shouldReceive('resolveForTenant')->once()->andReturn(['modules' => ['team_work' => []]]);
    $summary = app(TenantPremiumProductSummaryService::class)->forTenant($tenant->id, $user);
    expect($summary['known'])->toBeFalse()->and(collect($summary['products'])->pluck('owned')->all())->toBe([null, null, null]);
    Http::assertNothingSent();
});

test('Marketplace without a usable canonical app host cannot present an Open link', function (): void {
    [$tenant, $user] = premiumSummaryFixture();
    premiumSummaryGrant($tenant, 'markets_and_makers');
    Store::create(['tenant_id' => $tenant->id, 'name' => 'Fictional Store', 'host' => 'premium-market.example.test', 'verified_at' => now()]);
    config(['marketplace.enabled' => true, 'marketplace.host' => 'premium-market.example.test', 'app.url' => '']);
    $row = premiumSummaryProducts($tenant, $user)['markets_makers'];
    expect($row['state'])->toBe('setup')->and($row['open_url'])->toBeNull();
});
