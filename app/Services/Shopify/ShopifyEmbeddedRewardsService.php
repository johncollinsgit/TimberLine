<?php

namespace App\Services\Shopify;

use App\Models\CandleCashReward;
use App\Models\CandleCashTask;
use App\Models\MarketingSetting;
use App\Services\Marketing\CandleCashService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShopifyEmbeddedRewardsService
{
    public function __construct(
        protected CandleCashService $candleCashService
    ) {
    }

    /**
     * @return array{
     *   meta:array<string,mixed>,
     *   earn:array<string,mixed>,
     *   redeem:array<string,mixed>
     * }
     */
    public function payload(): array
    {
        $earn = $this->sectionPayload(fn (): array => $this->earnSection());
        $redeem = $this->sectionPayload(fn (): array => $this->redeemSection());

        return [
            'meta' => [
                'program' => [
                    'legacy_points_per_candle_cash' => $this->candleCashService->legacyPointsPerCandleCash(),
                    'points_per_dollar' => $this->candleCashService->legacyPointsPerCandleCash(),
                    'measurement_label' => '1 Candle Cash = 1 Candle Cash',
                    'redeem_increment_dollars' => $this->candleCashService->fixedRedemptionAmount(),
                    'redeem_increment_formatted' => $this->candleCashService->fixedRedemptionFormatted(),
                    'max_redeemable_per_order_dollars' => $this->candleCashService->maxRedeemablePerOrderAmount(),
                    'max_redeemable_per_order_formatted' => $this->candleCashService->formatRewardCurrency($this->candleCashService->maxRedeemablePerOrderAmount()),
                    'max_open_codes' => $this->candleCashService->maxOpenStorefrontCodes(),
                ],
                'limitations' => [
                    [
                        'scope' => 'redeem',
                        'message' => 'Minimum order requirements are not stored on current Candle Cash reward rows, so that field remains unavailable in this embedded page.',
                    ],
                ],
            ],
            'earn' => $earn,
            'redeem' => $redeem,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function updateEarnRule(CandleCashTask $task, array $data): array
    {
        $rewardAmount = array_key_exists('candle_cash_value', $data) && $data['candle_cash_value'] !== null
            ? round(max(0, (float) $data['candle_cash_value']), 2)
            : round($this->candleCashService->amountFromPoints(max(0, (int) ($data['points_value'] ?? 0))), 2);

        $task->forceFill([
            'title' => trim((string) ($data['title'] ?? '')),
            'description' => $this->nullableString($data['description'] ?? null),
            'reward_amount' => $rewardAmount,
            'enabled' => (bool) ($data['enabled'] ?? false),
            'display_order' => max(0, (int) ($data['sort_order'] ?? 0)),
        ])->save();

        $this->syncTaskConfigAmount((string) $task->handle, $rewardAmount);

        return $this->earnActionRow($task->fresh());
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function updateRedeemRule(CandleCashReward $reward, array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $description = $this->nullableString($data['description'] ?? null);
        $candleCashCost = array_key_exists('candle_cash_cost', $data) && $data['candle_cash_cost'] !== null
            ? round(max(0, (float) $data['candle_cash_cost']), 2)
            : round($this->candleCashService->amountFromPoints(max(0, (int) ($data['points_cost'] ?? 0))), 2);
        $pointsCost = $this->candleCashService->pointsFromAmount($candleCashCost);
        $rewardValue = $this->nullableString($data['reward_value'] ?? null);
        $enabled = (bool) ($data['enabled'] ?? false);

        if ($this->candleCashService->isStorefrontReward($reward)) {
            $parsedValue = $this->parseRewardValueAmount($rewardValue ?? '');
            if ($parsedValue === null) {
                throw ValidationException::withMessages([
                    'reward_value' => 'Storefront Candle Cash needs a numeric discount value, such as 10USD or 10.',
                ]);
            }

            $expectedCost = $this->candleCashService->pointsFromAmount($parsedValue);
            if ($pointsCost !== $expectedCost) {
                throw ValidationException::withMessages([
                    'candle_cash_cost' => 'Storefront Candle Cash cost is derived from the discount value and current Candle Cash value.',
                ]);
            }

            $this->syncStorefrontProgramConfig(
                rewardType: (string) $reward->reward_type,
                rewardValue: $rewardValue ?? '',
                discountAmount: $parsedValue
            );
        }

        $reward->forceFill([
            'name' => $title,
            'description' => $description,
            'points_cost' => $pointsCost,
            'reward_value' => $rewardValue,
            'is_active' => $enabled,
        ])->save();

        return $this->redeemRewardRow($reward->fresh());
    }

    /**
     * @return array<string,mixed>
     */
    protected function earnSection(): array
    {
        $items = CandleCashTask::query()
            ->whereNull('archived_at')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn (CandleCashTask $task): array => $this->earnActionRow($task))
            ->values()
            ->all();

        return [
            'status' => 'ok',
            'items' => $items,
            'summary' => [
                'total' => count($items),
                'enabled' => collect($items)->where('enabled', true)->count(),
                'disabled' => collect($items)->where('enabled', false)->count(),
            ],
            'message' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function redeemSection(): array
    {
        $items = CandleCashReward::query()
            ->orderBy('points_cost')
            ->orderBy('id')
            ->get()
            ->map(fn (CandleCashReward $reward): array => $this->redeemRewardRow($reward))
            ->values()
            ->all();

        return [
            'status' => 'ok',
            'items' => $items,
            'summary' => [
                'total' => count($items),
                'enabled' => collect($items)->where('enabled', true)->count(),
                'disabled' => collect($items)->where('enabled', false)->count(),
            ],
            'message' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function earnActionRow(CandleCashTask $task): array
    {
        $rewardAmount = (float) $task->reward_amount;
        $pointsValue = $this->candleCashService->pointsFromAmount($rewardAmount);
        $actionType = trim((string) ($task->verification_mode ?: $task->task_type));

        return [
            'id' => (int) $task->id,
            'code' => (string) $task->handle,
            'title' => (string) $task->title,
            'description' => $task->description ? (string) $task->description : null,
            'candle_cash_value' => $rewardAmount,
            'candle_cash_value_formatted' => $this->candleCashService->formatCandleCash($rewardAmount),
            'points_value' => $pointsValue,
            'legacy_points_value' => $pointsValue,
            'reward_amount' => $rewardAmount,
            'reward_amount_formatted' => $this->candleCashService->formatRewardCurrency($rewardAmount),
            'action_type' => $actionType,
            'action_type_label' => $this->labelize($actionType),
            'task_type' => (string) $task->task_type,
            'task_type_label' => $this->labelize((string) $task->task_type),
            'verification_mode' => (string) ($task->verification_mode ?? ''),
            'verification_mode_label' => $this->labelize((string) ($task->verification_mode ?? '')),
            'enabled' => (bool) $task->enabled,
            'status_label' => $task->enabled ? 'Enabled' : 'Disabled',
            'sort_order' => (int) $task->display_order,
            'customer_visible' => (bool) data_get($task->metadata, 'customer_visible', true),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function redeemRewardRow(CandleCashReward $reward): array
    {
        return [
            'id' => (int) $reward->id,
            'code' => 'reward-' . $reward->id,
            'title' => (string) $reward->name,
            'description' => $reward->description ? (string) $reward->description : null,
            'reward_type' => (string) $reward->reward_type,
            'reward_type_label' => $this->labelize((string) $reward->reward_type),
            'candle_cash_cost' => $this->candleCashService->amountFromPoints((int) $reward->points_cost),
            'candle_cash_cost_formatted' => $this->candleCashService->formatCandleCash($this->candleCashService->amountFromPoints((int) $reward->points_cost)),
            'points_cost' => (int) $reward->points_cost,
            'legacy_points_cost' => (int) $reward->points_cost,
            'reward_value' => $reward->reward_value !== null ? (string) $reward->reward_value : null,
            'value_display' => $this->rewardValueDisplay($reward),
            'minimum_order_amount' => null,
            'minimum_order_supported' => false,
            'enabled' => (bool) $reward->is_active,
            'status_label' => $reward->is_active ? 'Enabled' : 'Disabled',
            'is_storefront_reward' => $this->candleCashService->isStorefrontReward($reward),
        ];
    }

    /**
     * @param  callable():array<string,mixed>  $resolver
     * @return array<string,mixed>
     */
    protected function sectionPayload(callable $resolver): array
    {
        try {
            return $resolver();
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'status' => 'error',
                'items' => [],
                'summary' => [
                    'total' => 0,
                    'enabled' => 0,
                    'disabled' => 0,
                ],
                'message' => 'This section could not be loaded from Backstage right now.',
            ];
        }
    }

    protected function syncTaskConfigAmount(string $handle, float $rewardAmount): void
    {
        $programKeyMap = [
            'email-signup' => 'email_signup_reward_amount',
            'sms-signup' => 'sms_signup_reward_amount',
            'google-review' => 'google_review_reward_amount',
            'birthday-signup' => 'birthday_signup_reward_amount',
            'candle-club-join' => 'candle_club_join_reward_amount',
            'candle-club-vote' => 'candle_club_vote_reward_amount',
            'second-order' => 'second_order_reward_amount',
        ];

        if (array_key_exists($handle, $programKeyMap)) {
            $config = $this->rawSettingValue('candle_cash_program_config');
            $config[$programKeyMap[$handle]] = $rewardAmount;
            $this->saveSetting('candle_cash_program_config', $config, 'Core Candle Cash program settings for label text, reward math, and frontend messaging.');
        }

        $referralKeyMap = [
            'refer-a-friend' => 'referrer_reward_amount',
            'referred-friend-bonus' => 'referred_reward_amount',
        ];

        if (array_key_exists($handle, $referralKeyMap)) {
            $config = $this->rawSettingValue('candle_cash_referral_config');
            $config[$referralKeyMap[$handle]] = $rewardAmount;
            $this->saveSetting('candle_cash_referral_config', $config, 'Referral program settings for Candle Cash growth tasks.');
        }
    }

    protected function syncStorefrontProgramConfig(string $rewardType, string $rewardValue, float $discountAmount): void
    {
        $raw = $this->rawSettingValue('candle_cash_program_config');
        $effective = array_merge($raw, $this->candleCashService->programConfig());

        $effective['points_per_dollar'] = $this->candleCashService->legacyPointsPerCandleCash();
        $effective['redeem_increment_dollars'] = $discountAmount;
        $effective['max_redeemable_per_order_dollars'] = $this->candleCashService->maxRedeemablePerOrderAmount();
        $effective['max_open_codes'] = $this->candleCashService->maxOpenStorefrontCodes();
        $effective['storefront_reward_type'] = strtolower(trim($rewardType)) ?: 'coupon';
        $effective['storefront_reward_value'] = trim($rewardValue) ?: $rewardValue;

        $this->saveSetting('candle_cash_program_config', $effective, 'Core Candle Cash program settings for label text, reward math, and frontend messaging.');
    }

    /**
     * @return array<string,mixed>
     */
    protected function rawSettingValue(string $key): array
    {
        return (array) optional(MarketingSetting::query()->where('key', $key)->first())->value;
    }

    /**
     * @param  array<string,mixed>  $value
     */
    protected function saveSetting(string $key, array $value, string $description): void
    {
        MarketingSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'description' => $description,
            ]
        );
    }

    protected function rewardValueDisplay(CandleCashReward $reward): string
    {
        $rewardType = strtolower(trim((string) $reward->reward_type));
        $rawValue = trim((string) ($reward->reward_value ?? ''));

        if ($rewardType === 'coupon') {
            $amount = $this->candleCashService->rewardValueAmount($reward);

            return $amount > 0
                ? $this->candleCashService->formatRewardCurrency($amount) . ' off'
                : ($rawValue !== '' ? $rawValue : 'Coupon reward');
        }

        if ($rewardType === 'percent_discount') {
            return $rawValue !== '' ? $rawValue . ' off' : 'Percent discount';
        }

        if ($rawValue !== '') {
            return Str::of($rawValue)->replace('_', ' ')->headline()->value();
        }

        return $this->labelize($rewardType);
    }

    protected function labelize(string $value): string
    {
        return Str::of($value)
            ->replace(['-', '_'], ' ')
            ->squish()
            ->headline()
            ->value();
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function parseRewardValueAmount(string $value): ?float
    {
        if (preg_match('/-?\d+(?:\.\d+)?/', $value, $matches) !== 1) {
            return null;
        }

        return round((float) $matches[0], 2);
    }
}
