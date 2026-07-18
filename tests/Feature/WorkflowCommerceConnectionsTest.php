<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Automation\WorkflowAutomationReadinessService;
use Illuminate\Support\Facades\Http;

function commerceWorkflowTenant(string $slug): array
{
    $tenant = Tenant::query()->create(['name' => str($slug)->headline(), 'slug' => $slug]);
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'workflow_automations',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped',
        'entitlement_source' => 'entitlement',
        'price_source' => 'test',
    ]);
    $user = User::factory()->create(['role' => 'marketing_manager', 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'marketing_manager']);

    return [$tenant, $user];
}

test('square oauth connection is tenant bound reusable testable and replay safe', function (): void {
    [$tenant, $user] = commerceWorkflowTenant('commerce-connect');
    config()->set('services.square.oauth_client_id', 'square-app-id');
    config()->set('services.square.oauth_client_secret', 'square-app-secret');
    config()->set('services.square.redirect_uri', 'https://app.example.com/workflows/connections/square/callback');
    config()->set('services.square.authorization_url', 'https://connect.squareup.com/oauth2/authorize');
    config()->set('services.square.token_url', 'https://connect.squareup.com/oauth2/token');
    config()->set('services.square.oauth_scopes', 'MERCHANT_PROFILE_READ ORDERS_READ');
    config()->set('services.square.api_base', 'https://connect.squareup.com');

    $connect = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->post(route('workflows.connections.commerce.connect', 'square'))
        ->assertRedirect();
    parse_str((string) parse_url((string) $connect->headers->get('Location'), PHP_URL_QUERY), $query);
    $state = (string) ($query['state'] ?? '');
    expect($state)->not->toBe('');

    Http::fake([
        'https://connect.squareup.com/oauth2/token' => Http::response([
            'access_token' => 'square-access-token',
            'refresh_token' => 'square-refresh-token',
            'merchant_id' => 'merchant-123',
            'expires_at' => now()->addDays(30)->toIso8601String(),
            'token_type' => 'Bearer',
        ]),
        'https://connect.squareup.com/v2/merchants/me' => Http::response(['merchant' => ['id' => 'merchant-123', 'business_name' => 'Harbor Bakery']]),
        'https://connect.squareup.com/v2/locations' => Http::response(['locations' => [[
            'id' => 'location-1',
            'name' => 'Downtown counter',
            'status' => 'ACTIVE',
            'address' => ['address_line_1' => '12 Main St', 'locality' => 'Portland', 'administrative_district_level_1' => 'ME', 'postal_code' => '04101'],
        ]]]),
    ]);

    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->get(route('workflows.connections.commerce.callback', ['provider' => 'square', 'state' => $state, 'code' => 'square-code']))
        ->assertRedirect(route('workflows.connections'));

    $connection = IntegrationConnection::query()->forAllTenants()->where('tenant_id', $tenant->id)->where('provider', 'square')->firstOrFail();
    expect($connection->status)->toBe(IntegrationConnection::STATUS_CONNECTED)
        ->and($connection->external_account_secret)->toBe('merchant-123')
        ->and($connection->access_token)->toBe('square-access-token')
        ->and($connection->toArray())->not->toHaveKeys(['access_token', 'refresh_token', 'external_account_secret']);

    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->post(route('workflows.connections.commerce.test', ['provider' => 'square', 'connection' => $connection]))
        ->assertRedirect();
    expect($connection->fresh()->last_synced_at)->not->toBeNull()
        ->and(data_get($connection->fresh()->metadata, 'locations.0.id'))->toBe('location-1')
        ->and(data_get($connection->fresh()->metadata, 'locations.0.label'))->toBe('Downtown counter');

    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->get(route('workflows.connections.commerce.callback', ['provider' => 'square', 'state' => $state, 'code' => 'replay']))
        ->assertRedirect(route('workflows.connections'))
        ->assertSessionHas('toast', fn (array $toast): bool => str_contains((string) ($toast['message'] ?? ''), 'expired or was already used'));
    expect(IntegrationConnection::query()->forAllTenants()->where('tenant_id', $tenant->id)->where('provider', 'square')->count())->toBe(1);

    [$otherTenant, $otherUser] = commerceWorkflowTenant('commerce-forged');
    $this->actingAs($otherUser)->withSession(['tenant_id' => $otherTenant->id])
        ->post(route('workflows.connections.commerce.disconnect', ['provider' => 'square', 'connection' => $connection]))
        ->assertNotFound();
});

