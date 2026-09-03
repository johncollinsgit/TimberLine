<?php

use App\Models\CustomerEquipment;
use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceFinancialDocumentLine;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServicePriceBookItem;
use App\Models\IntegrationConnection;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\QuickBooksAuditRun;
use App\Models\QuickBooksSourceRecord;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\FieldService\FieldServiceWorkCandidateService;
use App\Services\FieldService\QuickBooksDiscoveryAuditService;
use App\Services\FieldService\QuickBooksFieldServiceImportService;
use App\Services\FieldService\QuickBooksFieldServiceSyncService;
use App\Services\FieldService\QuickBooksGeneratorEquipmentService;
use App\Services\Integrations\QuickBooks\QuickBooksOnlineClient;
use App\Services\Search\Providers\FieldServiceSearchProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        ->and(FieldServiceJob::query()->where('tenant_id', $tenant->id)->where('external_source', 'quickbooks')->count())->toBe(0)
        ->and(FieldServiceMaterial::query()->where('tenant_id', $tenant->id)->where('external_source', 'quickbooks')->count())->toBe(0)
        ->and(FieldServicePriceBookItem::query()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(FieldServiceFinancialDocument::query()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(FieldServiceFinancialDocumentLine::query()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(FieldServiceJobNote::query()->where('tenant_id', $tenant->id)->count())->toBe(0);

    $owner = User::factory()->tenantAdmin()->create();
    $member = User::factory()->create(['role' => 'member', 'email_verified_at' => now()]);
    $owner->tenants()->attach($tenant->id, ['role' => 'admin']);
    $member->tenants()->attach($tenant->id, ['role' => 'member']);
    $drafts = app(FieldServiceWorkCandidateService::class)->pending($tenant);
    expect($drafts)->toHaveCount(2)
        ->and($drafts->firstWhere('external_id', 'INV-1')?->service_address_line_1)->toBe('88 Breaker Ave')
        ->and($drafts->firstWhere('external_id', 'EST-1')?->service_address_line_1)->toBe('90 Breaker Ave');
    $invoiceDraft = $drafts->firstWhere('external_id', 'INV-1');
    $invoiceDraft->forceFill(['title' => 'Outdoor disconnect replacement', 'participant_user_ids' => [$member->id]])->save();
    $invoiceJob = app(FieldServiceWorkCandidateService::class)->publish($tenant, $owner, $invoiceDraft);
    $search = app(FieldServiceSearchProvider::class);

    expect($invoiceJob->service_address_line_1)->toBe('88 Breaker Ave')
        ->and($invoiceJob->participants()->whereKey($member->id)->exists())->toBeTrue()
        ->and($search->search('panel labeling', ['tenant_id' => $tenant->id, 'user' => $owner]))->not->toBeEmpty()
        ->and($search->search('panel labeling', ['tenant_id' => $tenant->id, 'user' => $member]))->toBeEmpty()
        ->and($search->search('outdoor disconnect', ['tenant_id' => $tenant->id, 'user' => $member]))->not->toBeEmpty();
});

test('quickbooks drafts use address fallback and sync preserves an existing confirmed job site', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Address Electric', 'slug' => 'address-electric']);
    $service = app(QuickBooksFieldServiceImportService::class);
    $transaction = [
        'Id' => 'INV-ADDRESS',
        'DocNumber' => '2002',
        'CustomerRef' => ['value' => 'C-ADDRESS', 'name' => 'Address Customer'],
        'Balance' => 100,
        'PrivateNote' => 'Schedule field work.',
        'ShipAddr' => [],
        'BillAddr' => ['Line1' => '44 Billing Lane', 'City' => 'Greenville', 'CountrySubDivisionCode' => 'SC', 'PostalCode' => '29601'],
        'Line' => [['Description' => 'Repair service equipment.']],
    ];

    $service->importQuickBooksTransaction($tenant, $transaction, 'invoice');
    $draft = app(FieldServiceWorkCandidateService::class)->pending($tenant)->firstWhere('external_id', 'INV-ADDRESS');
    expect($draft?->service_address_line_1)->toBe('44 Billing Lane');

    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'external_source' => 'quickbooks',
        'external_id' => 'quickbooks:invoice:INV-ADDRESS',
        'title' => 'Confirmed address job',
        'status' => 'open',
        'service_address_line_1' => '99 Confirmed Job Site',
    ]);
    unset($transaction['BillAddr']);
    $service->importQuickBooksTransaction($tenant, $transaction, 'invoice');

    expect($job->fresh()->service_address_line_1)->toBe('99 Confirmed Job Site')
        ->and(data_get($job->fresh()->metadata, 'quickbooks.address_source'))->toBe('existing_job_address');
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

