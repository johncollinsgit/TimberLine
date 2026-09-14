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
        $ambiguous = preg_match('/\b(amazon|amzn|walmart|wal-mart|target|paypal|venmo|transfer|payment|square|sq)\b/i', $merchant);
        $category = in_array($providerCategory, config('trajectory.categories'), true) ? $providerCategory : 'uncategorized';
        $defaults = $space->settings['discretionary_categories'] ?? config('trajectory.discretionary_defaults');

        return [
            'category' => $category,
            'flow' => $amount > 0 ? (in_array($category, ['income', 'uncategorized'], true) ? 'income' : 'refund') : 'expense',
            'reviewed' => ! $ambiguous && $category !== 'uncategorized',
            'face_punched' => false,
            'bullshit_spending' => ! $ambiguous && in_array($category, $defaults, true),
            'explanation' => $ambiguous ? 'Mixed-purpose merchant or possible transfer. Review the purpose.' : ($category === 'uncategorized' ? 'Insufficient evidence. Review required.' : 'Provider category; editable in review.'),
        ];
    }

    public static function merchant(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($value)));
    }
}
