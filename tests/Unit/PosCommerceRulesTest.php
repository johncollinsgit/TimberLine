<?php

use App\Services\Pos\PosCommerceRules;
use App\Services\Pos\PosException;

it('validates independently configurable discount and candle add-on rules', function (): void {
    $rules = [
        ['id' => 'teacher', 'name' => 'Teacher', 'kind' => 'percent', 'value' => 1000, 'scopes' => ['item', 'sale'], 'channels' => ['in_person'], 'active' => true],
        ['id' => 'candle_addon', 'name' => '8oz add-on', 'kind' => 'addon', 'value' => 1500, 'scopes' => ['item'], 'channels' => ['in_person'], 'active' => true, 'eligible_item_ids' => [str_repeat('a', 32)], 'requires_other_item' => true],
    ];
    $service = new PosCommerceRules;
    expect($service->validate($rules)['rules'])->toEqual($rules)
        ->and($service->validate($rules)['version'])->toBe($service->validate($rules)['version']);
    $changed = $rules;
    $changed[0]['value'] = 1500;
    expect($service->validate($changed)['version'])->not->toBe($service->validate($rules)['version']);
    expect(fn () => $service->validate([$rules[0], $rules[0]]))->toThrow(PosException::class);
    $changed = $rules;
    $changed[1]['eligible_item_ids'] = ['not-an-item'];
    expect(fn () => $service->validate($changed))->toThrow(PosException::class);
});

it('canonicalizes object keys without changing the order of lists', function (): void {
    $service = new PosCommerceRules;
    $first = ['id' => 'teacher', 'name' => 'Teacher', 'kind' => 'percent', 'value' => 1000,
        'scopes' => ['item', 'sale'], 'channels' => ['in_person', 'online'], 'active' => true];
    $reordered = ['channels' => ['in_person', 'online'], 'active' => true, 'scopes' => ['item', 'sale'],
        'value' => 1000, 'kind' => 'percent', 'name' => 'Teacher', 'id' => 'teacher'];
    $second = ['id' => 'vendor', 'name' => 'Vendor', 'kind' => 'fixed', 'value' => 500,
        'scopes' => ['sale'], 'channels' => ['in_person'], 'active' => true];
    $expected = $service->validate([$first, $second]);
    expect($service->validate([$reordered, $second]))->toBe($expected)
        ->and($service->validate([$second, $first])['version'])->not->toBe($expected['version']);
    $reordered['scopes'] = ['sale', 'item'];
    expect($service->validate([$reordered, $second])['version'])->not->toBe($expected['version']);
});

it('creates, edits and deletes rules while rejecting stale edits', function (): void {
    $service = new PosCommerceRules;
    $empty = $service->validate([]);
    $rule = ['id' => 'five_off', 'name' => '$5 off', 'kind' => 'fixed', 'value' => 500, 'scopes' => ['sale'], 'channels' => ['in_person'], 'active' => true];
    $created = $service->save([], $rule, $empty['version']);
    $rule['value'] = 600;
    $updated = $service->save($created['rules'], $rule, $created['version']);
    expect($updated['rules'][0]['value'])->toBe(600);
    expect(fn () => $service->delete($updated['rules'], 'five_off', $created['version']))->toThrow(PosException::class);
    expect($service->delete($updated['rules'], 'five_off', $updated['version'])['rules'])->toBe([]);
});