test('quickbooks generator invoices create equipment and link a later annual service', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Generator Electric', 'slug' => 'generator-electric']);
    $customer = MarketingProfile::query()->create(['tenant_id' => $tenant->id, 'first_name' => 'Generator', 'last_name' => 'Owner']);
    foreach (['GEN-INSTALL', 'GEN-SERVICE'] as $id) {
        FieldServiceFinancialDocument::query()->create([
            'tenant_id' => $tenant->id,
            'marketing_profile_id' => $customer->id,
            'source' => 'quickbooks',
            'document_type' => 'invoice',
            'external_id' => $id,
        ]);
    }

    $summary = app(QuickBooksGeneratorEquipmentService::class)->syncInvoices($tenant, [
        [
            'Id' => 'GEN-INSTALL', 'DocNumber' => 'G-100', 'TxnDate' => '2024-01-10',
            'CustomerRef' => ['name' => 'Generator Owner'],
            'Line' => [['Description' => 'Provide and install 22KW Generac generator with 200 amp automatic transfer switch.']],
        ],
        [
            'Id' => 'GEN-SERVICE', 'DocNumber' => 'G-200', 'TxnDate' => '2025-01-13',
            'CustomerRef' => ['name' => 'Generator Owner'],
            'Line' => [['Description' => 'Annual maintenance of 22KW Generac generator. Changed oil and air filter, load tested generator, and reset maintenance timer.']],
        ],
    ]);

    $equipment = CustomerEquipment::query()->sole();
    expect($summary['generator_equipment_created'])->toBe(1)
        ->and($summary['generator_services_linked'])->toBe(1)
        ->and($equipment->name)->toBe('Generac 22kW generator')
        ->and($equipment->installed_at?->toDateString())->toBe('2024-01-10')
        ->and($equipment->last_serviced_at?->toDateString())->toBe('2025-01-13')
        ->and($equipment->next_service_due_at?->toDateString())->toBe('2026-01-13')
        ->and(FieldServiceJob::query()->where('customer_equipment_id', $equipment->id)->where('operational_status', 'complete')->exists())->toBeTrue();
});

test('quickbooks customer queries include active and inactive records across every page', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Paged Electric', 'slug' => 'paged-electric']);
    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'quickbooks',
        'external_account_id' => hash_hmac('sha256', 'realm', (string) config('app.key')),
        'external_account_secret' => 'realm',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'qbo-access-token',
        'refresh_token' => 'qbo-refresh-token',
        'expires_at' => now()->addHour(),
    ]);
    $queries = [];
    Http::fake(function (Request $request) use (&$queries) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);
        $query = (string) ($parameters['query'] ?? '');
        $queries[] = $query;

        return str_contains($query, 'startposition 1')
            ? Http::response(['QueryResponse' => ['Customer' => [
                ['Id' => '1', 'DisplayName' => 'First', 'Active' => true],
                ['Id' => '2', 'DisplayName' => 'Second', 'Active' => false],
            ]]], 200)
            : Http::response(['QueryResponse' => ['Customer' => [
                ['Id' => '3', 'DisplayName' => 'Third', 'Active' => true],
            ]]], 200);
    });

    $client = new QuickBooksOnlineClient($connection, 'https://sandbox-quickbooks.api.intuit.com');
    $full = $client->allCustomers(2);
    $incremental = $client->allCustomersSince(Carbon::parse('2026-07-18T02:00:00Z'), 2);

    expect(collect($full)->pluck('Id')->all())->toBe(['1', '2', '3'])
        ->and(collect($incremental)->pluck('Id')->all())->toBe(['1', '2', '3'])
        ->and($queries)->toHaveCount(4)
        ->and(collect($queries)->every(fn (string $query): bool => str_contains($query, 'Active IN (true, false)')))->toBeTrue()
        ->and(collect($queries)->filter(fn (string $query): bool => str_contains($query, 'MetaData.LastUpdatedTime'))->count())->toBe(2)
        ->and(collect($queries)->filter(fn (string $query): bool => str_contains($query, 'startposition 3'))->count())->toBe(2);
});

