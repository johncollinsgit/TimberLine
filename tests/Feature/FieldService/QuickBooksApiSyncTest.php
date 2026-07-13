<?php

use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceFinancialDocumentLine;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServicePriceBookItem;
use App\Models\IntegrationConnection;
use App\Models\MarketingProfile;
use App\Models\QuickBooksAuditRun;
use App\Models\QuickBooksSourceRecord;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\FieldService\QuickBooksDiscoveryAuditService;
use App\Services\FieldService\QuickBooksFieldServiceImportService;
use App\Services\Integrations\QuickBooks\QuickBooksOnlineClient;
use App\Services\Search\Providers\FieldServiceSearchProvider;
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

function enableQuickBooksBranchForApiTest(Tenant $tenant): void
{
    TenantModuleEntitlement::query()->updateOrCreate(
        ['tenant_id' => $tenant->id, 'module_key' => 'quickbooks'],
        [
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'included_in_plan',
            'entitlement_source' => 'test',
            'price_source' => 'catalog',
        ]
    );
}

test('quickbooks oauth connect and callback store tenant connection', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Collins Upstate Electric', 'slug' => 'collins-upstate-electric']);
    enableQuickBooksBranchForApiTest($tenant);
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

    $this->actingAs($user)
        ->get(route('integrations.quickbooks.callback', [
            'state' => $state,
            'code' => 'oauth-code-replay',
            'realmId' => '1234567890',
        ]))
        ->assertForbidden();

    $connection = IntegrationConnection::query()
        ->forTenantId($tenant->id)
        ->where('provider', 'quickbooks')
        ->firstOrFail();

    expect($connection->external_account_id)->not->toBe('1234567890')
        ->and($connection->external_account_secret)->toBe('1234567890')
        ->and(data_get($connection->metadata, 'realm_id'))->toBeNull()
        ->and($connection->access_token)->toBe('qbo-access-token')
        ->and((int) $connection->connected_by_user_id)->toBe((int) $user->id);

    Http::assertSentCount(1);
});

test('quickbooks oauth denies a tenant team member', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);
    $member = User::factory()->create(['role' => 'member', 'email_verified_at' => now()]);
    $member->tenants()->attach($tenant->id, ['role' => 'member']);

    $this->actingAs($member)
        ->get(route('integrations.quickbooks.connect', ['tenant' => $tenant->slug]))
        ->assertForbidden();
});

