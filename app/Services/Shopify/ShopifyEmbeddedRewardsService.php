<?php

namespace App\Services\Shopify;

use App\Models\CandleCashReward;
use App\Models\CandleCashTask;
use App\Models\MarketingSetting;
use App\Models\TenantCandleCashRewardOverride;
use App\Models\TenantCandleCashTaskOverride;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\TenantRewardsPolicyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShopifyEmbeddedRewardsService
{
    public function __construct(
        protected CandleCashService $candleCashService,
        protected TenantRewardsPolicyService $tenantRewardsPolicyService
    ) {
    }

    /**
     * @return array{
     *   meta:array<string,mixed>,
     *   earn:array<string,mixed>,
     *   redeem:array<string,mixed>
     * }
     */
    public function payload(?int $tenantId = null): array
    {
        $programConfig = $this->programConfig($tenantId);
        $policy = $this->tenantRewardsPolicyService->resolve($tenantId);
        $redeemIncrement = round((float) data_get($programConfig, 'redeem_increment_dollars', $this->candleCashService->fixedRedemptionAmount()), 2);
        $maxRedeemable = round((float) data_get($programConfig, 'max_redeemable_per_order_dollars', $this->candleCashService->maxRedeemablePerOrderAmount()), 2);
        $maxOpenCodes = max(1, (int) data_get($programConfig, 'max_open_codes', $this->candleCashService->maxOpenStorefrontCodes()));
        $earn = $this->sectionPayload(fn (): array => $this->earnSection($tenantId));
        $redeem = $this->sectionPayload(fn (): array => $this->redeemSection($tenantId));

        return [
            'meta' => [
                'program' => [
                    'measurement_label' => '1 reward credit = 1 reward credit',
                    'redeem_increment_dollars' => $redeemIncrement,
                    'redeem_increment_formatted' => $this->candleCashService->formatRewardCurrency($redeemIncrement),
                    'max_redeemable_per_order_dollars' => $maxRedeemable,
                    'max_redeemable_per_order_formatted' => $this->candleCashService->formatRewardCurrency($maxRedeemable),
                    'max_open_codes' => $maxOpenCodes,
                    'program_name' => (string) data_get($policy, 'program_identity.program_name', 'Rewards'),
                ],
                'policy' => $policy,
                'limitations' => [
                    [
                        'scope' => 'redeem',
                        'message' => 'Minimum order requirements are not stored on current reward rows, so that field remains unavailable in this embedded page.',
                    ],
                ],
            ],
            'earn' => $earn,
            'redeem' => $redeem,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function policy(?int $tenantId = null, array $context = []): array
    {
        return $this->tenantRewardsPolicyService->resolve($tenantId, $context);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function updatePolicy(int $tenantId, array $payload, array $context = []): array
    {
        return $this->tenantRewardsPolicyService->update($tenantId, $payload, $context);
    }

    /**
     * @return array{
     *   program_name:string,
     *   measurement_label:string,
     *   earning_rules_active:bool,
     *   earning_rule_count:int,
     *   redeem_rules_active:bool,
     *   redeem_rule_count:int,
     *   program_summary:string,
     *   earning_modes:array<int,string>,
     *   earn_preview:array<int,array{title:string,detail:string}>,
     *   redeem_preview:array<int,array{title:string,detail:string}>
     * }
     */
    public function overview(?int $tenantId = null): array
    {
        $payload = $this->payload($tenantId);
        $earnItems = collect((array) data_get($payload, 'earn.items', []));
        $redeemItems = collect((array) data_get($payload, 'redeem.items', []));
        $activeEarnItems = $earnItems->where('enabled', true)->values();
        $activeRedeemItems = $redeemItems->where('enabled', true)->values();
        $primaryReward = $activeRedeemItems->first();
        $programSummary = $primaryReward
            ? 'Customers earn reward credit through live tasks, then redeem it for rewards like '.(string) data_get($primaryReward, 'title', 'configured rewards').'.'
            : 'Customers earn reward credit through live tasks and can redeem it against the reward rows configured in Backstage.';

        return [
            'program_name' => 'Rewards',
            'measurement_label' => (string) data_get($payload, 'meta.program.measurement_label', '1 reward credit = $1.00'),
            'earning_rules_active' => $activeEarnItems->isNotEmpty(),
            'earning_rule_count' => $activeEarnItems->count(),
            'redeem_rules_active' => $activeRedeemItems->isNotEmpty(),
            'redeem_rule_count' => $activeRedeemItems->count(),
            'program_summary' => $programSummary,
            'earning_modes' => $activeEarnItems
                ->pluck('action_type_label')
                ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
                ->values()
                ->unique()
                ->all(),
            'earn_preview' => $activeEarnItems
                ->take(3)
                ->map(fn (array $item): array => [
                    'title' => (string) ($item['title'] ?? 'Earn rule'),
                    'detail' => (string) ($item['candle_cash_value_formatted'] ?? '$0 reward credit'),
                ])
                ->values()
                ->all(),
            'redeem_preview' => $activeRedeemItems
                ->take(3)
                ->map(fn (array $item): array => [
                    'title' => (string) ($item['title'] ?? 'Redeem rule'),
                    'detail' => (string) ($item['candle_cash_cost_formatted'] ?? '$0 reward credit'),
                ])
                ->values()
                ->all(),
        ];
    }

    public function resolveEarnRule(int $taskId, ?int $tenantId = null): CandleCashTask
    {
        $task = CandleCashTask::query()
            ->whereKey($taskId)
            ->whereNull('archived_at')
            ->first();

        if ($task) {
            return $task;
        }

        $exception = new ModelNotFoundException();
        $exception->setModel(CandleCashTask::class, [$taskId, $tenantId]);

        throw $exception;
    }

    public function resolveRedeemRule(int $rewardId, ?int $tenantId = null): CandleCashReward
    {
        $reward = CandleCashReward::query()->whereKey($rewardId)->first();

        if ($reward) {
            return $reward;
        }

        $exception = new ModelNotFoundException();
        $exception->setModel(CandleCashReward::class, [$rewardId, $tenantId]);

        throw $exception;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function updateEarnRule(CandleCashTask $task, array $data, ?int $tenantId = null): array
    {
        $rewardAmount = array_key_exists('candle_cash_value', $data) && $data['candle_cash_value'] !== null
            ? round(max(0, (float) $data['candle_cash_value']), 2)
            : round($this->candleCashService->candleCashFromLegacyPoints(max(0, (int) ($data['points_value'] ?? 0))), 2);
        $title = trim((string) ($data['title'] ?? ''));
        $description = $this->nullableString($data['description'] ?? null);
        $enabled = (bool) ($data['enabled'] ?? false);
        $displayOrder = max(0, (int) ($data['sort_order'] ?? 0));

        $override = null;

        if ($tenantId === null) {
            $task->forceFill([
                'title' => $title,
                'description' => $description,
                'reward_amount' => $rewardAmount,
                'enabled' => $enabled,
                'display_order' => $displayOrder,
            ])->save();
            $task = $task->fresh();
        } else {
            $override = $this->persistTaskOverride(
                tenantId: $tenantId,
                task: $task,
                title: $title,
                description: $description,
                rewardAmount: $rewardAmount,
                enabled: $enabled,
                displayOrder: $displayOrder
            );
        }

        $this->syncTaskConfigAmount((string) $task->handle, $rewardAmount, $tenantId);

        return $this->earnActionRow($task, $override);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function updateRedeemRule(CandleCashReward $reward, array $data, ?int $tenantId = null): array
    {
        $existingOverride = $tenantId !== null
            ? $this->rewardOverrideForTenant($tenantId, (int) $reward->id)
            : null;
        $resolvedReward = $this->resolvedRewardAttributes($reward, $existingOverride);
        $title = trim((string) ($data['title'] ?? ''));
        $description = $this->nullableString($data['description'] ?? null);
        $candleCashCost = array_key_exists('candle_cash_cost', $data) && $data['candle_cash_cost'] !== null
            ? round(max(0, (float) $data['candle_cash_cost']), 2)
            : round($this->candleCashService->candleCashFromLegacyPoints(max(0, (int) ($data['candle_cash_cost'] ?? 0))), 2);
        $pointsCost = $this->candleCashService->pointsFromAmount($candleCashCost);
        $rewardValue = $this->nullableString($data['reward_value'] ?? null);
        $enabled = (bool) ($data['enabled'] ?? false);

        if ($this->isStorefrontReward($resolvedReward, $tenantId)) {
            $parsedValue = $this->parseRewardValueAmount($rewardValue ?? '');
            if ($parsedValue === null) {
                throw ValidationException::withMessages([
                    'reward_value' => 'The storefront reward needs a numeric discount value, such as 10USD or 10.',
                ]);
            }

            $expectedCost = $this->candleCashService->pointsFromAmount($parsedValue);
            if ($pointsCost !== $expectedCost) {
                throw ValidationException::withMessages([
                    'candle_cash_cost' => 'Storefront reward cost is derived from the discount value and current reward value.',
                ]);
            }

            $this->syncStorefrontProgramConfig(
                rewardType: (string) ($resolvedReward['reward_type'] ?? $reward->reward_type),
                rewardValue: $rewardValue ?? '',
                discountAmount: $parsedValue,
                tenantId: $tenantId
            );
        }

        $override = null;

        if ($tenantId === null) {
            $reward->forceFill([
                'name' => $title,
                'description' => $description,
                'candle_cash_cost' => $pointsCost,
                'reward_value' => $rewardValue,
                'is_active' => $enabled,
            ])->save();
            $reward = $reward->fresh();
        } else {
            $override = $this->persistRewardOverride(
                tenantId: $tenantId,
                reward: $reward,
                title: $title,
                description: $description,
                candleCashCost: $pointsCost,
                rewardValue: $rewardValue,
                enabled: $enabled
            );
        }

        return $this->redeemRewardRow($reward, $override, $tenantId);
    }

    /**
     * @return array<string,mixed>
     */
    protected function earnSection(?int $tenantId = null): array
    {
        /** @var Collection<int,CandleCashTask> $tasks */
        $tasks = CandleCashTask::query()
            ->whereNull('archived_at')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $overrides = $this->taskOverridesForTenant($tenantId, $tasks->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $items = $tasks
            ->map(function (CandleCashTask $task) use ($overrides): array {
                /** @var TenantCandleCashTaskOverride|null $override */
                $override = $overrides->get((int) $task->id);

                return $this->earnActionRow($task, $override);
            })
            ->values()
            ->all();

        usort($items, function (array $left, array $right): int {
            $leftOrder = [(int) ($left['sort_order'] ?? 0), (int) ($left['id'] ?? 0)];
            $rightOrder = [(int) ($right['sort_order'] ?? 0), (int) ($right['id'] ?? 0)];

            return $leftOrder <=> $rightOrder;
        });

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
    protected function redeemSection(?int $tenantId = null): array
    {
        /** @var Collection<int,CandleCashReward> $rewards */
        $rewards = CandleCashReward::query()
            ->orderBy('candle_cash_cost')
            ->orderBy('id')
            ->get();

        $overrides = $this->rewardOverridesForTenant($tenantId, $rewards->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $items = $rewards
            ->map(function (CandleCashReward $reward) use ($overrides, $tenantId): array {
                /** @var TenantCandleCashRewardOverride|null $override */
                $override = $overrides->get((int) $reward->id);

                return $this->redeemRewardRow($reward, $override, $tenantId);
            })
            ->values()
            ->all();

        usort($items, function (array $left, array $right): int {
            $leftOrder = [(float) ($left['candle_cash_cost'] ?? 0), (int) ($left['id'] ?? 0)];
            $rightOrder = [(float) ($right['candle_cash_cost'] ?? 0), (int) ($right['id'] ?? 0)];

            return $leftOrder <=> $rightOrder;
        });

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
    protected function earnActionRow(CandleCashTask $task, ?TenantCandleCashTaskOverride $override = null): array
    {
        $rewardAmount = $override
            ? round((float) $override->reward_amount, 2)
            : round((float) $task->reward_amount, 2);
        $actionType = trim((string) ($task->verification_mode ?: $task->task_type));
        $enabled = $override ? (bool) $override->enabled : (bool) $task->enabled;

        return [
            'id' => (int) $task->id,
            'code' => (string) $task->handle,
            'title' => (string) ($override?->title ?? $task->title),
            'description' => $override
                ? $this->nullableString($override->description)
                : ($task->description ? (string) $task->description : null),
            'candle_cash_value' => $rewardAmount,
            'candle_cash_value_formatted' => $this->candleCashService->formatCandleCash($rewardAmount),
            'reward_amount' => $rewardAmount,
            'reward_amount_formatted' => $this->candleCashService->formatRewardCurrency($rewardAmount),
            'action_type' => $actionType,
            'action_type_label' => $this->labelize($actionType),
            'task_type' => (string) $task->task_type,
            'task_type_label' => $this->labelize((string) $task->task_type),
            'verification_mode' => (string) ($task->verification_mode ?? ''),
            'verification_mode_label' => $this->labelize((string) ($task->verification_mode ?? '')),
            'enabled' => $enabled,
            'status_label' => $enabled ? 'Enabled' : 'Disabled',
            'sort_order' => $override ? (int) $override->display_order : (int) $task->display_order,
            'customer_visible' => (bool) data_get($task->metadata, 'customer_visible', true),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function redeemRewardRow(
        CandleCashReward $reward,
        ?TenantCandleCashRewardOverride $override = null,
        ?int $tenantId = null
    ): array {
        $resolved = $this->resolvedRewardAttributes($reward, $override);
        $candleCashCost = $this->candleCashService->amountFromPoints((int) ($resolved['candle_cash_cost'] ?? 0));

        return [
            'id' => (int) $reward->id,
            'code' => 'reward-' . $reward->id,
            'title' => (string) ($resolved['name'] ?? 'Reward'),
            'description' => $this->nullableString($resolved['description'] ?? null),
            'reward_type' => (string) ($resolved['reward_type'] ?? ''),
            'reward_type_label' => $this->labelize((string) ($resolved['reward_type'] ?? '')),
            'candle_cash_cost' => $candleCashCost,
            'candle_cash_cost_formatted' => $this->candleCashService->formatCandleCash($candleCashCost),
            'reward_value' => array_key_exists('reward_value', $resolved) && $resolved['reward_value'] !== null
                ? (string) $resolved['reward_value']
                : null,
            'value_display' => $this->rewardValueDisplay($resolved, $tenantId),
            'minimum_order_amount' => null,
            'minimum_order_supported' => false,
            'enabled' => (bool) ($resolved['is_active'] ?? false),
            'status_label' => (bool) ($resolved['is_active'] ?? false) ? 'Enabled' : 'Disabled',
            'is_storefront_reward' => $this->isStorefrontReward($resolved, $tenantId),
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

    protected function syncTaskConfigAmount(string $handle, float $rewardAmount, ?int $tenantId = null): void
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
            $config = $this->rawSettingValue('candle_cash_program_config', $tenantId);
            $config[$programKeyMap[$handle]] = $rewardAmount;
            $this->saveSetting(
                'candle_cash_program_config',
                $config,
                'Core rewards program settings for label text, reward math, and frontend messaging.',
                $tenantId
            );
        }

        $referralKeyMap = [
            'refer-a-friend' => 'referrer_reward_amount',
            'referred-friend-bonus' => 'referred_reward_amount',
        ];

        if (array_key_exists($handle, $referralKeyMap)) {
            $config = $this->rawSettingValue('candle_cash_referral_config', $tenantId);
            $config[$referralKeyMap[$handle]] = $rewardAmount;
            $this->saveSetting(
                'candle_cash_referral_config',
                $config,
                'Referral program settings for rewards growth tasks.',
                $tenantId
            );
        }
    }

    protected function syncStorefrontProgramConfig(
        string $rewardType,
        string $rewardValue,
        float $discountAmount,
        ?int $tenantId = null
    ): void {
        $raw = $this->rawSettingValue('candle_cash_program_config', $tenantId);
        $effective = array_merge(
            $raw,
            $this->programConfig($tenantId)
        );

        $effective['candle_cash_units_per_dollar'] = $this->candleCashService->pointsPerDollar();
        $effective['legacy_points_per_candle_cash'] = $this->candleCashService->legacyPointsPerCandleCash();
        $effective['redeem_increment_dollars'] = $discountAmount;
        $effective['max_redeemable_per_order_dollars'] = $this->candleCashService->maxRedeemablePerOrderAmount();
        $effective['max_open_codes'] = $this->candleCashService->maxOpenStorefrontCodes();
        $effective['storefront_reward_type'] = strtolower(trim($rewardType)) ?: 'coupon';
        $effective['storefront_reward_value'] = trim($rewardValue) ?: $rewardValue;

        $this->saveSetting(
            'candle_cash_program_config',
            $effective,
            'Core rewards program settings for label text, reward math, and frontend messaging.',
            $tenantId
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function rawSettingValue(string $key, ?int $tenantId = null): array
    {
        if ($tenantId !== null && Schema::hasTable('tenant_marketing_settings')) {
            $tenantSetting = TenantMarketingSetting::query()
                ->where('tenant_id', $tenantId)
                ->where('key', $key)
                ->first();

            if ($tenantSetting) {
                return is_array($tenantSetting->value) ? $tenantSetting->value : [];
            }
        }

        return (array) optional(MarketingSetting::query()->where('key', $key)->first())->value;
    }

    /**
     * @param  array<string,mixed>  $value
     */
    protected function saveSetting(string $key, array $value, string $description, ?int $tenantId = null): void
    {
        if ($tenantId !== null) {
            if (! Schema::hasTable('tenant_marketing_settings')) {
                throw new \RuntimeException('tenant_marketing_settings table is required for tenant-scoped rewards configuration.');
            }

            TenantMarketingSetting::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'key' => $key,
                ],
                [
                    'value' => $value,
                    'description' => $description,
                ]
            );

            return;
        }

        MarketingSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'description' => $description,
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $reward
     */
    protected function rewardValueDisplay(array $reward, ?int $tenantId = null): string
    {
        $rewardType = strtolower(trim((string) ($reward['reward_type'] ?? '')));
        $rawValue = trim((string) ($reward['reward_value'] ?? ''));

        if ($rewardType === 'coupon') {
            $amount = $this->rewardValueAmount($rawValue);

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

    /**
     * @param  array<int,int>  $taskIds
     * @return Collection<int,TenantCandleCashTaskOverride>
     */
    protected function taskOverridesForTenant(?int $tenantId, array $taskIds): Collection
    {
        if ($tenantId === null || $taskIds === [] || ! Schema::hasTable('tenant_candle_cash_task_overrides')) {
            return collect();
        }

        return TenantCandleCashTaskOverride::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('candle_cash_task_id', $taskIds)
            ->get()
            ->keyBy('candle_cash_task_id');
    }

    /**
     * @param  array<int,int>  $rewardIds
     * @return Collection<int,TenantCandleCashRewardOverride>
     */
    protected function rewardOverridesForTenant(?int $tenantId, array $rewardIds): Collection
    {
        if ($tenantId === null || $rewardIds === [] || ! Schema::hasTable('tenant_candle_cash_reward_overrides')) {
            return collect();
        }

        return TenantCandleCashRewardOverride::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('candle_cash_reward_id', $rewardIds)
            ->get()
            ->keyBy('candle_cash_reward_id');
    }

    protected function rewardOverrideForTenant(int $tenantId, int $rewardId): ?TenantCandleCashRewardOverride
    {
        if (! Schema::hasTable('tenant_candle_cash_reward_overrides')) {
            return null;
        }

        return TenantCandleCashRewardOverride::query()
            ->where('tenant_id', $tenantId)
            ->where('candle_cash_reward_id', $rewardId)
            ->first();
    }

    protected function persistTaskOverride(
        int $tenantId,
        CandleCashTask $task,
        string $title,
        ?string $description,
        float $rewardAmount,
        bool $enabled,
        int $displayOrder
    ): TenantCandleCashTaskOverride {
        if (! Schema::hasTable('tenant_candle_cash_task_overrides')) {
            throw new \RuntimeException('tenant_candle_cash_task_overrides table is required for tenant-scoped rewards edits.');
        }

        return TenantCandleCashTaskOverride::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'candle_cash_task_id' => (int) $task->id,
            ],
            [
                'title' => $title,
                'description' => $description,
                'reward_amount' => $rewardAmount,
                'enabled' => $enabled,
                'display_order' => $displayOrder,
            ]
        );
    }

    protected function persistRewardOverride(
        int $tenantId,
        CandleCashReward $reward,
        string $title,
        ?string $description,
        int $candleCashCost,
        ?string $rewardValue,
        bool $enabled
    ): TenantCandleCashRewardOverride {
        if (! Schema::hasTable('tenant_candle_cash_reward_overrides')) {
            throw new \RuntimeException('tenant_candle_cash_reward_overrides table is required for tenant-scoped rewards edits.');
        }

        return TenantCandleCashRewardOverride::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'candle_cash_reward_id' => (int) $reward->id,
            ],
            [
                'name' => $title,
                'description' => $description,
                'candle_cash_cost' => $candleCashCost,
                'reward_value' => $rewardValue,
                'is_active' => $enabled,
            ]
        );
    }

    /**
     * @return array{
     *   id:int,
     *   name:string,
     *   description:?string,
     *   candle_cash_cost:int,
     *   reward_type:string,
     *   reward_value:?string,
     *   is_active:bool
     * }
     */
    protected function resolvedRewardAttributes(
        CandleCashReward $reward,
        ?TenantCandleCashRewardOverride $override = null
    ): array {
        return [
            'id' => (int) $reward->id,
            'name' => (string) ($override?->name ?? $reward->name),
            'description' => $override
                ? $this->nullableString($override->description)
                : ($reward->description ? (string) $reward->description : null),
            'candle_cash_cost' => $override
                ? (int) $override->candle_cash_cost
                : (int) $reward->candle_cash_cost,
            'reward_type' => (string) $reward->reward_type,
            'reward_value' => $override
                ? $this->nullableString($override->reward_value)
                : ($reward->reward_value !== null ? (string) $reward->reward_value : null),
            'is_active' => $override
                ? (bool) $override->is_active
                : (bool) $reward->is_active,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function programConfig(?int $tenantId = null): array
    {
        $configured = array_merge(
            (array) data_get(config('marketing', []), 'candle_cash', []),
            $this->rawSettingValue('candle_cash_program_config', $tenantId)
        );

        $legacyPointsPerCandleCash = max(
            1,
            (int) data_get(
                $configured,
                'legacy_points_per_candle_cash',
                data_get($configured, 'points_per_dollar', CandleCashService::DEFAULT_LEGACY_POINTS_PER_CANDLE_CASH)
            )
        );
        $redeemIncrement = round(max(0.01, (float) data_get($configured, 'redeem_increment_dollars', 10)), 2);
        $maxRedeemablePerOrder = round(max(0.01, (float) data_get($configured, 'max_redeemable_per_order_dollars', 10)), 2);
        $maxOpenCodes = max(1, (int) data_get($configured, 'max_open_codes', 1));
        $storefrontRewardType = strtolower(trim((string) data_get($configured, 'storefront_reward_type', 'coupon')));
        if ($storefrontRewardType === '') {
            $storefrontRewardType = 'coupon';
        }

        $storefrontRewardValue = trim((string) data_get($configured, 'storefront_reward_value', '10USD'));
        if ($storefrontRewardValue === '') {
            $storefrontRewardValue = rtrim(rtrim(number_format($redeemIncrement, 2, '.', ''), '0'), '.') . 'USD';
        }

        return array_merge($configured, [
            'candle_cash_units_per_dollar' => (int) data_get(
                $configured,
                'candle_cash_units_per_dollar',
                $this->candleCashService->pointsPerDollar()
            ),
            'legacy_points_per_candle_cash' => $legacyPointsPerCandleCash,
            'redeem_increment_dollars' => $redeemIncrement,
            'max_redeemable_per_order_dollars' => $maxRedeemablePerOrder,
            'max_open_codes' => $maxOpenCodes,
            'storefront_reward_type' => $storefrontRewardType,
            'storefront_reward_value' => $storefrontRewardValue,
        ]);
    }

    /**
     * @param  array<string,mixed>  $reward
     */
    protected function isStorefrontReward(array $reward, ?int $tenantId = null): bool
    {
        $programConfig = $this->programConfig($tenantId);
        $expectedType = strtolower(trim((string) data_get($programConfig, 'storefront_reward_type', 'coupon')));
        if ($expectedType === '') {
            $expectedType = 'coupon';
        }

        $expectedAmount = (float) data_get($programConfig, 'redeem_increment_dollars', 10);
        $rewardType = strtolower(trim((string) ($reward['reward_type'] ?? '')));
        $rewardName = strtolower(trim((string) ($reward['name'] ?? '')));
        $rewardAmount = $this->rewardValueAmount((string) ($reward['reward_value'] ?? ''));

        if ($rewardType === $expectedType && abs($rewardAmount - $expectedAmount) < 0.01) {
            return true;
        }

        $legacyAmountLabel = '$'.rtrim(rtrim(number_format($expectedAmount, 2, '.', ''), '0'), '.');

        return $rewardType === 'coupon'
            && (str_contains($rewardName, '$10') || str_contains($rewardName, strtolower($legacyAmountLabel)));
    }

    protected function rewardValueAmount(string $value): float
    {
        if (preg_match('/-?\d+(?:\.\d+)?/', $value, $matches) !== 1) {
            return 0.0;
        }

        return round((float) $matches[0], 2);
    }

    protected function parseRewardValueAmount(string $value): ?float
    {
        if (preg_match('/-?\d+(?:\.\d+)?/', $value, $matches) !== 1) {
            return null;
        }

        return round((float) $matches[0], 2);
    }
}
