<?php

namespace App\Services\Marketing;

use App\Models\BirthdayRewardIssuance;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class BirthdayRewardRedemptionReconciliationService
{
    public function __construct(
        protected BirthdayProfileService $birthdayProfileService,
        protected MarketingStorefrontEventLogger $eventLogger,
        protected MarketingAttributionSourceMetaBuilder $attributionSourceMetaBuilder
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,int>
     */
    public function reconcileShopifyOrder(Order $order, array $options = []): array
    {
        $codes = $this->normalizeCodes((array) ($options['codes'] ?? []));
        if ($codes === []) {
            $codes = $this->extractCodesFromText(implode(' ', array_filter([
                (string) ($order->order_number ?? ''),
                (string) ($order->shopify_name ?? ''),
                (string) ($order->internal_notes ?? ''),
            ])));
        }

        $profileIds = $this->profileIdsForOrder($order);
        $orderTotal = $this->normalizeAmount($options['order_total'] ?? null);

        return $this->reconcileCodes(
            codes: $codes,
            externalOrderSource: 'order',
            externalOrderId: (string) $order->id,
            profileIds: $profileIds,
            context: [
                'order_id' => (int) $order->id,
                'order_number' => (string) ($order->order_number ?: $order->shopify_name ?: ''),
                'shopify_order_id' => $order->shopify_order_id ? (string) $order->shopify_order_id : null,
                'order_total' => $orderTotal,
                'attribution_meta' => (array) ($options['attribution_meta'] ?? []),
            ]
        );
    }

    /**
     * @param array<int,string> $codes
     * @param array<int,int> $profileIds
     * @param array<string,mixed> $context
     * @return array<string,int>
     */
    public function reconcileCodes(
        array $codes,
        string $externalOrderSource,
        string $externalOrderId,
        array $profileIds = [],
        array $context = []
    ): array {
        $summary = [
            'processed' => 0,
            'matched' => 0,
            'redeemed' => 0,
            'already_redeemed' => 0,
            'rejected' => 0,
            'not_found' => 0,
        ];

        foreach ($this->birthdayCandidateCodes($codes) as $code) {
            $summary['processed']++;

            /** @var BirthdayRewardIssuance|null $issuance */
            $issuance = BirthdayRewardIssuance::query()
                ->with('birthdayProfile')
                ->where('reward_code', $code)
                ->latest('id')
                ->first();

            if (! $issuance) {
                $summary['not_found']++;
                $this->eventLogger->log('birthday_reward_unmatched_code_observed', [
                    'status' => 'error',
                    'issue_type' => 'birthday_reward_code_not_found',
                    'source_surface' => 'ingestion',
                    'endpoint' => $externalOrderSource,
                    'source_type' => $externalOrderSource,
                    'source_id' => $externalOrderId,
                    'dedupe_key' => sha1('birthday_reward_unmatched|' . $code . '|' . $externalOrderSource . '|' . $externalOrderId),
                    'meta' => [
                        'reward_code' => $code,
                    ],
                ]);

                continue;
            }

            $summary['matched']++;

            if ($profileIds !== [] && ! in_array((int) $issuance->marketing_profile_id, $profileIds, true)) {
                $summary['rejected']++;
                $this->logRejected($issuance, 'profile_mismatch', $code, $externalOrderSource, $externalOrderId);

                continue;
            }

            if ((string) $issuance->status === 'cancelled' || $issuance->isExpired()) {
                $summary['rejected']++;
                $this->logRejected(
                    $issuance,
                    (string) $issuance->status === 'cancelled' ? 'reward_cancelled' : 'reward_expired',
                    $code,
                    $externalOrderSource,
                    $externalOrderId
                );

                continue;
            }

            if ((string) $issuance->status === 'redeemed') {
                if ((int) ($issuance->order_id ?? 0) === (int) ($context['order_id'] ?? 0)) {
                    $summary['already_redeemed']++;

                    $this->eventLogger->log('birthday_reward_duplicate_replay_ignored', [
                        'status' => 'ok',
                        'issue_type' => null,
                        'source_surface' => 'ingestion',
                        'endpoint' => $externalOrderSource,
                        'marketing_profile_id' => (int) $issuance->marketing_profile_id,
                        'source_type' => $externalOrderSource,
                        'source_id' => $externalOrderId,
                        'dedupe_key' => sha1('birthday_reward_already_redeemed|' . $issuance->id . '|' . $externalOrderSource . '|' . $externalOrderId),
                        'meta' => [
                            'reward_code' => $code,
                            'issuance_id' => (int) $issuance->id,
                        ],
                        'resolution_status' => 'resolved',
                    ]);

                    continue;
                }

                $summary['rejected']++;
                $this->logRejected($issuance, 'reward_already_used', $code, $externalOrderSource, $externalOrderId);

                continue;
            }

            $redeemed = DB::transaction(function () use ($issuance, $context): BirthdayRewardIssuance {
                /** @var BirthdayRewardIssuance $locked */
                $locked = BirthdayRewardIssuance::query()
                    ->with('birthdayProfile')
                    ->whereKey($issuance->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((string) $locked->status === 'redeemed') {
                    return $locked;
                }

                $redeemedAt = now();
                $orderTotal = $this->normalizeAmount($context['order_total'] ?? null);

                $locked->forceFill([
                    'status' => 'redeemed',
                    'claimed_at' => $locked->claimed_at ?: $redeemedAt,
                    'activated_at' => $locked->resolvedActivationAt() ?: $redeemedAt,
                    'redeemed_at' => $locked->redeemed_at ?: $redeemedAt,
                    'order_id' => (int) ($context['order_id'] ?? 0) > 0 ? (int) $context['order_id'] : $locked->order_id,
                    'order_number' => trim((string) ($context['order_number'] ?? '')) !== '' ? trim((string) ($context['order_number'] ?? '')) : $locked->order_number,
                    'order_total' => $orderTotal !== null ? $orderTotal : $locked->order_total,
                    'attributed_revenue' => $orderTotal !== null ? $orderTotal : $locked->attributed_revenue,
                    'discount_sync_status' => $locked->resolvedDiscountSyncStatus() === 'failed' ? 'synced' : $locked->resolvedDiscountSyncStatus(),
                    'discount_sync_error' => null,
                    'metadata' => $this->attributionSourceMetaBuilder->mergeSourceMeta(
                        is_array($locked->metadata ?? null) ? $locked->metadata : [],
                        is_array($context['attribution_meta'] ?? null) ? $context['attribution_meta'] : []
                    ),
                ])->save();

                return $locked->fresh();
            });

            $summary['redeemed']++;
            $this->writeAudit($redeemed, 'birthday_reward_redeemed_from_order', [
                'reward_code' => $code,
                'order_id' => $context['order_id'] ?? null,
                'order_number' => $context['order_number'] ?? null,
                'order_total' => $context['order_total'] ?? null,
            ]);

            $this->eventLogger->log('birthday_reward_redeemed_from_order', [
                'status' => 'ok',
                'issue_type' => null,
                'source_surface' => 'ingestion',
                'endpoint' => $externalOrderSource,
                'marketing_profile_id' => (int) $redeemed->marketing_profile_id,
                'source_type' => $externalOrderSource,
                'source_id' => $externalOrderId,
                'dedupe_key' => sha1('birthday_reward_redeemed|' . $redeemed->id . '|' . $externalOrderSource . '|' . $externalOrderId),
                'meta' => [
                    'reward_code' => $code,
                    'issuance_id' => (int) $redeemed->id,
                    'order_number' => $context['order_number'] ?? null,
                    'order_total' => $context['order_total'] ?? null,
                ],
                'resolution_status' => 'resolved',
            ]);
        }

        return $summary;
    }

    /**
     * @param array<int,string> $codes
     * @return array<int,string>
     */
    protected function birthdayCandidateCodes(array $codes): array
    {
        return collect($this->normalizeCodes($codes))
            ->filter(fn (string $code): bool => $this->looksLikeBirthdayCode($code))
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $codes
     * @return array<int,string>
     */
    protected function normalizeCodes(array $codes): array
    {
        return collect($codes)
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    protected function extractCodesFromText(string $value): array
    {
        $patterns = collect($this->codePrefixes())
            ->map(function (string $prefix): string {
                return preg_quote($prefix, '/');
            })
            ->filter()
            ->values();

        if ($patterns->isEmpty()) {
            return [];
        }

        preg_match_all('/\b(?:' . $patterns->implode('|') . ')-[A-Z0-9]{4,24}\b/i', strtoupper($value), $matches);

        return $this->normalizeCodes((array) ($matches[0] ?? []));
    }

    protected function looksLikeBirthdayCode(string $code): bool
    {
        foreach ($this->codePrefixes() as $prefix) {
            if (str_starts_with($code, strtoupper($prefix) . '-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    protected function codePrefixes(): array
    {
        $config = (array) optional(\App\Models\MarketingSetting::query()->where('key', 'birthday_reward_config')->first())->value;

        return collect([
            trim((string) ($config['discount_code_prefix'] ?? 'BDAY')),
            trim((string) ($config['free_shipping_code_prefix'] ?? 'BDAYSHIP')),
            'BDAY',
            'BDAYSHIP',
        ])
            ->map(fn (string $value): string => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,int>
     */
    protected function profileIdsForOrder(Order $order): array
    {
        $shopifySourceId = (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown') . ':' . $order->shopify_order_id;

        return MarketingProfileLink::query()
            ->where(function ($query) use ($order, $shopifySourceId): void {
                $query->where(function ($nested) use ($order): void {
                    $nested->where('source_type', 'order')
                        ->where('source_id', (string) $order->id);
                });

                if ($order->shopify_order_id) {
                    $query->orWhere(function ($nested) use ($shopifySourceId): void {
                        $nested->where('source_type', 'shopify_order')
                            ->where('source_id', $shopifySourceId);
                    });
                }
            })
            ->pluck('marketing_profile_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    protected function normalizeAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    protected function logRejected(
        BirthdayRewardIssuance $issuance,
        string $reason,
        string $code,
        string $externalOrderSource,
        string $externalOrderId
    ): void {
        $this->writeAudit($issuance, 'birthday_reward_reconcile_rejected', [
            'reward_code' => $code,
            'reason' => $reason,
            'external_order_source' => $externalOrderSource,
            'external_order_id' => $externalOrderId,
        ]);

        $this->eventLogger->log('birthday_reward_reconcile_rejected', [
            'status' => 'error',
            'issue_type' => $reason,
            'source_surface' => 'ingestion',
            'endpoint' => $externalOrderSource,
            'marketing_profile_id' => (int) $issuance->marketing_profile_id,
            'source_type' => $externalOrderSource,
            'source_id' => $externalOrderId,
            'dedupe_key' => sha1('birthday_reward_reject|' . $issuance->id . '|' . $externalOrderSource . '|' . $externalOrderId . '|' . $reason),
            'meta' => [
                'reward_code' => $code,
                'issuance_id' => (int) $issuance->id,
                'reason' => $reason,
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    protected function writeAudit(BirthdayRewardIssuance $issuance, string $action, array $payload = []): void
    {
        if (! $issuance->birthdayProfile) {
            return;
        }

        $this->birthdayProfileService->writeAudit(
            profile: $issuance->birthdayProfile,
            action: $action,
            source: 'birthday_reward_reconciliation',
            isUncertain: false,
            payload: $payload
        );
    }
}
