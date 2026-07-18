<?php

use App\Services\Automation\CalendarEventPresentationService;

test('commerce calendar presentation is customer configurable and privacy conservative', function (): void {
    $service = app(CalendarEventPresentationService::class);
    $settings = $service->fromPayload([
        'event_title_template' => '{{customer_name}} · order {{order_number}}',
        'event_description_fields' => ['items', 'total', 'status', 'source_link'],
        'event_location_source' => 'pickup_location',
        'event_color_id' => '10',
        'event_availability' => 'free',
        'event_visibility' => 'private',
        'event_reminders' => 'none',
        'cancelled_order_behavior' => 'mark_cancelled',
    ], 'shopify');

    $event = $service->render([
        'source' => 'Shopify',
        'order_number' => '1042',
        'customer_name' => 'Jamie Lee',
        'customer_email' => 'private@example.com',
        'items' => '2 × Cedar Candle',
        'total' => '$84.00',
        'status' => 'Cancelled',
        'source_url' => 'https://shop.example/orders/1042',
        'pickup_location' => 'Downtown shop',
    ], $settings, 'shopify');

    expect($event['summary'])->toBe('Cancelled — Jamie Lee · order 1042')
        ->and($event['description'])->toContain('Items: 2 × Cedar Candle')
        ->and($event['description'])->toContain('Total: $84.00')
        ->and($event['description'])->not->toContain('private@example.com')
        ->and($event['location'])->toBe('Downtown shop')
        ->and($event['colorId'])->toBe('10')
        ->and($event['transparency'])->toBe('transparent')
        ->and($event['visibility'])->toBe('private')
        ->and($event['reminders'])->toBe(['useDefault' => false]);
});

test('all ecommerce templates carry independent fulfillment and calendar presentation defaults', function (string $templateKey, string $provider): void {
    $definition = app(\App\Services\Automation\WorkflowTemplateCatalog::class)->defaultDefinition($templateKey);

    expect(data_get($definition, 'trigger.provider'))->toBe($provider)
        ->and(data_get($definition, 'trigger.schedule_source'))->toBe('fulfillment')
        ->and(data_get($definition, 'action.presentation.title_template'))->toContain('{{order_number}}')
        ->and(data_get($definition, 'action.presentation.description_fields'))->toBe(['items', 'total', 'status', 'source_link'])
        ->and(data_get($definition, 'action.presentation.location_source'))->toBe('shipping_address');
})->with([
    ['shopify_order_to_google_calendar', 'shopify'],
    ['square_order_to_google_calendar', 'square'],
    ['squarespace_order_to_google_calendar', 'squarespace'],
    ['wix_order_to_google_calendar', 'wix'],
]);
