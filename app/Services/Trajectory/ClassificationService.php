<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;

class ClassificationService
{
    public function suggest(Space $space, string $merchant, int $amount, ?string $providerCategory = null): array
    {
        $normalized = self::merchant($merchant);
        foreach (Record::query()->where('space_id', $space->id)->where('kind', 'rule')->where('active', true)->orderByDesc('id')->get() as $rule) {
            if (self::merchant($rule->data['merchant']) === $normalized) {
                return [...$rule->data['classification'], 'reviewed' => true, 'explanation' => 'Your exact merchant rule: '.$rule->name];
            }
        }
        $ambiguous = self::isAmbiguousMerchant($merchant);
        $category = in_array($providerCategory, app(WorkspaceService::class)->categories($space), true) ? $providerCategory : 'uncategorized';
        $defaults = $space->settings['discretionary_categories'] ?? config('trajectory.discretionary_defaults');
        $flow = match (true) {
            $amount > 0 && self::isTransferMerchant($merchant) => 'transfer',
            $amount > 0 && $category === 'uncategorized' => 'unclassified_deposit',
            $amount > 0 && ($category === 'income' || isset($space->settings['income_categories'][$category])) => 'income',
            $amount > 0 => 'refund',
            default => 'expense',
        };

        return [
            'category' => $category,
            'flow' => $flow,
            'reviewed' => ! $ambiguous && $category !== 'uncategorized',
            'face_punched' => false,
            'bullshit_spending' => ! $ambiguous && in_array($category, $defaults, true),
            'explanation' => $ambiguous ? 'Mixed-purpose merchant or possible transfer. Review the purpose.' : ($category === 'uncategorized' ? 'Insufficient evidence. Review required.' : 'Provider category; editable in review.'),
        ];
    }

    public static function isAmbiguousMerchant(string $merchant): bool
    {
        return (bool) preg_match('/\b(amazon|amzn|walmart|wal-mart|target|ebay|etsy|costco|paypal|venmo|zelle|cash app|transfer|payment|square|sq)\b/i', $merchant);
    }

    public static function isTransferMerchant(string $merchant): bool
    {
        return (bool) preg_match('/\b(?:funds\s+transfer|webxfr|moneylink|cashout)\b/i', $merchant);
    }

    public static function merchant(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($value)));
    }
}
