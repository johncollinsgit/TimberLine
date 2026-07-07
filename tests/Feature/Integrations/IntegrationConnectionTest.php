<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Integrations\ConnectionManager;
use App\Services\Integrations\Contracts\ProviderConnector;
use Illuminate\Support\Facades\DB;

function integrationTenant(string $slug): Tenant
{
    return Tenant::query()->create(['name' => $slug, 'slug' => $slug]);
}

function makeConnection(Tenant $tenant, array $overrides = []): IntegrationConnection
{
    return IntegrationConnection::query()->create(array_merge([
        'tenant_id' => $tenant->id,
        'provider' => 'shopify',
        'external_account_id' => '',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'super-secret-access-token',
        'refresh_token' => 'super-secret-refresh-token',
        'expires_at' => now()->addHour(),
        'scopes' => ['read_orders', 'read_products'],
    ], $overrides));
}

test('tokens are encrypted at rest and transparently decrypted', function (): void {
    $tenant = integrationTenant('acme');
    $connection = makeConnection($tenant);

    // Raw DB value must NOT be the plaintext token.
    $raw = DB::table('integration_connections')->where('id', $connection->id)->value('access_token');
    expect($raw)->not->toBe('super-secret-access-token');
    expect($raw)->not->toContain('super-secret');

    // The model transparently decrypts it back.
    expect($connection->fresh()->access_token)->toBe('super-secret-access-token');
    expect($connection->fresh()->refresh_token)->toBe('super-secret-refresh-token');
});

test('connections are isolated by tenant via forTenant scope', function (): void {
    $alpha = integrationTenant('alpha');
    $beta = integrationTenant('beta');

    makeConnection($alpha, ['external_account_label' => 'Alpha shop']);
    makeConnection($beta, ['external_account_label' => 'Beta shop']);

    expect(IntegrationConnection::query()->forTenantId($alpha->id)->pluck('external_account_label')->all())
        ->toBe(['Alpha shop']);
    expect(IntegrationConnection::query()->forTenant($beta)->count())->toBe(1);
});

test('the unique index prevents duplicate provider account rows for a tenant', function (): void {
    $tenant = integrationTenant('dupes');
    makeConnection($tenant, ['provider' => 'square', 'external_account_id' => 'loc_1']);

    expect(fn () => makeConnection($tenant, ['provider' => 'square', 'external_account_id' => 'loc_1']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

test('the same provider account can connect for two different tenants', function (): void {
    $alpha = integrationTenant('alpha');
    $beta = integrationTenant('beta');

    makeConnection($alpha, ['provider' => 'square', 'external_account_id' => 'loc_1']);

    // Not a duplicate — different tenant. Must not throw.
    $second = makeConnection($beta, ['provider' => 'square', 'external_account_id' => 'loc_1']);
    expect($second->exists)->toBeTrue();
});

test('needsRefresh and isExpired reflect token expiry', function (): void {
    $tenant = integrationTenant('exp');

    $expiringSoon = makeConnection($tenant, ['external_account_id' => 'a', 'expires_at' => now()->addSeconds(60)]);
    $fresh = makeConnection($tenant, ['external_account_id' => 'b', 'expires_at' => now()->addHours(4)]);
    $noRefreshToken = makeConnection($tenant, ['external_account_id' => 'c', 'expires_at' => now()->subMinute(), 'refresh_token' => null]);

    expect($expiringSoon->needsRefresh(300))->toBeTrue();  // expires within 5 min lead
    expect($fresh->needsRefresh(300))->toBeFalse();
    expect($noRefreshToken->needsRefresh(300))->toBeFalse(); // nothing to refresh with
    expect($noRefreshToken->isExpired())->toBeTrue();
    expect($fresh->isExpired())->toBeFalse();
});

test('connectionsDueForRefresh selects only refreshable, expiring, connected rows', function (): void {
    $tenant = integrationTenant('due');
    $manager = app(ConnectionManager::class);

    makeConnection($tenant, ['external_account_id' => 'due', 'expires_at' => now()->addSeconds(60)]);
    makeConnection($tenant, ['external_account_id' => 'fresh', 'expires_at' => now()->addHours(4)]);
    makeConnection($tenant, ['external_account_id' => 'disconnected', 'expires_at' => now()->addSeconds(60), 'status' => IntegrationConnection::STATUS_DISCONNECTED]);

    $due = $manager->connectionsDueForRefresh(300);

    expect($due)->toHaveCount(1);
    expect($due->first()->external_account_id)->toBe('due');
});

test('the ConnectionManager registers and resolves connectors by key', function (): void {
    $connector = new class implements ProviderConnector
    {
        public function key(): string
        {
            return 'demo';
        }

        public function label(): string
        {
            return 'Demo';
        }

        public function buildAuthorizationUrl(Tenant $tenant, array $options = []): string
        {
            return 'https://demo.test/oauth';
        }

        public function handleCallback(Tenant $tenant, Illuminate\Http\Request $request): IntegrationConnection
        {
            return new IntegrationConnection;
        }

        public function refresh(IntegrationConnection $connection): IntegrationConnection
        {
            return $connection;
        }

        public function client(IntegrationConnection $connection): mixed
        {
            return null;
        }
    };

    $manager = new ConnectionManager([$connector]);

    expect($manager->hasConnector('demo'))->toBeTrue();
    expect($manager->hasConnector('missing'))->toBeFalse();
    expect($manager->connector('demo')->label())->toBe('Demo');
    expect($manager->registeredProviders())->toBe(['demo']);
    expect(fn () => $manager->connector('missing'))->toThrow(InvalidArgumentException::class);
});
