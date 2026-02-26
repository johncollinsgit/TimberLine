<?php

use App\Services\EventMatchingService;

it('normalizes titles by stripping dates years and punctuation', function () {
    $service = new EventMatchingService();

    $normalized = $service->normalizeTitle('Downtown Market - Sat 03/14/2026 (Spring)!');

    expect($normalized)->toBe('downtown market spring');
});

it('fuzzy matches upcoming events to historical events when similarity threshold is met', function () {
    $service = new EventMatchingService();

    $candidates = collect([
        (object) ['event_title' => 'Downtown Holiday Market 2024'],
        (object) ['event_title' => 'Riverfront Spring Market 2025'],
        (object) ['event_title' => 'Warehouse Sale'],
    ]);

    $match = $service->bestMatch('Riverfront Spring Market - Sat 04/12/2026', $candidates);

    expect($match)->not->toBeNull();
    expect($match['candidate']->event_title)->toBe('Riverfront Spring Market 2025');
    expect($match['score'])->toBeGreaterThan(0.68);
});

it('returns null when no candidate is similar enough', function () {
    $service = new EventMatchingService();

    $match = $service->bestMatch('Completely New Event Name', [
        'Old Town Summer Market',
        'Holiday Open House',
    ], null, 0.8);

    expect($match)->toBeNull();
});