test('customer sync preserves canonical contacts metadata and uses only qbo contact fallbacks', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Contact Electric', 'slug' => 'contact-electric']);
    enableQuickBooksBranchForApiTest($tenant);
    IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'quickbooks',
        'external_account_id' => hash_hmac('sha256', 'contact-realm', (string) config('app.key')),
        'external_account_secret' => 'contact-realm',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'qbo-access-token',
        'refresh_token' => 'qbo-refresh-token',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake(function (Request $request) {
        $url = urldecode($request->url());
        expect($url)->toContain('from Customer')
            ->toContain('Active IN (true, false)');

        return Http::response(['QueryResponse' => ['Customer' => [
            [
                'Id' => 'PARENT', 'DisplayName' => 'Parent Customer',
                'PrimaryEmailAddr' => ['Address' => 'parent@example.com'],
                'PrimaryPhone' => ['FreeFormNumber' => '555-1000'],
            ],
            [
                'Id' => 'CHILD', 'DisplayName' => 'Child Project',
                'ParentRef' => ['value' => 'PARENT'], 'Job' => true, 'BillWithParent' => true,
            ],
            [
                'Id' => 'MOBILE', 'DisplayName' => 'Mobile Customer',
                'Mobile' => ['FreeFormNumber' => '555-2000'],
            ],
            [
                'Id' => 'ALTERNATE', 'DisplayName' => 'Inactive Alternate Customer', 'Active' => false,
                'AlternatePhone' => ['FreeFormNumber' => '555-3000'],
            ],
            [
                'Id' => 'CANONICAL', 'DisplayName' => 'Canonical Customer',
                'PrimaryEmailAddr' => ['Address' => 'canonical@example.com'],
                'PrimaryPhone' => ['FreeFormNumber' => '555-4000'],
            ],
        ]]], 200);
    });

    $this->artisan('field-service:sync-quickbooks', [
        '--tenant' => $tenant->slug,
        '--entities' => 'customers',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('quickbooks_customers=5')
        ->expectsOutputToContain('quickbooks_customers_active=4')
        ->expectsOutputToContain('quickbooks_customers_inactive=1')
        ->expectsOutputToContain('quickbooks_customer_emails_inherited=1')
        ->expectsOutputToContain('quickbooks_customer_phones_from_mobile=1')
        ->expectsOutputToContain('quickbooks_customer_phones_from_alternate=1')
        ->expectsOutputToContain('quickbooks_customer_phones_inherited=1')
        ->expectsOutputToContain('quickbooks_customer_links_missing_before=5')
        ->expectsOutputToContain('quickbooks_customer_links_missing=0')
        ->expectsOutputToContain('quickbooks_customer_profiles_shared=0');

    $sourceId = fn (string $id): string => $tenant->id.':'.$id;
    $childLink = MarketingProfileLink::query()->forTenantId($tenant->id)
        ->where('source_type', 'quickbooks_customer')->where('source_id', $sourceId('CHILD'))->firstOrFail();
    $mobileLink = MarketingProfileLink::query()->forTenantId($tenant->id)
        ->where('source_type', 'quickbooks_customer')->where('source_id', $sourceId('MOBILE'))->firstOrFail();
    $alternateLink = MarketingProfileLink::query()->forTenantId($tenant->id)
        ->where('source_type', 'quickbooks_customer')->where('source_id', $sourceId('ALTERNATE'))->firstOrFail();
    $canonicalLink = MarketingProfileLink::query()->forTenantId($tenant->id)
        ->where('source_type', 'quickbooks_customer')->where('source_id', $sourceId('CANONICAL'))->firstOrFail();
    $parentLink = MarketingProfileLink::query()->forTenantId($tenant->id)
        ->where('source_type', 'quickbooks_customer')->where('source_id', $sourceId('PARENT'))->firstOrFail();

    expect(data_get($childLink->source_meta, 'email_source'))->toBe('parent_primary_email')
        ->and(data_get($childLink->source_meta, 'phone_source'))->toBe('parent_primary_phone')
        ->and($childLink->marketingProfile()->firstOrFail()->email)->toBe('parent@example.com')
        ->and($childLink->marketing_profile_id)->not->toBe($parentLink->marketing_profile_id)
        ->and(data_get($mobileLink->source_meta, 'phone_source'))->toBe('mobile')
        ->and($mobileLink->marketingProfile()->firstOrFail()->phone)->toBe('555-2000')
        ->and(data_get($alternateLink->source_meta, 'phone_source'))->toBe('alternate_phone')
        ->and(data_get($alternateLink->source_meta, 'active'))->toBeFalse()
        ->and(data_get($canonicalLink->source_meta, 'source_record_kind'))->toBe('customer');

    app(QuickBooksFieldServiceImportService::class)->importQuickBooksTransaction($tenant, [
        'Id' => 'INV-CANONICAL',
        'CustomerRef' => ['value' => 'CANONICAL', 'name' => 'Invoice Alias'],
        'BillEmail' => ['Address' => 'invoice-only@example.com'],
        'BillAddr' => ['Line1' => 'Transaction Address'],
        'TotalAmt' => 100,
        'Balance' => 100,
    ], 'invoice');

    $canonicalLink->refresh();
    $canonicalProfile = $canonicalLink->marketingProfile()->firstOrFail();
    expect($canonicalProfile->email)->toBe('canonical@example.com')
        ->and($canonicalProfile->phone)->toBe('555-4000')
        ->and(data_get($canonicalLink->source_meta, 'email'))->toBe('canonical@example.com')
        ->and(data_get($canonicalLink->source_meta, 'source_record_kind'))->toBe('customer');

    app(QuickBooksFieldServiceImportService::class)->importQuickBooksTransaction($tenant, [
        'Id' => 'INV-FALLBACK',
        'CustomerRef' => ['value' => 'FALLBACK', 'name' => 'Fallback Customer'],
        'BillEmail' => ['Address' => 'document-evidence@example.com'],
        'TotalAmt' => 50,
        'Balance' => 50,
    ], 'invoice');
    $fallbackLink = MarketingProfileLink::query()->forTenantId($tenant->id)
        ->where('source_type', 'quickbooks_customer')->where('source_id', $sourceId('FALLBACK'))->firstOrFail();
    expect($fallbackLink->marketingProfile()->firstOrFail()->email)->toBe('document-evidence@example.com')
        ->and(data_get($fallbackLink->source_meta, 'source_record_kind'))->toBe('transaction_fallback');
});

test('manual quickbooks sync shares the scheduler connection lock', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Locked Electric', 'slug' => 'locked-electric']);
    enableQuickBooksBranchForApiTest($tenant);
    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'quickbooks',
        'external_account_id' => hash_hmac('sha256', 'locked-realm', (string) config('app.key')),
        'external_account_secret' => 'locked-realm',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'qbo-access-token',
        'refresh_token' => 'qbo-refresh-token',
        'expires_at' => now()->addHour(),
    ]);
    Http::preventStrayRequests();
    $lock = Cache::lock('quickbooks-sync:'.$connection->id, 60);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('field-service:sync-quickbooks', [
            '--tenant' => $tenant->slug,
            '--entities' => 'customers',
        ])
            ->assertFailed()
            ->expectsOutputToContain('already running');
    } finally {
        $lock->release();
    }

    Http::assertNothingSent();
});

