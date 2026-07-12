<?php

use App\Models\FieldServiceJob;
use App\Models\FieldServiceMaterial;
use App\Models\IntegrationConnection;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('services.quickbooks.api_base', 'https://sandbox-quickbooks.api.intuit.com');
    config()->set('services.quickbooks.client_id', 'qbo-client-id');
    config()->set('services.quickbooks.client_secret', 'qbo-client-secret');
    config()->set('services.quickbooks.redirect_uri', 'https://app.test/integrations/quickbooks/callback');
    config()->set('services.quickbooks.minor_version', 75);
});

test('quickbooks oauth connect and callback store tenant connection', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Collins Upstate Electric', 'slug' => 'collins-upstate-electric']);
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);

    $redirect = $this->actingAs($user)
        ->get(route('integrations.quickbooks.connect', ['tenant' => $tenant->slug]))
        ->assertRedirect()
        ->headers->get('Location');

    expect($redirect)->toStartWith('https://appcenter.intuit.com/connect/oauth2?')
        ->and($redirect)->toContain('client_id=qbo-client-id')
        ->and($redirect)->toContain('scope=com.intuit.quickbooks.accounting');

    parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);
    $state = (string) ($query['state'] ?? '');
    expect($state)->not->toBe('');

    Http::fake([
        'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
            'access_token' => 'qbo-access-token',
            'refresh_token' => 'qbo-refresh-token',
            'token_type' => 'bearer',
            'expires_in' => 3600,
        ], 200),
    ]);

    $callback = $this->actingAs($user)
        ->get(route('integrations.quickbooks.callback', [
            'state' => $state,
            'code' => 'oauth-code',
            'realmId' => '1234567890',
        ]));
    $callback->assertRedirect(route('field-service.index', ['tenant' => $tenant->slug]));

    $connection = IntegrationConnection::query()
        ->forTenantId($tenant->id)
        ->where('provider', 'quickbooks')
        ->firstOrFail();

    expect($connection->external_account_id)->not->toBe('1234567890')
        ->and($connection->external_account_secret)->toBe('1234567890')
        ->and(data_get($connection->metadata, 'realm_id'))->toBeNull()
        ->and($connection->access_token)->toBe('qbo-access-token')
        ->and((int) $connection->connected_by_user_id)->toBe((int) $user->id);
});

test('quickbooks api sync imports collins electric customers jobs items and recommends cards', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Collins Upstate Electric', 'slug' => 'collins-upstate-electric']);
    IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'quickbooks',
        'external_account_id' => hash_hmac('sha256', '1234567890', (string) config('app.key')),
        'external_account_secret' => '1234567890',
        'external_account_label' => 'Collins Upstate Electric QBO',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'qbo-access-token',
        'refresh_token' => 'qbo-refresh-token',
        'expires_at' => now()->addHour(),
        'metadata' => ['source' => 'quickbooks_oauth'],
    ]);

    Http::fake(function (Request $request) {
        $url = urldecode($request->url());

        if (str_contains($url, 'from Customer')) {
            return Http::response(['QueryResponse' => ['Customer' => [[
                'Id' => '101',
                'DisplayName' => 'Bob Homeowner',
                'PrimaryEmailAddr' => ['Address' => 'bob@example.com'],
                'PrimaryPhone' => ['FreeFormNumber' => '555-123-4567'],
                'BillAddr' => ['Line1' => '10 Panel Rd', 'City' => 'Greenville', 'CountrySubDivisionCode' => 'SC', 'PostalCode' => '29601'],
            ]]]], 200);
        }

        if (str_contains($url, 'from Invoice')) {
            return Http::response(['QueryResponse' => ['Invoice' => [[
                'Id' => 'INV-1',
                'DocNumber' => '1001',
                'CustomerRef' => ['value' => '101', 'name' => 'Bob Homeowner'],
                'TotalAmt' => 1250,
                'Balance' => 250,
                'ShipAddr' => ['Line1' => '88 Breaker Ave', 'City' => 'Greenville', 'CountrySubDivisionCode' => 'SC', 'PostalCode' => '29607'],
                'Line' => [['Description' => 'Replace failed outdoor disconnect.']],
            ]]]], 200);
        }

        if (str_contains($url, 'from Estimate')) {
            return Http::response(['QueryResponse' => ['Estimate' => [[
                'Id' => 'EST-1',
                'DocNumber' => '2001',
                'CustomerRef' => ['value' => '101', 'name' => 'Bob Homeowner'],
                'TotalAmt' => 2400,
                'ShipAddr' => ['Line1' => '90 Breaker Ave', 'City' => 'Greenville', 'CountrySubDivisionCode' => 'SC', 'PostalCode' => '29607'],
                'CustomerMemo' => ['value' => 'Quote for EV charger circuit.'],
            ]]]], 200);
        }

        if (str_contains($url, 'from Item')) {
            return Http::response(['QueryResponse' => ['Item' => [[
                'Id' => 'ITEM-20A',
                'Name' => '20A breaker',
                'Sku' => 'BRK-20A',
                'PurchaseCost' => 12.5,
                'QtyOnHand' => 4,
            ]]]], 200);
        }

        return Http::response(['QueryResponse' => []], 200);
    });

    $this->artisan('field-service:sync-quickbooks', [
        '--tenant' => $tenant->slug,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('quickbooks_customers=1')
        ->expectsOutputToContain('quickbooks_invoices=1')
        ->expectsOutputToContain('quickbooks_estimates=1')
        ->expectsOutputToContain('quickbooks_items=1')
        ->expectsOutputToContain('Open job pipeline')
        ->expectsOutputToContain('Supplies used this month')
        ->expectsOutputToContain('QuickBooks sync health');

    expect(MarketingProfile::query()->where('tenant_id', $tenant->id)->where('normalized_email', 'bob@example.com')->exists())->toBeTrue()
        ->and(FieldServiceJob::query()->where('tenant_id', $tenant->id)->where('external_source', 'quickbooks')->count())->toBe(2)
        ->and(FieldServiceMaterial::query()->where('tenant_id', $tenant->id)->where('external_source', 'quickbooks')->count())->toBe(1);

    $invoiceJob = FieldServiceJob::query()->where('tenant_id', $tenant->id)->where('external_id', $tenant->id.':1001')->firstOrFail();
    expect($invoiceJob->service_address_line_1)->toBe('88 Breaker Ave')
        ->and(data_get($invoiceJob->metadata, 'quickbooks_import.amount'))->toBe('1250');
});

test('quickbooks api sync dry run does not write imported records', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Dry Run Electric', 'slug' => 'dry-run-electric']);
    IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'quickbooks',
        'external_account_id' => hash_hmac('sha256', '1234567890', (string) config('app.key')),
        'external_account_secret' => '1234567890',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'qbo-access-token',
        'refresh_token' => 'qbo-refresh-token',
        'expires_at' => now()->addHour(),
        'metadata' => ['source' => 'quickbooks_oauth'],
    ]);

    Http::fake(['https://sandbox-quickbooks.api.intuit.com/*' => Http::response([
        'QueryResponse' => ['Customer' => [['Id' => '101', 'DisplayName' => 'Dry Customer']]],
    ], 200)]);

    $this->artisan('field-service:sync-quickbooks', [
        '--tenant' => $tenant->slug,
        '--entities' => 'customers',
        '--dry-run' => true,
    ])->assertSuccessful()->expectsOutputToContain('mode=dry-run');

    expect(MarketingProfile::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});
