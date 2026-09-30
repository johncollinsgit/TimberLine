<?php

use App\Models\IntegrationConnection;
use App\Models\ModernForestryFundraiserInvoicePackage;
use App\Models\ModernForestryFundraiserOrder;
use App\Models\Tenant;
use App\Services\Shopify\ModernForestryFundraiserQuickBooksService;
use Illuminate\Support\Facades\Http;

test('a reviewed fundraiser package creates and sends one replay-safe QuickBooks invoice', function (): void {
    config()->set('services.quickbooks.api_base', 'https://quickbooks.test');
    config()->set('services.quickbooks.fundraiser_writes_enabled', true);
    config()->set('services.quickbooks.fundraiser_send_enabled', true);
    config()->set('services.quickbooks.fundraiser_customer_id', 'customer-7');
    config()->set('services.quickbooks.fundraiser_item_id', 'item-candles');
    config()->set('services.quickbooks.fundraiser_shipping_item_id', 'item-shipping');
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    IntegrationConnection::query()->create(['tenant_id' => $tenant->id, 'provider' => 'quickbooks', 'external_account_id' => 'fingerprint', 'external_account_secret' => 'realm-1', 'status' => 'connected', 'access_token' => 'token']);
    $order = ModernForestryFundraiserOrder::query()->create(['tenant_id' => $tenant->id, 'source' => 'zapier', 'external_order_id' => '32733', 'order_reference' => '32733', 'recipient_name' => 'Customer', 'shipping_address' => [], 'currency' => 'usd', 'subtotal_cents' => 1000, 'discount_cents' => 0, 'shipping_cents' => 935, 'tax_cents' => 0, 'total_cents' => 1935, 'status' => 'packaged', 'fingerprint' => str_repeat('a', 64), 'line_items' => [], 'received_at' => now()]);
    $package = ModernForestryFundraiserInvoicePackage::query()->create(['tenant_id' => $tenant->id, 'package_reference' => 'BSF-SEP-2026', 'status' => 'review_required', 'delivery_status' => 'not_sent', 'tracking_status' => 'not_available', 'payer_name' => 'Dan Arnoldussen', 'payer_email' => 'info@theforestrystudio.com', 'notification_email' => 'info@theforestrystudio.com', 'currency' => 'usd', 'payment_terms_days' => 14, 'invoice_date' => today(), 'due_date' => today()->addDays(14), 'subtotal_cents' => 1000, 'discount_cents' => 0, 'shipping_cents' => 935, 'tax_cents' => 0, 'total_cents' => 1935, 'order_ids' => [$order->id], 'invoice_lines' => [], 'prepared_at' => now()]);
    Http::fake([
        'quickbooks.test/v3/company/realm-1/invoice?*' => Http::response(['Invoice' => ['Id' => 'invoice-9', 'DocNumber' => 'BSF-SEP-2026']]),
        'quickbooks.test/v3/company/realm-1/invoice/invoice-9/send?*' => Http::response(['Invoice' => ['Id' => 'invoice-9', 'EmailStatus' => 'EmailSent']]),
    ]);
    $sent = app(ModernForestryFundraiserQuickBooksService::class)->createAndMaybeSend($package, true);
    expect($sent->status)->toBe('sent')->and($sent->quickbooks_invoice_id)->toBe('invoice-9')->and($sent->quickbooks_sent_at)->not->toBeNull();
    app(ModernForestryFundraiserQuickBooksService::class)->createAndMaybeSend($sent, true);
    Http::assertSentCount(2);
});
