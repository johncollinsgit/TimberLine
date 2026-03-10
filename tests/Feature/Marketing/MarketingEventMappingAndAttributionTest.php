<?php

use App\Models\EventInstance;
use App\Models\MarketingEventSourceMapping;
use App\Models\MarketingOrderEventAttribution;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\User;
use App\Services\Marketing\MarketingEventAttributionService;

test('square raw value mapping resolves to event attribution', function () {
    $event = EventInstance::query()->create([
        'title' => 'Florida Strawberry Festival',
        'starts_at' => now()->addDays(5)->toDateString(),
    ]);

    $order = SquareOrder::query()->create([
        'square_order_id' => 'SQ-ATTR-1',
        'source_name' => 'Florida Strawberry Festival',
        'raw_tax_names' => ['county 7%'],
    ]);

    MarketingEventSourceMapping::query()->create([
        'source_system' => 'square_source_name',
        'raw_value' => 'Florida Strawberry Festival',
        'normalized_value' => 'florida strawberry festival',
        'event_instance_id' => $event->id,
        'confidence' => 0.95,
        'is_active' => true,
    ]);

    app(MarketingEventAttributionService::class)->refreshForSquareOrder($order);

    expect(MarketingOrderEventAttribution::query()
        ->where('source_type', 'square_order')
        ->where('source_id', 'SQ-ATTR-1')
        ->where('event_instance_id', $event->id)
        ->exists())->toBeTrue();
});

test('customer detail shows mapped event attribution when available', function () {
    $event = EventInstance::query()->create([
        'title' => 'Flowertown Festival',
        'starts_at' => now()->addDays(3)->toDateString(),
    ]);

    $order = SquareOrder::query()->create([
        'square_order_id' => 'SQ-ATTR-DETAIL',
        'source_name' => 'Flowertown',
        'raw_tax_names' => ['Horry County'],
    ]);

    MarketingEventSourceMapping::query()->create([
        'source_system' => 'square_source_name',
        'raw_value' => 'Flowertown',
        'normalized_value' => 'flowertown',
        'event_instance_id' => $event->id,
        'confidence' => 0.9,
        'is_active' => true,
    ]);

    app(MarketingEventAttributionService::class)->refreshForSquareOrder($order);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Event',
        'last_name' => 'Buyer',
        'email' => 'eventbuyer@example.com',
        'normalized_email' => 'eventbuyer@example.com',
    ]);
    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'square_order',
        'source_id' => 'SQ-ATTR-DETAIL',
        'match_method' => 'exact_email',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('marketing.customers.show', $profile))
        ->assertOk()
        ->assertSeeText('Flowertown Festival');
});

test('unmapped square source values are visible in providers integrations page', function () {
    SquareOrder::query()->create([
        'square_order_id' => 'SQ-UNMAPPED-1',
        'source_name' => 'Flowertown',
        'raw_tax_names' => ['Horry County'],
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations'))
        ->assertOk()
        ->assertSeeText('Horry County');
});