test('quickbooks oauth denies an admin until the branch is enabled', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Unentitled Electric', 'slug' => 'unentitled-electric']);
    $admin = User::factory()->tenantAdmin()->create();
    $admin->tenants()->attach($tenant->id, ['role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('integrations.quickbooks.connect', ['tenant' => $tenant->slug]))
        ->assertForbidden();
});

test('quickbooks api sync imports collins electric customers jobs items and recommends cards', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Collins Upstate Electric', 'slug' => 'collins-upstate-electric']);
    enableQuickBooksBranchForApiTest($tenant);
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
                'PrivateNote' => 'Crew should check the existing panel labeling.',
                'ShipAddr' => ['Line1' => '88 Breaker Ave', 'City' => 'Greenville', 'CountrySubDivisionCode' => 'SC', 'PostalCode' => '29607'],
                'Line' => [[
                    'Id' => '1',
                    'DetailType' => 'SalesItemLineDetail',
                    'Description' => 'Replace failed outdoor disconnect.',
                    'Amount' => 1250,
                    'SalesItemLineDetail' => ['ItemRef' => ['value' => 'ITEM-SVC', 'name' => 'Electrical service'], 'Qty' => 1, 'UnitPrice' => 1250],
                ]],
            ]]]], 200);
        }

        if (str_contains($url, 'from Estimate')) {
            return Http::response(['QueryResponse' => ['Estimate' => [[
                'Id' => 'EST-1',
                'DocNumber' => '1001',
                'CustomerRef' => ['value' => '101', 'name' => 'Bob Homeowner'],
                'TotalAmt' => 2400,
                'ShipAddr' => ['Line1' => '90 Breaker Ave', 'City' => 'Greenville', 'CountrySubDivisionCode' => 'SC', 'PostalCode' => '29607'],
                'CustomerMemo' => ['value' => 'Quote for EV charger circuit.'],
                'Line' => [[
                    'Id' => '1',
                    'DetailType' => 'SalesItemLineDetail',
                    'Description' => 'Install EV charger circuit.',
                    'Amount' => 2400,
                    'SalesItemLineDetail' => ['ItemRef' => ['value' => 'ITEM-SVC', 'name' => 'Electrical service'], 'Qty' => 1, 'UnitPrice' => 2400],
                ]],
            ]]]], 200);
        }

        if (str_contains($url, 'from Item')) {
            return Http::response(['QueryResponse' => ['Item' => [[
                'Id' => 'ITEM-20A',
                'Name' => '20A breaker',
                'Type' => 'Service',
                'Sku' => 'BRK-20A',
                'UnitPrice' => 85,
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

    $this->artisan('field-service:sync-quickbooks', ['--tenant' => $tenant->slug])->assertSuccessful();

    expect(MarketingProfile::query()->where('tenant_id', $tenant->id)->where('normalized_email', 'bob@example.com')->exists())->toBeTrue()
        ->and(FieldServiceJob::query()->where('tenant_id', $tenant->id)->where('external_source', 'quickbooks')->count())->toBe(2)
        ->and(FieldServiceMaterial::query()->where('tenant_id', $tenant->id)->where('external_source', 'quickbooks')->count())->toBe(0)
        ->and(FieldServicePriceBookItem::query()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(FieldServiceFinancialDocument::query()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(FieldServiceFinancialDocumentLine::query()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(FieldServiceJobNote::query()->where('tenant_id', $tenant->id)->count())->toBe(4);

    $invoiceJob = FieldServiceJob::query()->where('tenant_id', $tenant->id)->where('external_id', 'quickbooks:invoice:INV-1')->firstOrFail();
    expect($invoiceJob->service_address_line_1)->toBe('88 Breaker Ave')
        ->and(data_get($invoiceJob->metadata, 'gross_revenue'))->toBe(1250)
        ->and(FieldServiceJob::query()->where('tenant_id', $tenant->id)->where('external_id', 'quickbooks:estimate:EST-1')->exists())->toBeTrue();

    $owner = User::factory()->tenantAdmin()->create();
    $member = User::factory()->create(['role' => 'member', 'email_verified_at' => now()]);
    $owner->tenants()->attach($tenant->id, ['role' => 'admin']);
    $member->tenants()->attach($tenant->id, ['role' => 'member']);
    $search = app(FieldServiceSearchProvider::class);

    expect(FieldServiceJobNote::query()
        ->where('tenant_id', $tenant->id)
        ->where('metadata->note_type', 'private_note')
        ->where('metadata->visibility', 'owner')
        ->exists())->toBeTrue()
        ->and($search->search('panel labeling', ['tenant_id' => $tenant->id, 'user' => $owner]))->not->toBeEmpty()
        ->and($search->search('panel labeling', ['tenant_id' => $tenant->id, 'user' => $member]))->toBeEmpty()
        ->and($search->search('outdoor disconnect', ['tenant_id' => $tenant->id, 'user' => $member]))->not->toBeEmpty();
});

test('quickbooks api sync dry run does not write imported records', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Dry Run Electric', 'slug' => 'dry-run-electric']);
    enableQuickBooksBranchForApiTest($tenant);
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

test('line descriptions stay searchable without manufacturing a field job', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Review Electric', 'slug' => 'review-electric']);
    enableQuickBooksBranchForApiTest($tenant);
    $owner = User::factory()->tenantAdmin()->create();
    $member = User::factory()->create(['role' => 'member', 'email_verified_at' => now()]);
    $owner->tenants()->attach($tenant->id, ['role' => 'admin']);
    $member->tenants()->attach($tenant->id, ['role' => 'member']);

    $summary = app(QuickBooksFieldServiceImportService::class)->importQuickBooksTransaction($tenant, [
        'Id' => 'LINE-ONLY-1',
        'DocNumber' => '2002',
        'TxnDate' => '2026-07-01',
        'CustomerRef' => ['value' => 'C-200', 'name' => 'Review Customer'],
        'TotalAmt' => 475,
        'Balance' => 0,
        'Line' => [[
            'Id' => '1',
            'DetailType' => 'SalesItemLineDetail',
            'Description' => 'Replace weathered exterior receptacle.',
            'Amount' => 475,
            'SalesItemLineDetail' => ['ItemRef' => ['value' => 'SVC-1', 'name' => 'Electrical service']],
        ]],
    ], 'invoice');

    $document = FieldServiceFinancialDocument::query()->forTenantId($tenant->id)->sole();
    expect($summary['documents_needing_review'])->toBe(1)
        ->and($summary['jobs_created'])->toBe(0)
        ->and(FieldServiceJob::query()->forTenantId($tenant->id)->count())->toBe(0)
        ->and(data_get($document->metadata, 'quickbooks.job_link_status'))->toBe('needs_review')
        ->and(data_get($document->metadata, 'quickbooks.job_link_reason'))->toBe('insufficient_operational_evidence');

    $search = app(FieldServiceSearchProvider::class);
    $ownerResults = $search->search('weathered exterior', ['tenant_id' => $tenant->id, 'user' => $owner]);
    expect($ownerResults)->toHaveCount(1)
        ->and($ownerResults[0]['subtype'])->toBe('quickbooks_document')
        ->and($search->search('weathered exterior', ['tenant_id' => $tenant->id, 'user' => $member]))->toBeEmpty();

    $this->actingAs($owner)
        ->get(route('integrations.quickbooks.documents.show', ['tenant' => $tenant->slug, 'document' => $document]))
        ->assertOk()
        ->assertSee('Replace weathered exterior receptacle.');

    $this->actingAs($member)
        ->get(route('integrations.quickbooks.documents.show', ['tenant' => $tenant->slug, 'document' => $document]))
        ->assertForbidden();
});

test('full quickbooks audit dry run reports aggregates without storing or printing private records', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);
    enableQuickBooksBranchForApiTest($tenant);
    IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'quickbooks',
        'external_account_id' => hash_hmac('sha256', 'realm', (string) config('app.key')),
        'external_account_secret' => 'realm',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'qbo-access-token',
        'refresh_token' => 'qbo-refresh-token',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake(function (Request $request) {
        $url = urldecode($request->url());
        if (str_contains($url, '/reports/ProfitAndLoss')) {
            return Http::response(['Rows' => ['Row' => [
                ['ColData' => [['value' => 'Wages'], ['value' => '12500.00']]],
                ['ColData' => [['value' => 'Contract Labor'], ['value' => '100000.00']]],
            ]]], 200);
        }
        if (str_contains($url, '/reports/AgedReceivables')) {
            return Http::response(['Rows' => ['Row' => []]], 200);
        }
        if (str_contains($url, 'from Customer')) {
            return Http::response(['QueryResponse' => ['Customer' => [[
                'Id' => 'C-1',
                'DisplayName' => 'Private Customer Name',
                'PrimaryPhone' => ['FreeFormNumber' => '555-0100'],
            ]]]], 200);
        }
        if (str_contains($url, 'from Invoice')) {
            return Http::response(['QueryResponse' => ['Invoice' => [[
                'Id' => 'I-1',
                'PrivateNote' => 'Sensitive invoice field note must never print.',
                'TotalAmt' => 900,
                'Balance' => 100,
                'Line' => [[
                    'DetailType' => 'SalesItemLineDetail',
                    'Description' => 'Panel work',
                    'Amount' => 900,
                    'SalesItemLineDetail' => ['ItemRef' => ['name' => 'Panel service'], 'Qty' => 1, 'UnitPrice' => 900],
                ]],
            ]]]], 200);
        }

        return Http::response(['QueryResponse' => []], 200);
    });

    $this->artisan('field-service:audit-quickbooks', [
        '--tenant' => $tenant->slug,
        '--full' => true,
        '--dry-run' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Invoice=1')
        ->expectsOutputToContain('profit_and_loss_wage_lines":1')
        ->doesntExpectOutputToContain('Sensitive invoice field note')
        ->doesntExpectOutputToContain('Private Customer Name');

    $connection = IntegrationConnection::query()->where('tenant_id', $tenant->id)->sole();
    $summary = app(QuickBooksDiscoveryAuditService::class)->audit(
        $tenant,
        $connection,
        new QuickBooksOnlineClient($connection, (string) config('services.quickbooks.api_base'), 75),
        true,
        true
    );

    expect(data_get($summary, 'labor_signals.profit_and_loss_contract_labor_lines'))->toBe(1)
        ->and(data_get($summary, 'labor_signals.profit_and_loss_contract_labor_total'))->toBe(100000.0)
        ->and(QuickBooksAuditRun::query()->count())->toBe(0)
        ->and(QuickBooksSourceRecord::query()->count())->toBe(0)
        ->and(FieldServiceFinancialDocument::query()->count())->toBe(0);
});