test('shopify oauth verifies the shop signature and stores a tenant safe reusable connection', function (): void {
    [$tenant, $user] = commerceWorkflowTenant('shopify-connect');
    config()->set('services.shopify.automation_oauth_client_id', 'shopify-app-id');
    config()->set('services.shopify.automation_oauth_client_secret', 'shopify-app-secret');
    config()->set('services.shopify.automation_redirect_uri', 'https://app.example.com/workflows/connections/shopify/callback');
    config()->set('services.shopify.automation_oauth_scopes', 'read_orders');
    config()->set('services.shopify.automation_api_version', '2026-07');

    $connect = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->post(route('workflows.connections.commerce.connect', 'shopify'), ['shop_domain' => 'harbor-bakery.myshopify.com'])
        ->assertRedirect();
    parse_str((string) parse_url((string) $connect->headers->get('Location'), PHP_URL_QUERY), $authorization);
    expect($authorization['scope'] ?? null)->toBe('read_orders')
        ->and($authorization['redirect_uri'] ?? null)->toBe('https://app.example.com/workflows/connections/shopify/callback');

    $callback = [
        'code' => 'shopify-code',
        'shop' => 'harbor-bakery.myshopify.com',
        'state' => (string) $authorization['state'],
        'timestamp' => '1784412000',
    ];
    ksort($callback);
    $callback['hmac'] = hash_hmac('sha256', http_build_query($callback, '', '&', PHP_QUERY_RFC3986), 'shopify-app-secret');

    Http::fake([
        'https://harbor-bakery.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shopify-access-token', 'scope' => 'read_orders']),
        'https://harbor-bakery.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['shop' => [
            'id' => 'gid://shopify/Shop/123',
            'name' => 'Harbor Bakery',
            'myshopifyDomain' => 'harbor-bakery.myshopify.com',
        ]]]),
    ]);

    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->get(route('workflows.connections.commerce.callback', ['provider' => 'shopify'] + $callback))
        ->assertRedirect(route('workflows.connections'));

    $connection = IntegrationConnection::query()->forAllTenants()
        ->where('tenant_id', $tenant->id)
        ->where('provider', 'shopify')
        ->firstOrFail();
    expect($connection->external_account_secret)->toBe('harbor-bakery.myshopify.com')
        ->and($connection->external_account_label)->toBe('Harbor Bakery')
        ->and($connection->access_token)->toBe('shopify-access-token')
        ->and($connection->toArray())->not->toHaveKeys(['access_token', 'refresh_token', 'external_account_secret']);

    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->post(route('workflows.connections.commerce.test', ['provider' => 'shopify', 'connection' => $connection]))
        ->assertRedirect();

    $forgedConnect = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->post(route('workflows.connections.commerce.connect', 'shopify'), ['shop_domain' => 'forged-shop.myshopify.com'])
        ->assertRedirect();
    parse_str((string) parse_url((string) $forgedConnect->headers->get('Location'), PHP_URL_QUERY), $forgedAuthorization);
    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->get(route('workflows.connections.commerce.callback', [
            'provider' => 'shopify',
            'code' => 'forged-code',
            'shop' => 'forged-shop.myshopify.com',
            'state' => (string) $forgedAuthorization['state'],
            'timestamp' => '1784412001',
            'hmac' => 'forged-hmac',
        ]))
        ->assertRedirect(route('workflows.connections'))
        ->assertSessionHas('toast', fn (array $toast): bool => str_contains((string) ($toast['message'] ?? ''), 'signature could not be verified'));
    expect(IntegrationConnection::query()->forAllTenants()->where('tenant_id', $tenant->id)->where('provider', 'shopify')->count())->toBe(1);
});

