<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\IntegrationConnection;
use App\Models\ModernForestryFundraiserOrder;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Shopify\ModernForestryFundraiserInvoicePreparationService;
use App\Services\Shopify\ModernForestryFundraiserInvoiceSettingsService;
use App\Services\Shopify\ModernForestryFundraiserShopifyOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
});

test('fundraising tab detects BSF Shopify orders and queues only new periods', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    configureEmbeddedRetailStore($tenant->id);
    foreach ([['#32959', '2026-08-28 12:00:00', 11], ['#33070', '2026-09-10 12:00:00', 33]] as [$reference, $date, $total]) {
        Order::query()->create([
            'tenant_id' => $tenant->id,
            'source' => 'shopify_retail',
            'shopify_store_key' => 'retail',
            'shopify_order_id' => $reference === '#32959' ? 32959 : 33070,
            'shopify_name' => $reference,
            'order_number' => $reference,
            'ordered_at' => CarbonImmutable::parse($date),
            'currency_code' => 'USD',
            'total_price' => $total,
            'tax_total' => 0,
            'shipping_total' => 0,
            'attribution_meta' => ['order_tags' => ['BSF']],
        ]);
    }

    $detector = app(ModernForestryFundraiserShopifyOrderService::class);
    expect($detector->discover($tenant))->toBe(1)
        ->and($detector->discover($tenant))->toBe(0)
        ->and(ModernForestryFundraiserOrder::query()->count())->toBe(1);

    $this->get(route('shopify.app.fundraising', retailEmbeddedSignedQuery()))
        ->assertOk()->assertSeeText('BSF invoice desk')->assertSeeText('#33070')
        ->assertSeeText('Needs receipt')->assertSeeText('Invoice packages');
});

test('a Shopify order needs a verified label cost and a live Shopify match before monthly packaging', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    configureEmbeddedRetailStore($tenant->id);
    app(ModernForestryFundraiserInvoiceSettingsService::class)->saveForTenant($tenant->id, [
        'fundraiser_name' => 'Bed Sheet Fundraiser',
        'invoice_payer_name' => 'Dan Arnoldussen',
        'invoice_payer_email' => 'info@bedsheetfundraising.com',
        'invoice_cadence' => 'monthly_first_day',
    ]);
    Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 33070,
        'shopify_name' => '#33070',
        'ordered_at' => CarbonImmutable::parse('2026-09-10 12:00:00'),
        'currency_code' => 'USD',
        'total_price' => 33,
        'tax_total' => 0,
        'shipping_total' => 0,
        'attribution_meta' => ['order_tags' => ['BSF']],
    ]);
    $detector = app(ModernForestryFundraiserShopifyOrderService::class);
    $detector->discover($tenant);
    $queued = ModernForestryFundraiserOrder::query()->sole();
    $preparation = app(ModernForestryFundraiserInvoicePreparationService::class);
    try {
        $preparation->approve($tenant, $queued->id, 'staff');
        $this->fail('Approval should require a purchased-label cost.');
    } catch (\Illuminate\Validation\ValidationException $exception) {
        expect($exception->errors())->toHaveKey('shipping');
    }

    Http::fake(['*/orders/33070.json' => Http::response(['order' => [
        'id' => 33070, 'tags' => 'BSF', 'currency' => 'USD',
        'current_total_price' => '33.00', 'current_total_tax' => '0.00',
    ]])]);
    $verified = $detector->verifyShipping($tenant, $queued->id, 583, 'Shopify label receipt 33070', 'staff');
    expect($verified->total_cents)->toBe(3883)
        ->and(data_get($verified->source_payload, 'shipping_verified'))->toBeTrue();
    $preparation->approve($tenant, $queued->id, 'staff');
    $manual = $preparation->prepare($tenant, [$queued->id], 'staff');
    expect($manual->package_reference)->toBe('BSF-SEP-2026');
    $packages = $preparation->prepareApprovedMonth($tenant, CarbonImmutable::parse('2026-09-01'), 'staff');
    expect($packages)->toHaveCount(1)
        ->and($packages[0]->id)->toBe($manual->id)
        ->and($packages[0]->package_reference)->toBe('BSF-SEP-2026')
        ->and($packages[0]->total_cents)->toBe(3883);
});

test('Shopify purchased-label report automatically links an exact BSF order cost', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    configureEmbeddedRetailStore($tenant->id);
    Http::fake([
        '*/graphql.json' => Http::response(['data' => ['shopifyqlQuery' => [
            'parseErrors' => [],
            'tableData' => ['rows' => [[
                'order_id' => '33070', 'order_name' => '#33070',
                'shipping_label_costs' => '8.14', 'shipping_labels' => '1',
            ]]],
        ]]]),
        '*/orders/33070.json' => Http::response(['order' => [
            'id' => 33070, 'tags' => 'BSF', 'currency' => 'USD',
            'current_total_price' => '33.00', 'current_total_tax' => '0.00',
        ]]),
    ]);
    Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 33070,
        'shopify_name' => '#33070',
        'ordered_at' => CarbonImmutable::parse('2026-09-10 12:00:00'),
        'currency_code' => 'USD',
        'total_price' => 33,
        'tax_total' => 0,
        'shipping_total' => 0,
        'attribution_meta' => ['order_tags' => ['BSF']],
    ]);

    app(ModernForestryFundraiserShopifyOrderService::class)->discover($tenant);
    $queued = ModernForestryFundraiserOrder::query()->sole();
    expect($queued->shipping_cents)->toBe(814)
        ->and($queued->total_cents)->toBe(4114)
        ->and(data_get($queued->source_payload, 'shipping_evidence_source'))->toBe('shopify_shipping_labels');
    app(ModernForestryFundraiserInvoicePreparationService::class)->approve($tenant, $queued->id, 'staff');
    Http::assertSentCount(3);
});

test('the existing August invoice exposes only a verified Intuit customer payment link', function (): void {
    config()->set('services.quickbooks.api_base', 'https://quickbooks.test');
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    configureEmbeddedRetailStore($tenant->id);
    IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'quickbooks',
        'external_account_id' => 'fingerprint',
        'external_account_secret' => 'realm-1',
        'status' => 'connected',
        'access_token' => 'token',
    ]);
    Http::fake([
        'quickbooks.test/v3/company/realm-1/query?*' => Http::response(['QueryResponse' => ['Invoice' => [['Id' => 'invoice-aug']]]]),
        'quickbooks.test/v3/company/realm-1/invoice/invoice-aug?*' => Http::response(['Invoice' => [
            'Id' => 'invoice-aug', 'DocNumber' => 'BSF-AUG-2026',
            'TotalAmt' => 96.58, 'Balance' => 96.58,
            'InvoiceLink' => 'https://links.notification.intuit.com/august',
        ]]),
    ]);

    $this->withHeader('Authorization', 'Bearer '.retailShopifySessionToken())
        ->getJson(route('shopify.app.api.fundraising.invoices.existing-august', [], false))
        ->assertOk()->assertJsonPath('invoice.total_cents', 9658)
        ->assertJsonPath('invoice.payment_link', 'https://links.notification.intuit.com/august');
});
