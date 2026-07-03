<?php

use App\Services\Bud\BudConversationService;

test('bud answers product questions in a conversational way', function (): void {
    $service = new BudConversationService();

    $response = $service->respond('What is Everbranch?');

    expect($response['confidence'])->toBe('high')
        ->and($response['uncertain'])->toBeFalse()
        ->and($response['reply'])->toContain('workspace')
        ->and($response['reply'])->toContain('customers');
});

test('bud answers who made everbranch directly', function (): void {
    $service = new BudConversationService();

    $response = $service->respond('who made everbranch?');

    expect($response['confidence'])->toBe('high')
        ->and($response['uncertain'])->toBeFalse()
        ->and($response['reply'])->toContain('John Collins')
        ->and($response['reply'])->toContain('Bud is the assistant layer');
});

test('bud answers onboarding and pricing questions with a real next step', function (): void {
    $service = new BudConversationService();

    $response = $service->respond('how can i get on board and what does it cost?');

    expect($response['confidence'])->toBe('high')
        ->and($response['uncertain'])->toBeFalse()
        ->and($response['reply'])->toContain('Request access')
        ->and($response['reply'])->toContain('pricing');
});

test('bud is honest when it does not know enough', function (): void {
    $service = new BudConversationService();

    $response = $service->respond('What is the exact shipping cost for my current subscription?');

    expect($response['uncertain'])->toBeTrue()
        ->and($response['reply'])->toContain('I’m not sure yet about the exact live billing detail')
        ->and($response['reply'])->toContain('can’t see a live billing record');
});

test('bud uses page context to make the answer specific', function (): void {
    $service = new BudConversationService();

    $response = $service->respond('How could this help?', [
        'scenario' => 'service',
        'pane' => 'customers',
        'type' => 'Service business',
        'customer' => 'Northline Maintenance',
    ]);

    expect($response['confidence'])->toBe('high')
        ->and($response['reply'])->toContain('Northline Maintenance')
        ->and($response['reply'])->toContain('customer notes');
});