test('commerce templates fail readiness until provider registration and customer data gates pass', function (): void {
    config()->set('automation_workflows.templates.shopify_order_to_google_calendar.launchable', true);
    config()->set('services.shopify.automation_oauth_client_id', 'shopify-app-id');
    config()->set('services.shopify.automation_oauth_client_secret', 'shopify-app-secret');
    config()->set('services.shopify.automation_redirect_uri', 'https://app.example.com/workflows/connections/shopify/callback');
    config()->set('services.shopify.automation_oauth_scopes', 'read_orders');
    config()->set('services.shopify.automation_api_version', '2026-07');
    config()->set('services.shopify.automation_protected_customer_data_approved', false);

    $checks = app(WorkflowAutomationReadinessService::class)->evaluate()['checks'];
    expect(data_get($checks, 'shopify_order_connector.ready'))->toBeFalse();

    config()->set('services.shopify.automation_protected_customer_data_approved', true);
    $checks = app(WorkflowAutomationReadinessService::class)->evaluate()['checks'];
    expect(data_get($checks, 'shopify_order_connector.ready'))->toBeTrue();

    config()->set('automation_workflows.templates.square_order_to_google_calendar.launchable', true);
    config()->set('services.square.oauth_client_id', 'square-app-id');
    config()->set('services.square.oauth_client_secret', 'square-app-secret');
    config()->set('services.square.redirect_uri', 'https://app.example.com/workflows/connections/square/callback');
    config()->set('services.square.oauth_scopes', 'ORDERS_READ');
    config()->set('services.square.api_version', '2026-05-20');
    $checks = app(WorkflowAutomationReadinessService::class)->evaluate()['checks'];
    expect(data_get($checks, 'square_order_connector.ready'))->toBeFalse();

    config()->set('services.square.oauth_scopes', 'ORDERS_READ MERCHANT_PROFILE_READ');
    $checks = app(WorkflowAutomationReadinessService::class)->evaluate()['checks'];
    expect(data_get($checks, 'square_order_connector.ready'))->toBeTrue();

    config()->set('automation_workflows.templates.squarespace_order_to_google_calendar.launchable', true);
    config()->set('services.squarespace.oauth_client_id', 'squarespace-client');
    config()->set('services.squarespace.oauth_client_secret', 'squarespace-secret');
    config()->set('services.squarespace.redirect_uri', 'https://app.example.com/workflows/connections/squarespace/callback');
    config()->set('services.squarespace.oauth_scopes', 'website.orders.read');
    config()->set('services.squarespace.user_agent', 'Everbranch Order Calendar/1.0');
    $checks = app(WorkflowAutomationReadinessService::class)->evaluate()['checks'];
    expect(data_get($checks, 'squarespace_order_connector.ready'))->toBeTrue();

    config()->set('automation_workflows.templates.wix_order_to_google_calendar.launchable', true);
    config()->set('services.wix.app_id', 'wix-app');
    config()->set('services.wix.client_secret', 'wix-secret');
    config()->set('services.wix.install_url', 'https://www.wix.com/installer/install');
    config()->set('services.wix.redirect_uri', 'https://app.example.com/workflows/connections/wix/callback');
    config()->set('services.wix.required_permission', 'Read Orders');
    $checks = app(WorkflowAutomationReadinessService::class)->evaluate()['checks'];
    expect(data_get($checks, 'wix_order_connector.ready'))->toBeTrue();
});

