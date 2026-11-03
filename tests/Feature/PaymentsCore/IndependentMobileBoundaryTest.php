<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSite;
use App\Models\User;
use App\Services\PaymentsCore\Mobile\MobilePaymentAccess;
use App\Services\PaymentsCore\Mobile\MobilePaymentException;
use App\Services\PaymentsCore\Mobile\MobileRegisterDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Independent native catalog fixture. Deliberately does not enable POS simulation. */
function independentPaymentApiFixture(string $membershipRole = 'member'): array
{
    $tenant = Tenant::query()->create(['name' => 'Independent fictional merchant', 'slug' => 'independent-payments-'.Str::lower(Str::ulid())]);
    $actor = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $actor->tenants()->attach($tenant->id, ['role' => $membershipRole, 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website',
        'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped',
        'entitlement_source' => 'test', 'price_source' => 'catalog']);
    $site = TenantSite::query()->create(['tenant_id' => $tenant->id, 'subdomain' => $tenant->slug, 'status' => 'draft', 'settings' => []]);
    config()->set(['evergrove_payments.read_enabled' => true, 'evergrove_payments.collection_enabled' => false,
        'evergrove_payments.servicing_enabled' => true, 'evergrove_payments.fixture_mode' => true,
        'evergrove_payments.fixture_collection_enabled' => false,
        'evergrove_payments.registers' => ['independent_counter' => ['tenant_id' => $tenant->id, 'site_id' => $site->id,
            'name' => 'Independent fictional counter', 'active' => true, 'user_ids' => [$actor->id],
            'binding' => ['provider' => 'fixture_gateway', 'merchant_account' => 'independent_private_merchant',
                'location_id' => 'independent_private_location', 'environment' => 'test']]]]);

    return [$tenant, $actor, $site, '/api/mobile/v1/workspaces/'.$tenant->id.'/payments'];
}

function independentPaymentToken(User $actor, array $abilities, ?DateTimeInterface $expiresAt = null): string
{
    return $actor->createToken('Independent synthetic payment review', $abilities, $expiresAt ?? now()->addHour())->plainTextToken;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('independently requires authentication on every payment read and mutation route', function (): void {
    [$tenant, , , $base] = independentPaymentApiFixture();
    foreach (['/registers', '/registers/independent_counter/catalog', '/registers/independent_counter/attempts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'] as $suffix) {
        $this->getJson($base.$suffix)->assertUnauthorized();
    }
    foreach (['quotes', 'attempts', 'attempts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/reconcile', 'attempts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/refunds'] as $suffix) {
        $this->postJson($base.'/registers/independent_counter/'.$suffix, [])->assertUnauthorized();
    }
});

it('independently requires both dedicated read abilities', function (array $abilities): void {
    [, $actor, , $base] = independentPaymentApiFixture();
    $this->withToken(independentPaymentToken($actor, $abilities))->getJson($base.'/registers')->assertForbidden();
})->with([
    'legacy-mobile-only' => [['mobile:read']],
    'payment-only' => [['payments:read']],
    'mixed-wrong-action' => [['mobile:read', 'payments:write']],
]);

it('independently requires both dedicated write abilities before mutation', function (array $abilities): void {
    [, $actor, , $base] = independentPaymentApiFixture();
    $this->withToken(independentPaymentToken($actor, $abilities))->postJson($base.'/registers/independent_counter/quotes', [
        'request_id' => (string) Str::uuid(), 'items' => [],
    ])->assertForbidden();
})->with([
    'legacy-mobile-only' => [['mobile:write']],
    'payment-only' => [['payments:write']],
    'read-only' => [['mobile:read', 'payments:read']],
]);

it('independently rejects expired and revoked real bearer tokens', function (): void {
    [, $actor, , $base] = independentPaymentApiFixture();
    $expired = independentPaymentToken($actor, ['mobile:read', 'payments:read'], now()->subSecond());
    $this->withToken($expired)->getJson($base.'/registers')->assertUnauthorized();
    $revoked = independentPaymentToken($actor, ['mobile:read', 'payments:read']);
    $actor->tokens()->delete();
    Auth::forgetGuards();
    $this->withToken($revoked)->getJson($base.'/registers')->assertUnauthorized();
});

it('independently prevents register projection from exposing routing and assignment data', function (): void {
    [, $actor, , $base] = independentPaymentApiFixture();
    $response = $this->withToken(independentPaymentToken($actor, ['mobile:read', 'payments:read']))->getJson($base.'/registers')->assertOk();
    $response->assertDontSee('independent_private_merchant', false)->assertDontSee('independent_private_location', false);
    foreach (['merchant_account', 'binding', 'user_ids', 'provider_id'] as $key) {
        expect($response->getContent())->not->toContain('"'.$key.'"');
    }
    expect(config('pos.enabled'))->toBeFalse();
});

it('independently rejects current inactive and unverified actors', function (string $field, mixed $value, int $status): void {
    [, $actor, , $base] = independentPaymentApiFixture();
    $token = independentPaymentToken($actor, ['mobile:read', 'payments:read']);
    DB::table('users')->where('id', $actor->id)->update([$field => $value]);
    $this->withToken($token)->getJson($base.'/registers')->assertStatus($status);
})->with([
    'inactive' => ['is_active', false, 401],
    'unverified' => ['email_verified_at', null, 403],
]);

it('independently denies global operator status without active tenant membership', function (string $scenario): void {
    [$tenant, $actor, , $base] = independentPaymentApiFixture();
    if ($scenario === 'revoked') {
        $actor->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
    } else {
        $actor->tenants()->detach($tenant->id);
    }
    $this->withToken(independentPaymentToken($actor, ['mobile:read', 'payments:read']))->getJson($base.'/registers')->assertNotFound();
})->with(['revoked', 'not-a-member']);

it('independently requires explicit register assignment even for tenant administrators', function (): void {
    [, $actor, , $base] = independentPaymentApiFixture('admin');
    config()->set('evergrove_payments.registers.independent_counter.user_ids', []);
    $this->withToken(independentPaymentToken($actor, ['mobile:read', 'payments:read']))
        ->getJson($base.'/registers/independent_counter/catalog')->assertNotFound();
});

it('independently rejects member refund authority before looking up a guessed payment', function (): void {
    [, $actor, , $base] = independentPaymentApiFixture('member');
    $this->withToken(independentPaymentToken($actor, ['mobile:write', 'payments:write']))
        ->postJson($base.'/registers/independent_counter/attempts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/refunds', [
            'request_id' => (string) Str::uuid(), 'amount_minor' => 1, 'reason' => 'other',
        ])->assertForbidden();
});

it('independently has no payment mutation or reconciliation GET route', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), '/payments/'));
    expect($routes)->not->toBeEmpty();
    foreach ($routes as $route) {
        if (array_intersect(['GET', 'HEAD'], $route->methods())) {
            expect($route->getActionMethod())->not->toBeIn(['quote', 'quotes', 'store', 'start', 'reconcile', 'refund', 'refunds', 'recover']);
        }
    }
});

