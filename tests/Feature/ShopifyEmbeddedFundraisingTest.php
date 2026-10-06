<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\IntegrationConnection;
use App\Models\ModernForestryFundraiserOrder;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shopify\ModernForestryFundraiserInvoicePreparationService;
use App\Services\Shopify\ModernForestryFundraiserInvoiceSettingsService;
use App\Services\Shopify\ModernForestryFundraiserShopifyOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
});

test('fundraising tab detects BSF Shopify orders and queues only new periods', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    configureEmbeddedRetailStore($tenant->id);
    foreach ([['#32959', '2026-08-28 12:00:00', 11], ['#33070', '2026-09-10 12:00:00', 33]] as [$reference, $date, $total]) {
        $order = Order::query()->create([
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
        if ($reference === '#33070') {
            OrderLine::query()->create(['order_id' => $order->id, 'raw_title' => 'Soy Candle | Peach Orchard - 8oz', 'ordered_qty' => 2, 'quantity' => 2]);
        }
    }

    $detector = app(ModernForestryFundraiserShopifyOrderService::class);
    expect($detector->discover($tenant))->toBe(1)
        ->and($detector->discover($tenant))->toBe(0)
        ->and(ModernForestryFundraiserOrder::query()->count())->toBe(1);

    $this->get(route('shopify.app.fundraising', retailEmbeddedSignedQuery()))
        ->assertOk()->assertSeeText('BSF invoice desk')->assertSeeText('#33070')
        ->assertSeeText('Needs receipt')->assertSeeText('Invoice packages')
        ->assertSeeText('2 × Soy Candle | Peach Orchard - 8oz');
});

test('search new orders accepts a Shopify Admin bearer token without an iframe CSRF cookie', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    configureEmbeddedRetailStore($tenant->id);
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
    Http::fake(['*' => Http::response(['data' => ['shopifyqlQuery' => ['parseErrors' => [], 'tableData' => ['rows' => []]]]])]);

    $this->withMiddleware(ValidateCsrfToken::class)
        ->withHeader('Authorization', 'Bearer '.retailShopifySessionToken())
        ->postJson(route('shopify.app.api.fundraising.detect', [], false))
        ->assertOk()->assertJsonPath('queued', 1);
    expect(ModernForestryFundraiserOrder::query()->count())->toBe(1);

    $this->withMiddleware(ValidateCsrfToken::class)
        ->withHeader('Authorization', '')
        ->postJson(route('shopify.app.api.fundraising.detect', [], false))
        ->assertUnauthorized()->assertJsonPath('status', 'missing_api_auth');
});

