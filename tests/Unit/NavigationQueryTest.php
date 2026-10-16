<?php

use App\Services\Navigation\NavigationQuery;

test('voice navigation understands ordinary page requests without interpreting write commands', function (string $spoken, string $expected): void {
    expect((new NavigationQuery)->normalize($spoken))->toBe($expected);
})->with([
    ['Open my schedule', 'schedule'],
    ['Can you please take me to the invoices page?', 'invoices'],
    ['Where do I manage my team?', 'team'],
    ['Show me our email settings please', 'email settings'],
    ['Hey Everbranch, open the customer screen.', 'customer'],
    ['Delete the invoice', 'delete the invoice'],
    ['Send this email', 'send this email'],
]);

test('voice navigation recognizes only explicit back and cancel controls', function (): void {
    $query = new NavigationQuery;
    expect($query->control('Go back!'))->toBe('back')
        ->and($query->control('Cancel'))->toBe('cancel')
        ->and($query->control('cancel the invoice'))->toBeNull();
});

test('voice navigation asks when destinations are ambiguous or refer to records', function (): void {
    $query = new NavigationQuery;
    $page = ['type' => 'navigation', 'title' => 'Mail', 'url' => '/mail', 'score' => 260];
    expect($query->resolution([$page])['status'])->toBe('matched')
        ->and($query->resolution([$page, [...$page, 'url' => '/other-mail']])['status'])->toBe('choose')
        ->and($query->resolution([['type' => 'customer', 'score' => 400]])['status'])->toBe('choose')
        ->and($query->resolution([])['status'])->toBe('no_match');
});