test('incremental customer sync includes inactive changes without treating untouched links as extras', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Incremental Electric', 'slug' => 'incremental-electric']);
    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'quickbooks',
        'external_account_id' => hash_hmac('sha256', 'incremental-realm', (string) config('app.key')),
        'external_account_secret' => 'incremental-realm',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'qbo-access-token',
        'refresh_token' => 'qbo-refresh-token',
        'expires_at' => now()->addHour(),
    ]);
    $import = app(QuickBooksFieldServiceImportService::class);
    $import->profileForRow($tenant, [
        'customer_id' => 'PARENT',
        'customer' => 'Existing Parent',
        'email' => 'parent@example.com',
        'email_source' => 'primary_email',
        'phone' => '555-5000',
        'phone_source' => 'primary_phone',
        'active' => true,
    ]);
    $import->profileForRow($tenant, [
        'customer_id' => 'UNTOUCHED',
        'customer' => 'Untouched Customer',
        'email' => 'untouched@example.com',
        'email_source' => 'primary_email',
        'phone' => '',
        'phone_source' => 'missing',
        'active' => true,
    ]);

    Http::fake(function (Request $request) {
        $url = urldecode($request->url());
        expect($url)->toContain('Active IN (true, false)')
            ->toContain('MetaData.LastUpdatedTime');

        return Http::response(['QueryResponse' => ['Customer' => [[
            'Id' => 'CHANGED-CHILD',
            'DisplayName' => 'Changed Child',
            'Active' => false,
            'ParentRef' => ['value' => 'PARENT'],
            'Job' => true,
            'BillWithParent' => true,
        ]]]], 200);
    });

    $summary = app(QuickBooksFieldServiceSyncService::class)->sync(
        $tenant,
        new QuickBooksOnlineClient($connection, 'https://sandbox-quickbooks.api.intuit.com'),
        ['customers'],
        false,
        Carbon::parse('2026-07-18T02:00:00Z')
    );

    expect($summary['quickbooks_customers'])->toBe(1)
        ->and($summary['quickbooks_customers_inactive'])->toBe(1)
        ->and($summary['quickbooks_customer_emails_inherited'])->toBe(1)
        ->and($summary['quickbooks_customer_phones_inherited'])->toBe(1)
        ->and($summary['quickbooks_customer_reconciliation_complete_snapshot'])->toBe(0)
        ->and($summary['quickbooks_customer_links_missing_before'])->toBe(1)
        ->and($summary['quickbooks_customer_links_missing'])->toBe(0)
        ->and($summary['quickbooks_customer_links_extra'])->toBe(0)
        ->and(MarketingProfileLink::query()->forTenantId($tenant->id)
            ->where('source_type', 'quickbooks_customer')->count())->toBe(3);

    $childLink = MarketingProfileLink::query()->forTenantId($tenant->id)
        ->where('source_type', 'quickbooks_customer')
        ->where('source_id', $tenant->id.':CHANGED-CHILD')
        ->firstOrFail();
    expect(data_get($childLink->source_meta, 'email_source'))->toBe('parent_primary_email')
        ->and(data_get($childLink->source_meta, 'phone_source'))->toBe('parent_primary_phone')
        ->and(data_get($childLink->source_meta, 'active'))->toBeFalse();
});