test('fundraising controls review an order, create a Modern Forestry draft, and send only after approval', function (): void {
    config()->set('services.quickbooks.api_base', 'https://quickbooks.test');
    config()->set('services.quickbooks.fundraiser_writes_enabled', true);
    config()->set('services.quickbooks.fundraiser_send_enabled', true);
    config()->set('services.quickbooks.fundraiser_customer_id', 'customer-7');
    config()->set('services.quickbooks.fundraiser_item_id', 'item-candles');
    config()->set('services.quickbooks.fundraiser_shipping_item_id', 'item-shipping');
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    configureEmbeddedRetailStore($tenant->id);
    $operator = User::factory()->create(['email' => 'johncollinsemail@gmail.com']);
    IntegrationConnection::query()->create(['tenant_id' => $tenant->id, 'provider' => 'quickbooks', 'external_account_id' => 'fingerprint', 'external_account_secret' => 'realm-1', 'status' => 'connected', 'access_token' => 'token', 'connected_by_user_id' => $operator->id]);
    app(ModernForestryFundraiserInvoiceSettingsService::class)->saveForTenant($tenant->id, [
        'fundraiser_name' => 'Bed Sheet Fundraiser',
        'invoice_payer_name' => 'Modern Forestry',
        'invoice_payer_email' => 'info@theforestrystudio.com',
        'invoice_cadence' => 'monthly_first_day',
    ]);
    $order = Order::query()->create(['tenant_id' => $tenant->id, 'source' => 'shopify_retail', 'shopify_store_key' => 'retail', 'shopify_order_id' => 33070, 'shopify_name' => '#33070', 'ordered_at' => CarbonImmutable::parse('2026-09-10 12:00:00'), 'currency_code' => 'USD', 'total_price' => 33, 'tax_total' => 0, 'shipping_total' => 0, 'attribution_meta' => ['order_tags' => ['BSF']]]);
    OrderLine::query()->create(['order_id' => $order->id, 'raw_title' => 'Peach Orchard Candle', 'ordered_qty' => 2, 'quantity' => 2]);
    $invoiceReads = 0;
    Http::fake(function (HttpRequest $request) use (&$invoiceReads) {
        $url = $request->url();
        if (str_contains($url, '/graphql.json')) {
            return Http::response(['data' => ['shopifyqlQuery' => ['parseErrors' => [], 'tableData' => ['rows' => []]]]]);
        }
        if (str_contains($url, '/orders/33070.json')) {
            return Http::response(['order' => ['id' => 33070, 'tags' => 'BSF', 'currency' => 'USD', 'current_total_price' => '33.00', 'current_total_tax' => '0.00']]);
        }
        if (str_contains($url, 'CompanyInfo')) {
            return Http::response(['QueryResponse' => ['CompanyInfo' => [['CompanyName' => 'Modern Forestry', 'Email' => ['Address' => 'info@theforestrystudio.com']]]]]);
        }
        if (str_contains($url, '/query?')) {
            return Http::response(['QueryResponse' => ['Invoice' => []]]);
        }
        if (str_contains($url, '/invoice/invoice-9/send?')) {
            return Http::response(['Invoice' => ['Id' => 'invoice-9', 'EmailStatus' => 'EmailSent']]);
        }
        if ($request->method() === 'GET' && str_contains($url, '/invoice/invoice-9?')) {
            $invoiceReads++;

            return Http::response(['Invoice' => ['Id' => 'invoice-9', 'DocNumber' => 'BSF-SEP-2026', 'SyncToken' => (string) ($invoiceReads - 1), 'TotalAmt' => 38.83, 'CustomerRef' => ['value' => 'customer-7'], 'BillEmail' => ['Address' => 'info@theforestrystudio.com'], 'AllowOnlineACHPayment' => $invoiceReads > 1, 'AllowOnlineCreditCardPayment' => $invoiceReads > 1, 'InvoiceLink' => $invoiceReads > 1 ? 'https://links.notification.intuit.com/example' : null]]);
        }
        if ($request->method() === 'POST' && str_contains($url, '/invoice?')) {
            return Http::response(['Invoice' => ['Id' => 'invoice-9', 'DocNumber' => 'BSF-SEP-2026']]);
        }

        return Http::response(['Fault' => ['Error' => [['Message' => 'Unexpected request']]]], 500);
    });
    $url = fn (string $name, array $params = []) => route('shopify.app.api.'.$name, $params, false);
    $this->withMiddleware(ValidateCsrfToken::class)->withHeader('Authorization', 'Bearer '.retailShopifySessionToken());

    $this->postJson($url('fundraising.detect'))->assertOk()->assertJsonPath('queued', 1);
    $queued = ModernForestryFundraiserOrder::query()->sole();
    $this->get(route('shopify.app.fundraising', retailEmbeddedSignedQuery()))->assertOk()->assertSeeText('2 × Peach Orchard Candle');
    $this->postJson($url('fundraising.orders.shipping', ['order' => $queued->id]), ['shipping_cents' => 583, 'evidence' => 'Shopify label receipt 33070'])->assertOk();
    $this->postJson($url('settings.fundraiser-invoicing.orders.approve', ['order' => $queued->id]))->assertOk();
    $this->postJson($url('settings.fundraiser-invoicing.packages.prepare'), ['order_ids' => [$queued->id]])->assertOk();
    $package = \App\Models\ModernForestryFundraiserInvoicePackage::query()->sole();
    expect($package->payer_email)->toBe('info@theforestrystudio.com');
    $this->get(route('shopify.app.fundraising', retailEmbeddedSignedQuery()))
        ->assertOk()->assertSeeText('Products in these orders')->assertSeeText('2 × Peach Orchard Candle');

    $this->postJson($url('fundraising.invoices.send', ['package' => $package->id]), ['confirmation' => 'SEND BSF-SEP-2026'])->assertUnprocessable();
    $this->postJson($url('fundraising.invoices.create', ['package' => $package->id]))->assertOk()->assertJsonPath('status', 'quickbooks_created');
    $this->postJson($url('fundraising.invoices.send', ['package' => $package->id]), ['confirmation' => 'SEND BSF-SEP-2026'])->assertOk()->assertJsonPath('status', 'sent');
    $this->getJson($url('fundraising.invoices.payment-link', ['package' => $package->id]))->assertOk()->assertJsonPath('payment_link', 'https://links.notification.intuit.com/example');
    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/invoice/invoice-9/send?') && str_contains($request->url(), 'sendTo=info%40theforestrystudio.com'));
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
    $operator = User::factory()->create(['email' => 'johncollinsemail@gmail.com']);
    IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'quickbooks',
        'external_account_id' => 'fingerprint',
        'external_account_secret' => 'realm-1',
        'status' => 'connected',
        'access_token' => 'token',
        'connected_by_user_id' => $operator->id,
    ]);
    Http::fake([
        'quickbooks.test/v3/company/realm-1/query?*CompanyInfo*' => Http::response(['QueryResponse' => ['CompanyInfo' => [['CompanyName' => 'Modern Forestry', 'Email' => ['Address' => 'info@theforestrystudio.com']]]]]),
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
