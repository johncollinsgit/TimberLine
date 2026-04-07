<?php

namespace App\Services\Marketing;

class TenantRewardsPolicySummaryService
{
    /**
     * @param  array<string,mixed>  $policy
     */
    public function summarize(array $policy): string
    {
        $identity = (array) ($policy['program_identity'] ?? []);
        $value = (array) ($policy['value_model'] ?? []);
        $earning = (array) ($policy['earning_rules'] ?? []);
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);
        $redemption = (array) ($policy['redemption_rules'] ?? []);

        $programName = $this->stringOrDefault($identity['program_name'] ?? null, 'Rewards');
        $secondOrderReward = round((float) ($earning['second_order_reward_amount'] ?? 0), 2);
        $maxRedeem = round((float) ($value['max_redeemable_per_order_dollars'] ?? 0), 2);
        $minimumPurchase = round((float) ($value['minimum_purchase_dollars'] ?? 0), 2);
        $currencyMode = (string) ($value['currency_mode'] ?? 'fixed_cash');
        $pointsPerDollar = max(1, (int) ($value['points_per_dollar'] ?? CandleCashService::DEFAULT_LEGACY_POINTS_PER_CANDLE_CASH));

        $valueModeSentence = $currencyMode === 'points_to_cash'
            ? sprintf('Value is shown as points (%d points = $1.00).', $pointsPerDollar)
            : 'Rewards are shown as dollar savings.';

        $orderThresholdText = $minimumPurchase > 0
            ? '$'.number_format($minimumPurchase, 2)
            : 'any order amount';

        $stackingMode = (string) ($redemption['stacking_mode'] ?? 'no_stacking');
        $stackingSentence = match ($stackingMode) {
            'shipping_only' => 'Reward codes can only stack with shipping discounts.',
            'selected_promo_types' => 'Reward codes can stack with selected promotion types only.',
            default => 'Reward codes do not stack with other promotions.',
        };

        $expirationMode = (string) ($expiration['expiration_mode'] ?? 'days_from_issue');
        $expirationDays = max(1, (int) ($expiration['expiration_days'] ?? 90));
        $expirationSentence = match ($expirationMode) {
            'end_of_season' => 'Rewards expire at the end of the selected season.',
            'none' => 'Rewards do not expire.',
            default => sprintf('Rewards expire %d days after being earned.', $expirationDays),
        };

        $emailEnabled = (bool) ($expiration['email_enabled'] ?? true);
        $smsEnabled = (bool) ($expiration['sms_enabled'] ?? false);
        $emailOffsets = collect((array) ($expiration['email_reminder_offsets_days'] ?? $expiration['reminder_offsets_days'] ?? []))
            ->map(fn ($item): int => (int) $item)
            ->filter(fn (int $item): bool => $item >= 0)
            ->sortDesc()
            ->values();
        $smsOffsets = collect((array) ($expiration['sms_reminder_offsets_days'] ?? []))
            ->map(fn ($item): int => (int) $item)
            ->filter(fn (int $item): bool => $item >= 0)
            ->sortDesc()
            ->values();

        $emailSentence = $emailEnabled
            ? ($emailOffsets->isNotEmpty()
                ? 'Reminder emails go out '.$this->humanizedOffsets($emailOffsets->all()).' before expiration.'
                : 'Reminder emails are enabled.')
            : 'Reminder emails are turned off.';

        $smsMaxPerReward = max(0, (int) ($expiration['sms_max_per_reward'] ?? 0));
        $smsSentence = $smsEnabled
            ? ($smsOffsets->isNotEmpty()
                ? sprintf('Text reminders go out %s before expiration (up to %d per reward).', $this->humanizedOffsets($smsOffsets->all()), $smsMaxPerReward)
                : sprintf('Text reminders are enabled (up to %d per reward).', $smsMaxPerReward))
            : 'Text reminders are turned off.';

        return implode(' ', array_filter([
            sprintf(
                'Customers earn $%s in %s after their second order. They can use up to $%s on orders over %s.',
                number_format($secondOrderReward, 2),
                $programName,
                number_format($maxRedeem, 2),
                $orderThresholdText
            ),
            $expirationSentence,
            $emailSentence,
            $smsSentence,
            $stackingSentence,
            $this->channelStrategySummary($policy),
            $this->exclusionsSummary($policy),
            $valueModeSentence,
        ]));
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    public function exclusionsSummary(array $policy): string
    {
        $exclusions = (array) data_get($policy, 'redemption_rules.exclusions', []);
        $items = [];

        if ((bool) ($exclusions['wholesale'] ?? false)) {
            $items[] = 'wholesale orders';
        }
        if ((bool) ($exclusions['sale_items'] ?? false)) {
            $items[] = 'sale items';
        }
        if ((bool) ($exclusions['subscriptions'] ?? false)) {
            $items[] = 'subscription items';
        }
        if ((bool) ($exclusions['bundles'] ?? false)) {
            $items[] = 'bundles and gift sets';
        }
        if ((bool) ($exclusions['limited_releases'] ?? false)) {
            $items[] = 'limited releases';
        }
        if (((array) ($exclusions['collections'] ?? [])) !== []) {
            $items[] = count((array) ($exclusions['collections'] ?? [])).' excluded collection'.(count((array) ($exclusions['collections'] ?? [])) === 1 ? '' : 's');
        }
        if (((array) ($exclusions['tags'] ?? [])) !== []) {
            $items[] = count((array) ($exclusions['tags'] ?? [])).' excluded product tag'.(count((array) ($exclusions['tags'] ?? [])) === 1 ? '' : 's');
        }
        if (((array) ($exclusions['products'] ?? [])) !== []) {
            $items[] = count((array) ($exclusions['products'] ?? [])).' excluded product'.(count((array) ($exclusions['products'] ?? [])) === 1 ? '' : 's');
        }

        if ($items === []) {
            return 'No product exclusions are selected yet.';
        }

        return 'Rewards do not apply to '.$this->humanizedList($items).'.';
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    public function channelStrategySummary(array $policy): string
    {
        $mode = (string) data_get($policy, 'earning_rules.rewardable_channels', 'online_only');

        return match ($mode) {
            'show_issued_online_redeemed' => 'Rewards can be earned at shows and used on future online orders.',
            'exclude_shows' => 'Rewards stay on online purchases and skip show activity.',
            default => 'Rewards are set up for online earning and redemption.',
        };
    }

    /**
     * @param  array<int,int>  $offsets
     */
    protected function humanizedOffsets(array $offsets): string
    {
        $values = collect($offsets)
            ->map(fn (int $offset): string => $offset.' day'.($offset === 1 ? '' : 's'))
            ->values()
            ->all();

        if ($values === []) {
            return 'before expiration';
        }

        if (count($values) === 1) {
            return $values[0];
        }

        if (count($values) === 2) {
            return $values[0].' and '.$values[1];
        }

        $last = array_pop($values);

        return implode(', ', $values).', and '.$last;
    }

    /**
     * @param  array<int,string>  $values
     */
    protected function humanizedList(array $values): string
    {
        if ($values === []) {
            return 'nothing';
        }

        if (count($values) === 1) {
            return $values[0];
        }

        if (count($values) === 2) {
            return $values[0].' and '.$values[1];
        }

        $last = array_pop($values);

        return implode(', ', $values).', and '.$last;
    }

    protected function stringOrDefault(mixed $value, string $fallback): string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : $fallback;
    }
}
