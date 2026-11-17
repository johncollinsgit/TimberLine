<?php

use App\Services\Trajectory\ClassificationService;
use App\Services\Trajectory\ReviewSuggestionService;

function mixedMerchantEntries(string $merchant): \Illuminate\Support\Collection
{
    return collect([1, 2, 3])->map(fn (int $id): array => [
        'id' => $id, 'version' => 1, 'date' => '2028-07-20', 'merchant' => $merchant,
        'amount_cents' => -10000 * $id, 'category' => $id === 1 ? 'delivery_fees' : 'uncategorized',
        'flow' => 'expense', 'reviewed' => $id === 1, 'editable' => true,
        'face_punched' => false, 'bullshit_spending' => false,
    ]);
}

test('mixed retailer and platform bills do not inherit a category from one reviewed purchase', function (string $merchant): void {
    expect(ClassificationService::isAmbiguousMerchant($merchant))->toBeTrue()
        ->and(app(ReviewSuggestionService::class)->forEntries(mixedMerchantEntries($merchant)))->toBeEmpty();
})->with(['Shopify', "Sam's Club", 'Sams Club', 'Sam’s Club', 'SamsClub']);

test('the mixed-purpose guard preserves explicit review suggestions for other merchants', function (): void {
    $suggestions = app(ReviewSuggestionService::class)->forEntries(mixedMerchantEntries('Fictional parcel carrier'));
    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['category'])->toBe('delivery_fees')
        ->and(array_column($suggestions[0]['transactions'], 'id'))->toBe([2, 3]);
});