test('squarespace oauth uses offline least privilege access and rotates refresh tokens', function (): void {
    [$tenant, $user] = commerceWorkflowTenant('squarespace-connect');
    config()->set('services.squarespace.oauth_client_id', 'squarespace-client');
    config()->set('services.squarespace.oauth_client_secret', 'squarespace-secret');
    config()->set('services.squarespace.redirect_uri', 'https://app.example.com/workflows/connections/squarespace/callback');
    config()->set('services.squarespace.authorization_url', 'https://login.squarespace.com/api/1/login/oauth/provider/authorize');
    config()->set('services.squarespace.token_url', 'https://login.squarespace.com/api/1/login/oauth/provider/tokens');
    config()->set('services.squarespace.api_base', 'https://api.squarespace.com');
    config()->set('services.squarespace.oauth_scopes', 'website.orders.read');
    config()->set('services.squarespace.user_agent', 'Everbranch Order Calendar/1.0');

    $connect = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->post(route('workflows.connections.commerce.connect', 'squarespace'))
        ->assertRedirect();
    parse_str((string) parse_url((string) $connect->headers->get('Location'), PHP_URL_QUERY), $authorization);
    expect($authorization['scope'] ?? null)->toBe('website.orders.read')
        ->and($authorization['access_type'] ?? null)->toBe('offline');

    Http::fake([
        'https://login.squarespace.com/api/1/login/oauth/provider/tokens' => Http::response([
            'access_token' => 'squarespace-access',
            'refresh_token' => 'squarespace-refresh',
            'access_token_expires_at' => (string) now()->addMinutes(30)->getTimestamp(),
            'token_type' => 'bearer',
        ]),
        'https://api.squarespace.com/1.0/authorization/website' => Http::response([
            'id' => 'website-123', 'siteId' => 'harbor-bakery', 'title' => 'Harbor Bakery',
            'url' => 'https://harborbakery.example', 'timeZone' => 'America/New_York',
        ]),
    ]);

    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->get(route('workflows.connections.commerce.callback', [
            'provider' => 'squarespace', 'state' => $authorization['state'], 'code' => 'squarespace-code',
        ]))
        ->assertRedirect(route('workflows.connections'));

    $connection = IntegrationConnection::query()->forAllTenants()->where('tenant_id', $tenant->id)->where('provider', 'squarespace')->firstOrFail();
    expect($connection->external_account_secret)->toBe('website-123')
        ->and($connection->external_account_label)->toBe('Harbor Bakery')
        ->and(data_get($connection->metadata, 'site_url'))->toBe('https://harborbakery.example')
        ->and($connection->refresh_token)->toBe('squarespace-refresh');

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://login.squarespace.com/api/1/login/oauth/provider/tokens'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('squarespace-client:squarespace-secret'))
        && $request->hasHeader('User-Agent', 'Everbranch Order Calendar/1.0'));
});

test('wix installation binds an app instance to the tenant without exposing credentials', function (): void {
    [$tenant, $user] = commerceWorkflowTenant('wix-connect');
    config()->set('services.wix.app_id', 'wix-app');
    config()->set('services.wix.client_secret', 'wix-secret');
    config()->set('services.wix.install_url', 'https://www.wix.com/installer/install');
    config()->set('services.wix.redirect_uri', 'https://app.example.com/workflows/connections/wix/callback');
    config()->set('services.wix.token_url', 'https://www.wixapis.com/oauth2/token');
    config()->set('services.wix.api_base', 'https://www.wixapis.com');

    $connect = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->post(route('workflows.connections.commerce.connect', 'wix'))
        ->assertRedirect();
    parse_str((string) parse_url((string) $connect->headers->get('Location'), PHP_URL_QUERY), $authorization);
    expect($authorization['state'] ?? null)->not->toBeEmpty()
        ->and($authorization['redirectUrl'] ?? null)->toBe('https://app.example.com/workflows/connections/wix/callback');

    Http::fake([
        'https://www.wixapis.com/oauth2/token' => Http::response([
            'access_token' => 'wix-access', 'expires_in' => 14400, 'token_type' => 'Bearer',
        ]),
        'https://www.wixapis.com/site-properties/v4/properties' => Http::response(['properties' => [
            'businessName' => 'Harbor Wix Shop', 'siteUrl' => 'https://harbor-wix.example',
        ]]),
    ]);

    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->get(route('workflows.connections.commerce.callback', [
            'provider' => 'wix', 'state' => $authorization['state'], 'instanceId' => 'wix-instance-55',
        ]))
        ->assertRedirect(route('workflows.connections'));

    $connection = IntegrationConnection::query()->forAllTenants()->where('tenant_id', $tenant->id)->where('provider', 'wix')->firstOrFail();
    expect($connection->external_account_secret)->toBe('wix-instance-55')
        ->and($connection->external_account_label)->toBe('Harbor Wix Shop')
        ->and(data_get($connection->metadata, 'site_url'))->toBe('https://harbor-wix.example')
        ->and($connection->toArray())->not->toHaveKeys(['access_token', 'refresh_token', 'external_account_secret']);
});
