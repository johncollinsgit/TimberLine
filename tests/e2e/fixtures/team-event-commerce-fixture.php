<?php

ob_start();
require __DIR__.'/../../../scripts/everbranch-team/operations-calendar-browser-fixture.php';
$fixture = json_decode(ob_get_clean(), true, 64, JSON_THROW_ON_ERROR);
$tenant = App\Models\Tenant::findOrFail($fixture['tenant_id']);
$owner = $tenant->users()->firstOrFail();
$event = App\Models\TeamOperationRecord::findOrFail($fixture['event_id']);
$stock = app(App\Services\Inventory\StockLedger::class);
$stock->createPool($tenant->id, 'browser-event-pool', $owner->id, 'Fictional show stock', 'show', 'event:730');
$stock->rows('event_links', $tenant->id)->insert(['tenant_id' => $tenant->id, 'event_id' => 730, 'provider' => 'manual', 'external_id' => 'browser-event',
    'metadata' => json_encode(['name' => $event->title, 'timezone' => 'America/New_York', 'start_on' => '2026-11-06', 'end_on' => '2026-11-08']), 'reviewed_by' => $owner->id]);
$data = $event->data;
$data['_teams_enrichment']['commerce_event'] = ['id' => 730];
$data['_teams_enrichment']['square_orders'] = ['ids' => ['fictional-commerce-order'], 'reviewed_by' => $owner->id, 'reviewed_at' => now()->toIso8601String()];
$event->update(['data' => $data]);
$profile = App\Models\MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional', 'last_name' => 'Returning customer', 'email' => 'fictional-buyer@example.invalid']);
App\Models\SquareOrder::create(['tenant_id' => $tenant->id, 'square_order_id' => 'fictional-commerce-order', 'location_id' => 'fictional-location', 'state' => 'COMPLETED',
    'closed_at' => '2026-11-06T14:00:00Z', 'synced_at' => now(), 'total_money_amount' => 2160, 'total_money_currency' => 'USD',
    'raw_payload' => ['total_tax_money' => ['amount' => 160, 'currency' => 'USD'], 'line_items' => [['catalog_object_id' => 'fictional-garden', 'name' => 'Fictional Garden candle', 'variation_name' => '8oz cotton wick',
        'quantity' => '2', 'total_money' => ['amount' => 2160, 'currency' => 'USD'], 'total_tax_money' => ['amount' => 160, 'currency' => 'USD']]]]]);
App\Models\SquarePayment::create(['tenant_id' => $tenant->id, 'square_payment_id' => 'fictional-commerce-payment', 'square_order_id' => 'fictional-commerce-order', 'location_id' => 'fictional-location',
    'status' => 'COMPLETED', 'source_type' => 'CARD', 'currency' => 'USD', 'amount_money' => 2160, 'raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
App\Models\MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'square_order', 'source_id' => 'fictional-commerce-order']);
$fixture['customer_id'] = $profile->id;
foreach ([['phone' => '+15551234567', 'normalized_phone' => '+15551234567'], []] as $index => $contact) {
    $profile = App\Models\MarketingProfile::create(['tenant_id' => $tenant->id, ...$contact]);
    App\Models\MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'pos_event_capture', 'source_id' => 'fictional-contact-'.$index,
        'source_meta' => ['event_id' => 730, 'captured_at' => '2026-11-06T14:00:00Z', 'capture_evidence_version' => 1, 'record_origin' => 'selected']]);
}
$fixture['contact_phone'] = '+15551234567';
echo json_encode($fixture, JSON_THROW_ON_ERROR);