test('customer reconciliation reports and does not rewrite an existing shared profile collision', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Collision Electric', 'slug' => 'collision-electric']);
    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'quickbooks',
        'external_account_id' => hash_hmac('sha256', 'collision-realm', (string) config('app.key')),
        'external_account_secret' => 'collision-realm',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'qbo-access-token',
        'refresh_token' => 'qbo-refresh-token',
        'expires_at' => now()->addHour(),
    ]);
    $profile = app(QuickBooksFieldServiceImportService::class)->profileForRow($tenant, [
        'customer_id' => 'A', 'customer' => 'Original Identity',
        'email' => 'shared@example.com', 'phone' => '555-9000',
    ]);
    MarketingProfileLink::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'quickbooks_customer',
        'source_id' => $tenant->id.':B',
        'source_meta' => ['customer_id' => 'B', 'source_record_kind' => 'transaction_fallback'],
        'match_method' => 'quickbooks_transaction_fallback',
        'confidence' => 0.75,
    ]);
    Http::fake(fn () => Http::response(['QueryResponse' => ['Customer' => [
        [
            'Id' => 'A', 'DisplayName' => 'Changed A',
            'PrimaryEmailAddr' => ['Address' => 'shared@example.com'],
            'PrimaryPhone' => ['FreeFormNumber' => '555-1000'],
        ],
        [
            'Id' => 'B', 'DisplayName' => 'Changed B',
            'PrimaryEmailAddr' => ['Address' => 'shared@example.com'],
            'PrimaryPhone' => ['FreeFormNumber' => '555-2000'],
        ],
    ]]], 200));

    $summary = app(QuickBooksFieldServiceSyncService::class)->sync(
        $tenant,
        new QuickBooksOnlineClient($connection, 'https://sandbox-quickbooks.api.intuit.com'),
        ['customers']
    );

    expect($summary['quickbooks_customer_profiles_shared'])->toBe(1)
        ->and($summary['quickbooks_customers_on_shared_profiles'])->toBe(2)
        ->and($summary['quickbooks_customer_shared_profile_phone_conflicts'])->toBe(1)
        ->and($profile->fresh()->first_name)->toBe('Original')
        ->and($profile->fresh()->phone)->toBe('555-9000')
        ->and(data_get(MarketingProfileLink::query()->where('source_id', $tenant->id.':B')->firstOrFail()->source_meta, 'source_record_kind'))->toBe('customer');
});

