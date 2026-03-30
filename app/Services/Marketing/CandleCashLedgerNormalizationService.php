<?php

namespace App\Services\Marketing;

use App\Models\CandleCashTransaction;

class CandleCashLedgerNormalizationService
{
    /**
     * @return array<string,array{label:string,definition:string}>
     */
    public function sourceDefinitions(): array
    {
        return [
            'order_purchase_earn' => [
                'label' => 'Order / Reward purchase earn',
                'definition' => 'Earned from order-linked and purchase-behavior rewards (for example second-order style task rewards).',
            ],
            'signup_welcome_earn' => [
                'label' => 'Signup / Welcome earn',
                'definition' => 'Earned from signup, consent, or welcome actions tied to first-party customer capture.',
            ],
            'referral_earn' => [
                'label' => 'Referral earn',
                'definition' => 'Earned from referral conversion rewards for referrers or referred customers.',
            ],
            'birthday_earn' => [
                'label' => 'Birthday earn',
                'definition' => 'Earned from birthday reward credits applied as reward credit.',
            ],
            'bonus_promo_earn' => [
                'label' => 'Bonus / Promo earn',
                'definition' => 'Earned from promotional tasks and engagement bonuses that are not signup, referral, birthday, or order-purchase earns.',
            ],
            'manual_adjustment_earn' => [
                'label' => 'Manual adjustment earn',
                'definition' => 'Manually granted positive reward balance adjustments by Backstage operators.',
            ],
            'other_earn' => [
                'label' => 'Other earn',
                'definition' => 'Earned from recognized program activity that does not cleanly map to a named source bucket.',
            ],
        ];
    }

    public function isGrandfatheredOpening(CandleCashTransaction $transaction): bool
    {
        $type = strtolower(trim((string) $transaction->type));
        $source = strtolower(trim((string) $transaction->source));
        $sourceId = strtolower(trim((string) $transaction->source_id));
        $description = strtolower(trim((string) $transaction->description));

        if (in_array($type, ['import_opening_balance', 'candle_cash_balance_rebase'], true)) {
            return true;
        }

        if (in_array($source, ['growave', 'legacy_rebase'], true)) {
            return true;
        }

        if ($source === 'growave_activity' && str_contains($description, '(import)')) {
            return true;
        }

        if (
            in_array($source, ['admin', 'shopify_embedded_admin', 'admin_adjustment'], true)
            && (
                str_contains($description, 'opening')
                || str_contains($description, 'grandfather')
                || str_contains($description, 'seed')
                || str_contains($description, 'starting balance')
                || str_contains($sourceId, 'seed')
                || str_contains($sourceId, 'opening')
            )
        ) {
            return true;
        }

        return false;
    }

    public function classifyEarnSource(CandleCashTransaction $transaction, ?string $taskHandle = null): string
    {
        $definitions = $this->sourceDefinitions();
        $type = strtolower(trim((string) $transaction->type));
        $source = strtolower(trim((string) $transaction->source));
        $description = strtolower(trim((string) $transaction->description));
        $taskHandle = strtolower(trim((string) ($taskHandle ?? '')));

        if (in_array($source, ['admin_adjustment', 'admin', 'shopify_embedded_admin'], true)) {
            return 'manual_adjustment_earn';
        }

        if (in_array($type, ['adjust', 'adjustment'], true)) {
            return 'manual_adjustment_earn';
        }

        if ($source === 'birthday_reward' || str_contains($taskHandle, 'birthday')) {
            return 'birthday_earn';
        }

        if (
            in_array($source, ['consent', 'subscription_event'], true)
            || str_contains($taskHandle, 'signup')
            || str_contains($taskHandle, 'welcome')
        ) {
            return 'signup_welcome_earn';
        }

        if (
            str_contains($source, 'referral')
            || in_array($taskHandle, ['refer-a-friend', 'referred-friend-bonus'], true)
            || str_contains($description, 'referrer')
            || str_contains($description, 'referred')
        ) {
            return 'referral_earn';
        }

        if (
            in_array($source, ['order', 'shopify_order', 'shopify_order_ingest'], true)
            || in_array($taskHandle, ['second-order'], true)
            || str_contains($taskHandle, 'order')
            || str_contains($description, 'order')
        ) {
            return 'order_purchase_earn';
        }

        if (
            $source === 'candle_cash_task'
            || in_array($source, ['growave_activity', 'campaign', 'gift'], true)
            || $taskHandle !== ''
        ) {
            return 'bonus_promo_earn';
        }

        return array_key_exists('other_earn', $definitions) ? 'other_earn' : 'bonus_promo_earn';
    }
}
