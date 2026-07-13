<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('dynamic web responses prohibit browser and intermediary caching', function (): void {
    $response = $this->get('https://app.theeverbranch.com/login');

    $response->assertOk();
    $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));

    expect($cacheControl)
        ->toContain('no-store')
        ->toContain('no-cache');
});

test('quickbooks callback stores realm id and oauth tokens encrypted at rest and returns a bodyless redirect', function (): void {
    config()->set('services.quickbooks.client_id', 'client-id');
    config()->set('services.quickbooks.client_secret', 'client-secret');
    config()->set('services.quickbooks.redirect_uri', 'https://app.theeverbranch.com/integrations/quickbooks/callback');
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'quickbooks',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'included_in_plan',
        'entitlement_source' => 'test',
        'price_source' => 'catalog',
    ]);
    $user = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);

    Http::fake(['https://oauth.platform.intuit.com/*' => Http::response([
        'access_token' => 'sensitive-access-token',
        'refresh_token' => 'sensitive-refresh-token',
        'token_type' => 'bearer',
        'expires_in' => 3600,
    ])]);

    $connect = $this->actingAs($user)->get(route('integrations.quickbooks.connect', ['tenant' => $tenant]));
    parse_str((string) parse_url((string) $connect->headers->get('Location'), PHP_URL_QUERY), $query);

    $callback = $this->get(route('integrations.quickbooks.callback', [
        'state' => $query['state'],
        'code' => 'authorization-code',
        'realmId' => '1234567890',
    ]));

    $callback->assertRedirect(route('field-service.index', ['tenant' => $tenant->slug]));
    expect($callback->getContent())->toBe('');

    $connection = IntegrationConnection::query()->where('tenant_id', $tenant->id)->where('provider', 'quickbooks')->sole();
    $raw = DB::table('integration_connections')->where('id', $connection->id)->first();

    expect($connection->external_account_secret)->toBe('1234567890')
        ->and($connection->access_token)->toBe('sensitive-access-token')
        ->and($connection->refresh_token)->toBe('sensitive-refresh-token')
        ->and((string) $raw->external_account_id)->not->toContain('1234567890')
        ->and((string) $raw->external_account_secret)->not->toContain('1234567890')
        ->and((string) $raw->access_token)->not->toContain('sensitive-access-token')
        ->and((string) $raw->refresh_token)->not->toContain('sensitive-refresh-token')
        ->and((string) $raw->metadata)->not->toContain('1234567890')
        ->and((string) $raw->external_account_label)->not->toContain('1234567890');
});

test('realm encryption migration backfills a legacy plaintext quickbooks connection', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Legacy Electric', 'slug' => 'legacy-electric']);
    DB::table('integration_connections')->insert([
        'tenant_id' => $tenant->id,
        'provider' => 'quickbooks',
        'external_account_id' => 'legacy-realm-123',
        'external_account_secret' => null,
        'external_account_label' => 'QuickBooks company legacy-realm-123',
        'status' => 'connected',
        'metadata' => json_encode(['realm_id' => 'legacy-realm-123', 'source' => 'quickbooks_oauth']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_07_11_235500_encrypt_sensitive_integration_account_ids.php');
    $migration->down();
    $migration->up();

    $connection = IntegrationConnection::query()->where('tenant_id', $tenant->id)->sole();
    $raw = DB::table('integration_connections')->where('id', $connection->id)->first();

    expect($connection->external_account_secret)->toBe('legacy-realm-123')
        ->and((string) $raw->external_account_id)->not->toContain('legacy-realm-123')
        ->and((string) $raw->external_account_secret)->not->toContain('legacy-realm-123')
        ->and((string) $raw->metadata)->not->toContain('legacy-realm-123')
        ->and((string) $raw->external_account_label)->not->toContain('legacy-realm-123');
});
