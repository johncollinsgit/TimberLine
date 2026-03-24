<?php

namespace App\Services\Marketing;

use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class BirthdayRewardEngineService
{
    public function __construct(
        protected CandleCashService $candleCashService,
        protected BirthdayProfileService $birthdayProfileService,
        protected BirthdayEmailDispatchService $birthdayEmailDispatchService
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function statusForProfile(?CustomerBirthdayProfile $birthdayProfile, array $options = []): array
    {
        if (! $birthdayProfile || ! $birthdayProfile->birth_month || ! $birthdayProfile->birth_day) {
            return [
                'state' => 'add_birthday_unlock_reward',
                'eligible' => false,
                'reward_ready' => false,
                'already_claimed' => false,
                'issuance' => null,
                'claim_window' => null,
            ];
        }

        $config = $this->rewardConfig();
        $rewardType = (string) ($config['reward_type'] ?? 'candle_cash');
        $cycleYear = (int) ($options['cycle_year'] ?? now()->year);
        $window = $this->claimWindow($birthdayProfile, $cycleYear, $config);
        $now = now()->toImmutable();

        $issuance = BirthdayRewardIssuance::query()
            ->where('customer_birthday_profile_id', $birthdayProfile->id)
            ->where('cycle_year', $cycleYear)
            ->where('reward_type', $rewardType)
            ->orderByDesc('id')
            ->first();

        if ($issuance && (string) $issuance->status === 'redeemed') {
            return [
                'state' => 'birthday_reward_redeemed',
                'eligible' => false,
                'reward_ready' => false,
                'already_claimed' => true,
                'issuance' => $issuance,
                'claim_window' => $window,
            ];
        }

        if ($issuance && (string) $issuance->status === 'expired') {
            return [
                'state' => 'birthday_reward_expired',
                'eligible' => false,
                'reward_ready' => false,
                'already_claimed' => false,
                'issuance' => $issuance,
                'claim_window' => $window,
            ];
        }

        if ($issuance && in_array((string) $issuance->status, ['claimed'], true)) {
            return [
                'state' => 'already_claimed',
                'eligible' => false,
                'reward_ready' => false,
                'already_claimed' => true,
                'issuance' => $issuance,
                'claim_window' => $window,
            ];
        }

        if ($issuance && (string) $issuance->status === 'issued') {
            return [
                'state' => 'birthday_reward_ready',
                'eligible' => true,
                'reward_ready' => true,
                'already_claimed' => false,
                'issuance' => $issuance,
                'claim_window' => $window,
            ];
        }

        if ($window['starts_at'] && $window['ends_at'] && $now->betweenIncluded($window['starts_at'], $window['ends_at'])) {
            return [
                'state' => 'birthday_reward_eligible',
                'eligible' => true,
                'reward_ready' => false,
                'already_claimed' => false,
                'issuance' => null,
                'claim_window' => $window,
            ];
        }

        return [
            'state' => 'birthday_saved',
            'eligible' => false,
            'reward_ready' => false,
            'already_claimed' => false,
            'issuance' => null,
            'claim_window' => $window,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function issueAnnualReward(CustomerBirthdayProfile $birthdayProfile, array $options = []): array
    {
        $config = $this->rewardConfig();
        if (! (bool) ($config['enabled'] ?? true)) {
            return [
                'ok' => false,
                'state' => 'birthday_rewards_disabled',
                'error' => 'birthday_rewards_disabled',
            ];
        }

        if (! $birthdayProfile->birth_month || ! $birthdayProfile->birth_day) {
            return [
                'ok' => false,
                'state' => 'add_birthday_unlock_reward',
                'error' => 'missing_birthday',
            ];
        }

        $cycleYear = (int) ($options['cycle_year'] ?? now()->year);
        $rewardType = $this->normalizeRewardType((string) ($config['reward_type'] ?? 'candle_cash'));
        $window = $this->claimWindow($birthdayProfile, $cycleYear, $config);
        $now = now()->toImmutable();

        if ($window['starts_at'] && $window['ends_at'] && ! $now->betweenIncluded($window['starts_at'], $window['ends_at'])) {
            return [
                'ok' => false,
                'state' => 'outside_claim_window',
                'error' => 'outside_claim_window',
                'claim_window' => $window,
            ];
        }

        $result = DB::transaction(function () use ($birthdayProfile, $cycleYear, $rewardType, $config, $window, $now): array {
            $locked = CustomerBirthdayProfile::query()
                ->whereKey($birthdayProfile->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new RuntimeException('Birthday profile no longer exists.');
            }

            $existing = BirthdayRewardIssuance::query()
                ->where('customer_birthday_profile_id', $locked->id)
                ->where('cycle_year', $cycleYear)
                ->where('reward_type', $rewardType)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                $state = in_array((string) $existing->status, ['claimed', 'redeemed'], true)
                    ? 'already_claimed'
                    : 'birthday_reward_ready';

                return [
                    'ok' => true,
                    'state' => $state,
                    'error' => null,
                    'issuance' => $existing,
                    'claim_window' => $window,
                ];
            }

            if ((int) ($locked->reward_last_issued_year ?? 0) === $cycleYear) {
                return [
                    'ok' => false,
                    'state' => 'already_claimed',
                    'error' => 'already_issued_this_cycle',
                    'claim_window' => $window,
                ];
            }

            $issuancePayload = [
                'customer_birthday_profile_id' => $locked->id,
                'marketing_profile_id' => $locked->marketing_profile_id,
                'cycle_year' => $cycleYear,
                'reward_type' => $rewardType,
                'reward_name' => $this->rewardName($config),
                'status' => 'issued',
                'candle_cash_awarded' => null,
                'reward_value' => $this->rewardValue($rewardType, $config),
                'reward_code' => null,
                'shopify_discount_id' => null,
                'shopify_store_key' => null,
                'shopify_discount_node_id' => null,
                'discount_sync_status' => $rewardType === 'candle_cash' ? 'not_applicable' : 'pending',
                'discount_sync_error' => null,
                'claim_window_starts_at' => $window['starts_at'],
                'claim_window_ends_at' => $window['ends_at'],
                'issued_at' => $now,
                'claimed_at' => null,
                'activated_at' => null,
                'expires_at' => $window['ends_at'],
                'redeemed_at' => null,
                'order_id' => null,
                'order_number' => null,
                'order_total' => null,
                'attributed_revenue' => null,
                'campaign_type' => 'birthday_email',
            ];

            if ($rewardType === 'candle_cash') {
                $candleCash = max(0, (int) ($config['candle_cash_amount'] ?? 0));
                if ($candleCash <= 0) {
                    throw new RuntimeException('Birthday Candle Cash reward is misconfigured.');
                }

                $result = $this->candleCashService->addPoints(
                    profile: $locked->marketingProfile,
                    points: $candleCash,
                    type: 'earn',
                    source: 'birthday_reward',
                    sourceId: 'birthday:'.$locked->id.':'.$cycleYear,
                    description: 'Birthday Candle Cash reward'
                );

                $issuancePayload['status'] = 'claimed';
                $issuancePayload['candle_cash_awarded'] = $candleCash;
                $issuancePayload['reward_value'] = (string) $candleCash;
                $issuancePayload['claimed_at'] = $now;
                $issuancePayload['activated_at'] = $now;
                $issuancePayload['metadata'] = [
                    'transaction_id' => (int) ($result['transaction_id'] ?? 0),
                    'balance_after' => (int) ($result['balance'] ?? 0),
                ];
            } elseif (in_array($rewardType, ['discount_code', 'free_shipping'], true)) {
                $issuancePayload['reward_code'] = $this->generateCode($rewardType, $cycleYear, $config);
            } else {
                throw new RuntimeException('Unsupported birthday reward type.');
            }

            /** @var BirthdayRewardIssuance $issuance */
            $issuance = BirthdayRewardIssuance::query()->create($issuancePayload);

            $locked->forceFill([
                'reward_last_issued_at' => $now,
                'reward_last_issued_year' => $cycleYear,
            ])->save();

            $this->birthdayProfileService->writeAudit(
                profile: $locked,
                action: 'birthday_reward_issued',
                source: 'birthday_reward_engine',
                isUncertain: false,
                payload: [
                    'cycle_year' => $cycleYear,
                    'reward_type' => $rewardType,
                    'issuance_id' => (int) $issuance->id,
                    'status' => (string) $issuance->status,
                ]
            );

            return [
                'ok' => true,
                'state' => (string) $issuance->status === 'claimed' ? 'already_claimed' : 'birthday_reward_ready',
                'error' => null,
                'issuance' => $issuance,
                'claim_window' => $window,
            ];
        });

        $issued = $result['issuance'] ?? null;
        if ($issued instanceof BirthdayRewardIssuance) {
            try {
                $result['email_delivery'] = $this->birthdayEmailDispatchService->sendIssuanceEmail($issued);
            } catch (\Throwable $exception) {
                $result['email_delivery'] = [
                    'ok' => false,
                    'success' => false,
                    'attempted' => true,
                    'already_recorded' => false,
                    'provider' => null,
                    'status' => 'failed',
                    'message_id' => null,
                    'delivery_id' => null,
                    'birthday_message_event_id' => null,
                    'event_key' => null,
                    'error_code' => 'birthday_email_dispatch_exception',
                    'error_message' => $exception->getMessage(),
                ];
            }
        }

        return $result;
    }

    public function generateUniqueCodeForRewardType(string $rewardType, int $cycleYear, ?array $config = null): string
    {
        return $this->generateCode(
            $this->normalizeRewardType($rewardType),
            $cycleYear,
            $config ?: $this->rewardConfig()
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function claimIssuedReward(CustomerBirthdayProfile $birthdayProfile, ?int $cycleYear = null): array
    {
        $cycleYear = $cycleYear ?: (int) now()->year;
        $config = $this->rewardConfig();
        $rewardType = $this->normalizeRewardType((string) ($config['reward_type'] ?? 'candle_cash'));

        return DB::transaction(function () use ($birthdayProfile, $cycleYear, $rewardType): array {
            $locked = CustomerBirthdayProfile::query()
                ->whereKey($birthdayProfile->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new RuntimeException('Birthday profile no longer exists.');
            }

            $issuance = BirthdayRewardIssuance::query()
                ->where('customer_birthday_profile_id', $locked->id)
                ->where('cycle_year', $cycleYear)
                ->where('reward_type', $rewardType)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if (! $issuance) {
                return [
                    'ok' => false,
                    'state' => 'birthday_reward_not_ready',
                    'error' => 'reward_not_issued',
                    'issuance' => null,
                ];
            }

            if (in_array((string) $issuance->status, ['claimed', 'redeemed'], true)) {
                return [
                    'ok' => true,
                    'state' => 'already_claimed',
                    'error' => null,
                    'issuance' => $issuance,
                ];
            }

            if ((string) $issuance->status !== 'issued') {
                return [
                    'ok' => false,
                    'state' => 'birthday_reward_not_ready',
                    'error' => 'reward_not_claimable',
                    'issuance' => $issuance,
                ];
            }

            $now = now();
            if ($issuance->claim_window_starts_at && $now->lt($issuance->claim_window_starts_at)) {
                return [
                    'ok' => false,
                    'state' => 'outside_claim_window',
                    'error' => 'outside_claim_window',
                    'issuance' => $issuance,
                ];
            }
            if ($issuance->claim_window_ends_at && $now->gt($issuance->claim_window_ends_at)) {
                $issuance->forceFill(['status' => 'expired'])->save();

                return [
                    'ok' => false,
                    'state' => 'outside_claim_window',
                    'error' => 'outside_claim_window',
                    'issuance' => $issuance->fresh(),
                ];
            }

            $issuance->forceFill([
                'status' => 'claimed',
                'claimed_at' => $now,
            ])->save();

            $locked->forceFill([
                'reward_last_issued_at' => $locked->reward_last_issued_at ?: $issuance->issued_at ?: $now,
                'reward_last_issued_year' => $locked->reward_last_issued_year ?: (int) $cycleYear,
            ])->save();

            $this->birthdayProfileService->writeAudit(
                profile: $locked,
                action: 'birthday_reward_claimed',
                source: 'birthday_reward_claim',
                isUncertain: false,
                payload: [
                    'cycle_year' => $cycleYear,
                    'reward_type' => $rewardType,
                    'issuance_id' => (int) $issuance->id,
                    'status' => (string) $issuance->status,
                ]
            );

            return [
                'ok' => true,
                'state' => 'already_claimed',
                'error' => null,
                'issuance' => $issuance->fresh(),
            ];
        });
    }

    /**
     * @param array<string,mixed> $config
     * @return array{starts_at:?CarbonImmutable,ends_at:?CarbonImmutable,birthday_date:?CarbonImmutable}
     */
    public function claimWindow(CustomerBirthdayProfile $birthdayProfile, int $cycleYear, array $config): array
    {
        $birthdayDate = $this->cycleBirthdayDate($birthdayProfile, $cycleYear);
        if (! $birthdayDate) {
            return [
                'starts_at' => null,
                'ends_at' => null,
                'birthday_date' => null,
            ];
        }

        $daysBefore = max(0, (int) ($config['claim_window_days_before'] ?? 0));
        $daysAfter = max(0, (int) ($config['claim_window_days_after'] ?? 14));

        return [
            'starts_at' => $birthdayDate->startOfDay()->subDays($daysBefore),
            'ends_at' => $birthdayDate->endOfDay()->addDays($daysAfter),
            'birthday_date' => $birthdayDate,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function rewardConfig(): array
    {
        $fallback = (array) config('marketing.birthday_rewards', []);

        $configured = (array) optional(\App\Models\MarketingSetting::query()->where('key', 'birthday_reward_config')->first())->value;

        return array_merge([
            'enabled' => true,
            'reward_type' => 'discount_code',
            'reward_name' => 'Birthday Candle Cash',
            'reward_value' => 10.00,
            'candle_cash_amount' => 50,
            'discount_code_prefix' => 'BDAY',
            'free_shipping_code_prefix' => 'BDAYSHIP',
            'claim_window_days_before' => 0,
            'claim_window_days_after' => 14,
        ], $fallback, $configured);
    }

    public function cycleBirthdayDate(CustomerBirthdayProfile $birthdayProfile, int $cycleYear): ?CarbonImmutable
    {
        $month = (int) ($birthdayProfile->birth_month ?? 0);
        $day = (int) ($birthdayProfile->birth_day ?? 0);
        if ($month < 1 || $month > 12 || $day < 1) {
            return null;
        }

        $daysInMonth = CarbonImmutable::create($cycleYear, $month, 1, 0, 0, 0, 'UTC')->daysInMonth;
        $safeDay = min($day, $daysInMonth);

        return CarbonImmutable::create($cycleYear, $month, $safeDay, 0, 0, 0, config('app.timezone'));
    }

    /**
     * @param array<string,mixed> $config
     */
    protected function generateCode(string $rewardType, int $cycleYear, array $config): string
    {
        $prefix = $rewardType === 'free_shipping'
            ? (string) ($config['free_shipping_code_prefix'] ?? 'BDAYSHIP')
            : (string) ($config['discount_code_prefix'] ?? 'BDAY');

        $prefix = trim(preg_replace('/[^A-Za-z0-9]/', '', strtoupper($prefix)) ?? '');
        if ($prefix === '') {
            $prefix = $rewardType === 'free_shipping' ? 'BDAYSHIP' : 'BDAY';
        }

        do {
            $code = $prefix.'-'.$cycleYear.'-'.Str::upper(Str::random(6));
        } while (BirthdayRewardIssuance::query()->where('reward_code', $code)->exists());

        return $code;
    }

    protected function normalizeRewardType(string $rewardType): string
    {
        $normalized = strtolower(trim($rewardType));

        if ($normalized === 'points') {
            app(CandleCashLegacyCompatibilityService::class)->record(
                'birthday_reward_config.reward_type',
                'normalization',
                __METHOD__
            );
        }

        return match ($normalized) {
            'candle_cash', 'discount_code', 'free_shipping' => $normalized,
            'points' => 'candle_cash',
            'coupon', 'discount' => 'discount_code',
            'shipping', 'free_ship' => 'free_shipping',
            default => 'candle_cash',
        };
    }

    /**
     * @param array<string,mixed> $config
     */
    protected function rewardName(array $config): string
    {
        $name = trim((string) ($config['reward_name'] ?? ''));

        return $name !== '' ? $name : 'Birthday Candle Cash';
    }

    /**
     * @param array<string,mixed> $config
     */
    protected function rewardValue(string $rewardType, array $config): ?string
    {
        if ($rewardType === 'candle_cash') {
            return (string) max(0, (int) ($config['candle_cash_amount'] ?? 0));
        }

        $value = $config['reward_value'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
