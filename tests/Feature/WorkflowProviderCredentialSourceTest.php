<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Automation\V2\Providers\ProviderAccessTokenService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('provider refreshes use the encrypted oauth client captured with the connection', function (
    string $provider,
    string $url,
): void {
    $tenant = Tenant::query()->create([
        'name' => Str::headline($provider).' credential tenant',
        'slug' => $provider.'-credentials-'.Str::lower((string) Str::ulid()),
    ]);
    config()->set('services.asana.oauth_client_id', 'wrong-shared-client');
    config()->set('services.asana.oauth_client_secret', 'wrong-shared-secret');
    config()->set('services.google_calendar.oauth_client_id', 'wrong-shared-client');
    config()->set('services.google_calendar.oauth_client_secret', 'wrong-shared-secret');
    config()->set('services.shopify.automation_oauth_client_id', 'wrong-shared-client');
    config()->set('services.shopify.automation_oauth_client_secret', 'wrong-shared-secret');
    config()->set('services.square.oauth_client_id', 'wrong-shared-client');
    config()->set('services.square.oauth_client_secret', 'wrong-shared-secret');
    config()->set('services.square.token_url', $url);

    $connection = IntegrationConnection::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'provider' => $provider,
        'external_account_id' => $provider.'-account',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'expired-access-token',
        'refresh_token' => 'captured-refresh-token',
        'oauth_client_id' => 'captured-client-id',
        'oauth_client_secret' => 'captured-client-secret',
        'expires_at' => now()->subMinute(),
        'metadata' => array_filter([
            'oauth_client_source' => 'legacy_tenant',
            'shop_domain' => $provider === 'shopify'
                ? 'captured-shop.myshopify.com'
                : null,
        ]),
        'connected_at' => now()->subDay(),
    ]);

    Http::fake([
        $url => Http::response([
            'access_token' => 'refreshed-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'expires_in' => 3_600,
        ]),
    ]);

    expect(app(ProviderAccessTokenService::class)->token($connection))
        ->toBe('refreshed-access-token')
        ->and($connection->fresh()->refresh_token)->toBe('rotated-refresh-token')
        ->and($connection->fresh()->oauth_client_id)->toBe('captured-client-id')
        ->and($connection->fresh()->oauth_client_secret)->toBe('captured-client-secret')
        ->and($connection->toArray())->not->toHaveKeys([
            'oauth_client_id',
            'oauth_client_secret',
        ]);

    $raw = DB::table('integration_connections')->where('id', $connection->id)->first();
    expect((string) $raw->oauth_client_id)
        ->not->toContain('captured-client-id')
        ->and((string) $raw->oauth_client_secret)
        ->not->toContain('captured-client-secret');

    Http::assertSent(fn (Request $request): bool => $request->url() === $url
        && ($request['client_id'] ?? null) === 'captured-client-id'
        && ($request['client_secret'] ?? null) === 'captured-client-secret'
        && ($request['refresh_token'] ?? null) === 'captured-refresh-token'
        && ($request['grant_type'] ?? null) === 'refresh_token');
})->with([
    'Asana' => ['asana', 'https://app.asana.com/-/oauth_token'],
    'Google Calendar' => ['google_calendar', 'https://oauth2.googleapis.com/token'],
    'Shopify' => ['shopify', 'https://captured-shop.myshopify.com/admin/oauth/access_token'],
    'Square' => ['square', 'https://connect.squareup.test/oauth2/token'],
]);

test('legacy normalized connections fail closed to their tenant oauth client instead of a shared client', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Legacy OAuth tenant',
        'slug' => 'legacy-oauth-'.Str::lower((string) Str::ulid()),
    ]);
    config()->set('services.asana.oauth_client_id', 'wrong-shared-client');
    config()->set('services.asana.oauth_client_secret', 'wrong-shared-secret');
    TenantMarketingSetting::query()->create([
        'tenant_id' => $tenant->id,
        'key' => 'workflow_automation_asana_google_calendar',
        'value' => [
            'workflow_key' => 'asana_to_google_calendar',
            'credentials' => [
                'asana_oauth_client_id_encrypted' => Crypt::encryptString('legacy-tenant-client'),
                'asana_oauth_client_secret_encrypted' => Crypt::encryptString('legacy-tenant-secret'),
            ],
        ],
    ]);
    $connection = IntegrationConnection::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'asana',
        'external_account_id' => 'legacy-asana',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'refresh_token' => 'legacy-refresh-token',
        'expires_at' => now()->subMinute(),
        'metadata' => ['credential_source' => 'legacy_migration'],
        'connected_at' => now()->subDay(),
    ]);
    Http::fake([
        'https://app.asana.com/-/oauth_token' => Http::response([
            'access_token' => 'legacy-refreshed-access',
            'expires_in' => 3_600,
        ]),
    ]);

    expect(app(ProviderAccessTokenService::class)->token($connection))
        ->toBe('legacy-refreshed-access');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://app.asana.com/-/oauth_token'
        && ($request['client_id'] ?? null) === 'legacy-tenant-client'
        && ($request['client_secret'] ?? null) === 'legacy-tenant-secret'
        && ($request['client_id'] ?? null) !== 'wrong-shared-client');
});
