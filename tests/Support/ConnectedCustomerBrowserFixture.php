<?php

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'marketing-desk-preview.sqlite' || ! app()->environment('testing')) {
    exit(90);
}
$tenant = App\Models\Tenant::where('slug', 'marketing-preview')->firstOrFail();
$owner = App\Models\User::where('email', 'marketing-preview@example.test')->firstOrFail();
$owner->forceFill(['is_active' => true, 'email_verified_at' => now()])->save();
$customer = App\Models\MarketingProfile::firstOrCreate(['tenant_id' => $tenant->id, 'normalized_email' => 'event-buyer@example.test'], ['first_name' => 'Fictional event buyer', 'email' => 'event-buyer@example.test', 'country' => 'US', 'postal_code' => '29201', 'accepts_email_marketing' => true, 'accepts_sms_marketing' => false]);
$stock = app(App\Services\Inventory\StockLedger::class);
$stock->rows('event_links', $tenant->id)->updateOrInsert(['tenant_id' => $tenant->id, 'event_id' => 123, 'provider' => 'manual', 'external_id' => 'fictional-preview-show'], ['metadata' => json_encode(['name' => 'Fictional Columbia show', 'timezone' => 'America/New_York', 'postal_code' => '29201', 'start_on' => '2026-11-13']), 'reviewed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
$order = App\Models\SquareOrder::firstOrCreate(['tenant_id' => $tenant->id, 'square_order_id' => 'fictional-preview-order'], ['state' => 'COMPLETED', 'closed_at' => now()->subDay(), 'location_id' => 'fictional-preview-location', 'total_money_amount' => 1080, 'total_money_currency' => 'USD', 'raw_payload' => ['line_items' => [['catalog_object_id' => 'fictional-preview-candle', 'name' => 'Fictional forest candle', 'quantity' => '2.0']]]]);
App\Models\SquarePayment::firstOrCreate(['tenant_id' => $tenant->id, 'square_payment_id' => 'fictional-preview-payment'], ['square_order_id' => $order->square_order_id, 'status' => 'COMPLETED', 'location_id' => 'fictional-preview-location', 'amount_money' => 1080, 'currency' => 'USD', 'raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
App\Models\MarketingProfileLink::firstOrCreate(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'source_type' => 'square_order', 'source_id' => $order->square_order_id], ['source_meta' => ['event_attribution' => ['event_id' => 123, 'evidence_reference' => 'fictional preview owner-confirmed purchase link', 'reviewed_at' => now()->toIso8601String(), 'reviewed_by' => $owner->id]]]);
App\Models\SquareCustomer::firstOrCreate(['tenant_id' => $tenant->id, 'square_customer_id' => 'fictional-preview-customer'], ['given_name' => 'Fictional event buyer', 'email' => $customer->email]);
App\Models\MarketingProfileLink::firstOrCreate(['marketing_profile_id' => $customer->id, 'source_type' => 'square_customer', 'source_id' => 'fictional-preview-customer']);
$area = App\Models\TeamArea::firstOrCreate(['tenant_id' => $tenant->id, 'slug' => 'fictional-connected-shows'], ['id' => (string) Illuminate\Support\Str::uuid(), 'name' => 'Fictional events', 'profile' => 'events', 'settings' => ['source_authority' => 'teams']]);
App\Models\TeamOperationRecord::firstOrCreate(['tenant_id' => $tenant->id, 'source_key' => hash('sha256', 'fictional-connected-series')], ['id' => (string) Illuminate\Support\Str::uuid(), 'area_id' => $area->id, 'kind' => 'series', 'title' => 'Fictional recurring show', 'status' => 'accepted', 'version' => 1, 'data' => []]);
echo json_encode(['tenant' => $tenant->slug, 'customer' => $customer->id, 'event' => 123]);