test('collins normalization changes only untouched invoice generated titles', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);

    $invoiceJob = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'external_source' => 'quickbooks',
        'external_id' => 'quickbooks:invoice:INV-LEGACY',
        'title' => 'Invoice 2201 · Invoice Customer',
        'status' => 'open',
        'customer_name' => 'Invoice Customer',
    ]);
    FieldServiceFinancialDocument::query()->create([
        'tenant_id' => $tenant->id,
        'field_service_job_id' => $invoiceJob->id,
        'source' => 'quickbooks',
        'document_type' => 'invoice',
        'external_id' => 'INV-LEGACY',
        'document_number' => '2201',
        'transaction_date' => now(),
        'metadata' => ['quickbooks' => ['service_address' => ['line_1' => '12 Invoice Way']]],
    ]);

    $estimateJob = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'external_source' => 'quickbooks',
        'external_id' => 'quickbooks:estimate:EST-LEGACY',
        'title' => 'Estimate 3301 · Estimate Customer',
        'status' => 'quoted',
        'customer_name' => 'Estimate Customer',
    ]);
    FieldServiceFinancialDocument::query()->create([
        'tenant_id' => $tenant->id,
        'field_service_job_id' => $estimateJob->id,
        'source' => 'quickbooks',
        'document_type' => 'estimate',
        'external_id' => 'EST-LEGACY',
        'document_number' => '3301',
        'transaction_date' => now(),
    ]);

    $this->artisan('field-service:normalize-job-drafts', ['tenant' => $tenant->slug, '--apply' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Applied 1 job normalization(s)');

    expect($invoiceJob->fresh()->title)->toBe('Invoice Customer job')
        ->and($invoiceJob->fresh()->service_address_line_1)->toBe('12 Invoice Way')
        ->and($estimateJob->fresh()->title)->toBe('Estimate 3301 · Estimate Customer');
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
