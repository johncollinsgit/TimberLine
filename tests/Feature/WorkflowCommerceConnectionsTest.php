<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
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
        'https://connect.squareup.com/v2/merchants/me' => Http::response(['merchant' => ['id' => 'merchant-123']]),
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
    expect($connection->fresh()->last_synced_at)->not->toBeNull();

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