it('independently cannot flag enable production stock collection', function (string $environment, string $bindingMode): void {
    [$tenant, $actor, , $base] = independentPaymentApiFixture('admin');
    config()->set(['evergrove_payments.collection_enabled' => true, 'evergrove_payments.fixture_collection_enabled' => true,
        'evergrove_payments.fixture_mode' => true, 'evergrove_payments.registers.independent_counter.binding.environment' => $bindingMode]);
    $created = $actor->createToken('Independent synthetic gate review', ['mobile:read', 'payments:read', 'mobile:write', 'payments:write'], now()->addHour());
    $actor->withAccessToken($created->accessToken);
    $request = Request::create($base.'/registers/independent_counter/attempts', 'POST');
    $request->setUserResolver(fn () => $actor);
    $request->attributes->set('current_tenant_id', $tenant->id);
    $directory = new MobileRegisterDirectory;
    $access = new MobilePaymentAccess($request, $directory);
    $previousEnvironment = app()->environment();
    app()->detectEnvironment(fn () => $environment);
    try {
        try {
            $access->assertAllowed($directory->binding($tenant->id, 'independent_counter'), 'collect');
        } catch (MobilePaymentException $error) {
            expect($error->blockerCode)->toBe('stock_commit_unavailable')->and($error->httpStatus)->toBe(503);

            return;
        }
        throw new RuntimeException('Configuration enabled collection without an inventory integration.');
    } finally {
        app()->detectEnvironment(fn () => $previousEnvironment);
    }
})->with([
    'production-with-test-merchant' => ['production', 'test'],
    'production-with-live-merchant' => ['production', 'live'],
    'testing-with-live-merchant' => ['testing', 'live'],
]);

it('independently preserves servicing permission when new collection and catalog access stop', function (): void {
    [$tenant, $actor, , $base] = independentPaymentApiFixture('admin');
    config()->set(['evergrove_payments.collection_enabled' => false,
        'evergrove_payments.registers.independent_counter.binding.environment' => 'live']);
    TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->delete();
    $created = $actor->createToken('Independent servicing fixture', ['mobile:read', 'payments:read', 'mobile:write', 'payments:write'], now()->addHour());
    $actor->withAccessToken($created->accessToken);
    $request = Request::create($base.'/registers/independent_counter/attempts', 'POST');
    $request->setUserResolver(fn () => $actor);
    $request->attributes->set('current_tenant_id', $tenant->id);
    $directory = new MobileRegisterDirectory;
    $access = new MobilePaymentAccess($request, $directory);
    foreach (['inspect', 'recover', 'refund'] as $operation) {
        $access->assertAllowed($directory->binding($tenant->id, 'independent_counter'), $operation);
    }
    expect($access->register('independent_counter', 'refund')['actor_id'])->toBe($actor->id);
    Http::assertNothingSent();
});
