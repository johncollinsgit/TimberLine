<?php

namespace App\Services\Marketing;

use App\Models\CandleCashRedemption;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\SquareOrder;
use App\Services\Tenancy\TenantResolver;

class CandleCashRedemptionReconciliationService
{
    public function __construct(
        protected MarketingStorefrontEventLogger $eventLogger,
        protected MarketingAttributionSourceMetaBuilder $attributionSourceMetaBuilder,
        protected TenantResolver $tenantResolver,
        protected CandleCashService $candleCashService
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,int>
     */
    public function reconcileShopifyOrder(Order $order, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $tenantId = $this->tenantIdForShopifyOrder($order, $options);
        $codes = $this->normalizeCodes((array) ($options['codes'] ?? []));
        if ($codes === []) {
            $codes = $this->extractCodesFromText(implode(' ', array_filter([
                (string) ($order->order_number ?? ''),
                (string) ($order->shopify_name ?? ''),
                (string) ($order->internal_notes ?? ''),
            ])));
        }

        $profileIds = $this->profileIdsForOrder($order, $tenantId);

        return $this->reconcileCodes(
            codes: $codes,
            externalOrderSource: 'order',
            externalOrderId: (string) $order->id,
            profileIds: $profileIds,
            redeemedChannel: 'shopify_ingest',
            context: [
                'tenant_id' => $tenantId,
                'order_id' => (int) $order->id,
                'order_number' => (string) ($order->order_number ?? ''),
                'shopify_order_id' => $order->shopify_order_id ? (string) $order->shopify_order_id : null,
                'order_discount_total' => is_numeric($order->discount_total) ? round((float) $order->discount_total, 2) : null,
                'coupon_signals' => $this->couponSignalsForShopifyOrder($order, $options),
                'attribution_meta' => (array) ($options['attribution_meta'] ?? []),
            ],
            tenantId: $tenantId,
            dryRun: $dryRun
        );
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,int>
     */
    public function reconcileSquareOrder(SquareOrder $order, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $tenantId = $this->tenantIdForSquareOrder($order, $options);
        $codes = $this->normalizeCodes((array) ($options['codes'] ?? []));
        if ($codes === []) {
            $raw = is_array($order->raw_payload) ? json_encode($order->raw_payload) : '';
            $codes = $this->extractCodesFromText(implode(' ', array_filter([
                (string) ($order->source_name ?? ''),
                (string) ($raw ?: ''),
                implode(' ', array_filter((array) $order->raw_tax_names)),
            ])));
        }

        $profileIds = $this->profileIdsForSquareOrder($order, $tenantId);

        return $this->reconcileCodes(
            codes: $codes,
            externalOrderSource: 'square_order',
            externalOrderId: (string) $order->square_order_id,
            profileIds: $profileIds,
            redeemedChannel: 'square_sync',
            context: [
                'tenant_id' => $tenantId,
                'square_order_id' => (string) $order->square_order_id,
                'square_customer_id' => $order->square_customer_id ? (string) $order->square_customer_id : null,
            ],
            tenantId: $tenantId,
            dryRun: $dryRun
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    public function markRedeemedManually(CandleCashRedemption $redemption, array $context = []): CandleCashRedemption
    {
        if ($redemption->status === 'redeemed') {
            return $redemption;
        }

        $platform = strtolower(trim((string) ($context['platform'] ?? $redemption->platform ?? 'square')));
        $externalOrderSource = trim((string) ($context['external_order_source'] ?? 'square_manual'));
        $externalOrderId = trim((string) ($context['external_order_id'] ?? ''));
        $notes = trim((string) ($context['notes'] ?? '')) ?: null;
        $actorId = isset($context['redeemed_by']) ? (int) $context['redeemed_by'] : null;

        $redemption->forceFill([
            'status' => 'redeemed',
            'platform' => $platform !== '' ? $platform : null,
            'redeemed_channel' => 'manual_reconcile',
            'external_order_source' => $externalOrderSource !== '' ? $externalOrderSource : null,
            'external_order_id' => $externalOrderId !== '' ? $externalOrderId : null,
            'redeemed_at' => $redemption->redeemed_at ?: now(),
            'reconciliation_notes' => $notes ?: $redemption->reconciliation_notes,
            'redeemed_by' => $actorId ?: $redemption->redeemed_by,
            'redemption_context' => $this->mergeContext((array) $redemption->redemption_context, [
                'manual_reconciled_at' => now()->toIso8601String(),
                'manual_reconciled_by' => $actorId,
            ]),
        ])->save();

        $this->eventLogger->log('redemption_manual_reconciled', [
            'status' => 'resolved',
            'issue_type' => null,
            'source_surface' => 'admin',
            'endpoint' => 'marketing/customers/candle-cash/mark-redeemed',
            'marketing_profile_id' => (int) $redemption->marketing_profile_id,
            'candle_cash_redemption_id' => (int) $redemption->id,
            'source_type' => (string) ($redemption->external_order_source ?? ''),
            'source_id' => (string) ($redemption->external_order_id ?? ''),
            'meta' => [
                'platform' => $platform,
                'notes' => $notes,
            ],
            'resolution_status' => 'resolved',
        ]);

        return $redemption->fresh();
    }

    /**
     * @param array<string,mixed> $context
     */
    public function cancelRedemption(CandleCashRedemption $redemption, array $context = []): CandleCashRedemption
    {
        $notes = trim((string) ($context['notes'] ?? '')) ?: null;
        $actorId = isset($context['actor_id']) ? (int) $context['actor_id'] : null;

        $redemption->forceFill([
            'status' => 'canceled',
            'canceled_at' => $redemption->canceled_at ?: now(),
            'reconciliation_notes' => $notes ?: $redemption->reconciliation_notes,
            'redemption_context' => $this->mergeContext((array) $redemption->redemption_context, [
                'canceled_at' => now()->toIso8601String(),
                'canceled_by' => $actorId,
            ]),
        ])->save();

        $this->eventLogger->log('redemption_canceled', [
            'status' => 'resolved',
            'issue_type' => 'canceled',
            'source_surface' => 'admin',
            'endpoint' => 'marketing/customers/candle-cash/cancel',
            'marketing_profile_id' => (int) $redemption->marketing_profile_id,
            'candle_cash_redemption_id' => (int) $redemption->id,
            'source_type' => (string) ($redemption->external_order_source ?? ''),
            'source_id' => (string) ($redemption->external_order_id ?? ''),
            'meta' => [
                'notes' => $notes,
            ],
            'resolution_status' => 'resolved',
        ]);

        return $redemption->fresh();
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
        string $redeemedChannel = 'ingest',
        array $context = [],
        ?int $tenantId = null,
        bool $dryRun = false
    ): array {
        $summary = [
            'processed' => 0,
            'matched' => 0,
            'reconciled' => 0,
            'already_reconciled' => 0,
            'rejected' => 0,
            'not_found' => 0,
            'tenant_context_missing' => 0,
        ];

        $codes = $this->normalizeCodes($codes);
        if ($codes === []) {
            return $summary;
        }

        if (! is_numeric($tenantId) || (int) $tenantId <= 0) {
            $summary['tenant_context_missing'] = count($codes);
            $this->eventLogger->log('redemption_reconcile_skipped_missing_tenant', [
                'status' => 'error',
                'issue_type' => 'tenant_context_missing',
                'source_surface' => 'ingestion',
                'endpoint' => $externalOrderSource,
                'source_type' => $externalOrderSource,
                'source_id' => $externalOrderId,
                'dedupe_key' => sha1('tenant_missing|' . $externalOrderSource . '|' . $externalOrderId . '|' . implode(',', $codes)),
                'meta' => [
                    'codes' => $codes,
                    'external_order_source' => $externalOrderSource,
                    'external_order_id' => $externalOrderId,
                ],
            ]);

            return $summary;
        }
        $tenantId = (int) $tenantId;

        foreach ($codes as $code) {
            $summary['processed']++;

            $redemption = CandleCashRedemption::query()
                ->join('marketing_profiles as mp', 'mp.id', '=', 'candle_cash_redemptions.marketing_profile_id')
                ->where('candle_cash_redemptions.redemption_code', $code)
                ->where('mp.tenant_id', $tenantId)
                ->select('candle_cash_redemptions.*')
                ->first();

            if (! $redemption) {
                $summary['not_found']++;
                $this->eventLogger->log('redemption_reconcile_not_found', [
                    'status' => 'error',
                    'issue_type' => 'redemption_code_not_found',
                    'source_surface' => 'ingestion',
                    'endpoint' => $externalOrderSource,
                    'source_type' => $externalOrderSource,
                    'source_id' => $externalOrderId,
                    'dedupe_key' => sha1('nf|' . $code . '|' . $externalOrderSource . '|' . $externalOrderId . '|tenant:' . $tenantId),
                    'meta' => [
                        'code' => $code,
                        'tenant_id' => $tenantId,
                        'external_order_source' => $externalOrderSource,
                        'external_order_id' => $externalOrderId,
                    ],
                ]);
                continue;
            }

            $summary['matched']++;

            if ($redemption->expires_at && $redemption->expires_at->isPast() && $redemption->status !== 'redeemed') {
                if (! $dryRun) {
                    $redemption->forceFill([
                        'status' => 'expired',
                    ])->save();
                }
            }

            if ($profileIds !== [] && ! in_array((int) $redemption->marketing_profile_id, $profileIds, true)) {
                $summary['rejected']++;
                $this->logRejected(
                    redemption: $redemption,
                    reason: 'profile_mismatch',
                    code: $code,
                    externalOrderSource: $externalOrderSource,
                    externalOrderId: $externalOrderId,
                    tenantId: $tenantId
                );
                continue;
            }

            if ($redemption->status === 'redeemed') {
                if (
                    (string) ($redemption->external_order_source ?? '') === $externalOrderSource
                    && (string) ($redemption->external_order_id ?? '') === $externalOrderId
                ) {
                    $summary['already_reconciled']++;
                    $this->eventLogger->log('redemption_reconcile_already_reconciled', [
                        'status' => 'ok',
                        'issue_type' => null,
                        'source_surface' => 'ingestion',
                        'endpoint' => $externalOrderSource,
                        'marketing_profile_id' => (int) $redemption->marketing_profile_id,
                        'candle_cash_redemption_id' => (int) $redemption->id,
                        'source_type' => $externalOrderSource,
                        'source_id' => $externalOrderId,
                        'dedupe_key' => sha1('ar|' . $redemption->id . '|' . $externalOrderSource . '|' . $externalOrderId),
                        'meta' => [
                            'code' => $code,
                        ],
                        'resolution_status' => 'resolved',
                    ]);
                } else {
                    $summary['rejected']++;
                    $this->logRejected(
                        redemption: $redemption,
                        reason: 'code_already_used',
                        code: $code,
                        externalOrderSource: $externalOrderSource,
                        externalOrderId: $externalOrderId,
                        tenantId: $tenantId
                    );
                }
                continue;
            }

            if ($redemption->status === 'canceled') {
                if (! $this->canFinalizeCanceledRedemption($redemption, $code, $externalOrderSource, $context)) {
                    $summary['rejected']++;
                    $this->logRejected(
                        redemption: $redemption,
                        reason: 'code_canceled',
                        code: $code,
                        externalOrderSource: $externalOrderSource,
                        externalOrderId: $externalOrderId,
                        tenantId: $tenantId
                    );
                    continue;
                }

                if (! $dryRun) {
                    $result = $this->candleCashService->finalizeRedemptionFromVerifiedOrder(
                        $redemption,
                        $externalOrderSource,
                        $externalOrderId,
                        $redeemedChannel,
                        $this->reconciliationContext($redemption, $context)
                    );

                    if (! (bool) ($result['ok'] ?? false)) {
                        $summary['rejected']++;
                        $this->logRejected(
                            redemption: $redemption,
                            reason: (string) ($result['error'] ?? 'code_canceled'),
                            code: $code,
                            externalOrderSource: $externalOrderSource,
                            externalOrderId: $externalOrderId,
                            tenantId: $tenantId
                        );
                        continue;
                    }

                    if ((bool) ($result['already_redeemed'] ?? false)) {
                        $summary['already_reconciled']++;
                        continue;
                    }
                }

                $summary['reconciled']++;
                $this->eventLogger->log('redemption_reconciled', [
                    'status' => 'ok',
                    'issue_type' => null,
                    'source_surface' => 'ingestion',
                    'endpoint' => $externalOrderSource,
                    'marketing_profile_id' => (int) $redemption->marketing_profile_id,
                    'candle_cash_redemption_id' => (int) $redemption->id,
                    'source_type' => $externalOrderSource,
                    'source_id' => $externalOrderId,
                    'dedupe_key' => sha1('ok|' . $redemption->id . '|' . $externalOrderSource . '|' . $externalOrderId),
                    'meta' => [
                        'code' => $code,
                        'channel' => $redeemedChannel,
                        'recovered_from_canceled' => true,
                    ],
                    'resolution_status' => 'resolved',
                ]);
                continue;
            }

            if ($redemption->status === 'expired') {
                $summary['rejected']++;
                $this->logRejected(
                    redemption: $redemption,
                    reason: 'code_expired',
                    code: $code,
                    externalOrderSource: $externalOrderSource,
                    externalOrderId: $externalOrderId,
                    tenantId: $tenantId
                );
                continue;
            }

            if (! $dryRun) {
                $redemption->forceFill([
                    'status' => 'redeemed',
                    'platform' => $this->platformFromSource($externalOrderSource),
                    'redeemed_channel' => $redeemedChannel,
                    'external_order_source' => $externalOrderSource,
                    'external_order_id' => $externalOrderId,
                    'redeemed_at' => $redemption->redeemed_at ?: now(),
                    'redemption_context' => $this->mergeContext((array) $redemption->redemption_context, $this->reconciliationContext($redemption, $context)),
                ])->save();
            }

            $summary['reconciled']++;
            $this->eventLogger->log('redemption_reconciled', [
                'status' => 'ok',
                'issue_type' => null,
                'source_surface' => 'ingestion',
                'endpoint' => $externalOrderSource,
                'marketing_profile_id' => (int) $redemption->marketing_profile_id,
                'candle_cash_redemption_id' => (int) $redemption->id,
                'source_type' => $externalOrderSource,
                'source_id' => $externalOrderId,
                'dedupe_key' => sha1('ok|' . $redemption->id . '|' . $externalOrderSource . '|' . $externalOrderId),
                'meta' => [
                    'code' => $code,
                    'channel' => $redeemedChannel,
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
    public function normalizeCodes(array $codes): array
    {
        return collect($codes)
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    public function extractCodesFromText(string $value): array
    {
        if ($value === '') {
            return [];
        }

        preg_match_all('/\bCC-[A-Z0-9]{6,20}\b/i', strtoupper($value), $matches);

        return $this->normalizeCodes((array) ($matches[0] ?? []));
    }

    /**
     * @return array<int,int>
     */
    protected function profileIdsForOrder(Order $order, ?int $tenantId = null): array
    {
        $shopifySourceId = (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown') . ':' . $order->shopify_order_id;

        $profileIds = MarketingProfileLink::query()
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

        return $this->filterProfileIdsByTenant($profileIds, $tenantId);
    }

    /**
     * @return array<int,int>
     */
    protected function profileIdsForSquareOrder(SquareOrder $order, ?int $tenantId = null): array
    {
        $profileIds = MarketingProfileLink::query()
            ->where(function ($query) use ($order): void {
                $query->where(function ($nested) use ($order): void {
                    $nested->where('source_type', 'square_order')
                        ->where('source_id', (string) $order->square_order_id);
                });

                if ($order->square_customer_id) {
                    $query->orWhere(function ($nested) use ($order): void {
                        $nested->where('source_type', 'square_customer')
                            ->where('source_id', (string) $order->square_customer_id);
                    });
                }
            })
            ->pluck('marketing_profile_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        return $this->filterProfileIdsByTenant($profileIds, $tenantId);
    }

    /**
     * @param array<int,int> $profileIds
     * @return array<int,int>
     */
    protected function filterProfileIdsByTenant(array $profileIds, ?int $tenantId): array
    {
        if ($profileIds === [] || $tenantId === null) {
            return $profileIds;
        }

        return MarketingProfile::query()
            ->forTenantId($tenantId)
            ->whereIn('id', $profileIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $options
     */
    protected function tenantIdForShopifyOrder(Order $order, array $options = []): ?int
    {
        $explicitTenantId = $options['tenant_id'] ?? data_get($options, 'attribution_meta.tenant_id');
        if (is_numeric($explicitTenantId) && (int) $explicitTenantId > 0) {
            return (int) $explicitTenantId;
        }

        $orderTenantId = $order->tenant_id ?? data_get($order->attribution_meta, 'tenant_id');
        if (is_numeric($orderTenantId) && (int) $orderTenantId > 0) {
            return (int) $orderTenantId;
        }

        $storeKey = trim((string) ($order->shopify_store_key ?? $order->shopify_store ?? ''));

        return $storeKey !== ''
            ? $this->tenantResolver->resolveTenantIdForStoreKey($storeKey)
            : null;
    }

    /**
     * @param array<string,mixed> $options
     */
    protected function tenantIdForSquareOrder(SquareOrder $order, array $options = []): ?int
    {
        $explicitTenantId = $options['tenant_id'] ?? data_get($options, 'attribution_meta.tenant_id');
        if (is_numeric($explicitTenantId) && (int) $explicitTenantId > 0) {
            return (int) $explicitTenantId;
        }

        $profileIds = $this->profileIdsForSquareOrder($order, null);
        if ($profileIds === []) {
            return null;
        }

        $tenantIds = MarketingProfile::query()
            ->whereIn('id', $profileIds)
            ->pluck('tenant_id')
            ->filter(fn ($value): bool => is_numeric($value) && (int) $value > 0)
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values();

        if ($tenantIds->count() !== 1) {
            return null;
        }

        return (int) $tenantIds->first();
    }

    protected function platformFromSource(string $externalOrderSource): string
    {
        return str_starts_with($externalOrderSource, 'square') ? 'square' : 'shopify';
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<int,string>
     */
    protected function couponSignalsForShopifyOrder(Order $order, array $options = []): array
    {
        $signals = [
            ...(array) ($options['coupon_signals'] ?? []),
            ...(array) data_get($options, 'attribution_meta.coupon_signals', []),
            ...(array) data_get($order->attribution_meta, 'coupon_signals', []),
            ...(array) ($options['codes'] ?? []),
        ];

        $textSignals = $this->extractCodesFromText(implode(' ', array_filter([
            (string) ($order->internal_notes ?? ''),
            (string) ($order->order_number ?? ''),
            (string) ($order->shopify_name ?? ''),
        ])));

        return $this->normalizeCodes(array_merge($signals, $textSignals));
    }

    protected function canFinalizeCanceledRedemption(
        CandleCashRedemption $redemption,
        string $code,
        string $externalOrderSource,
        array $context = []
    ): bool {
        if (strtolower(trim($externalOrderSource)) !== 'order') {
            return false;
        }

        $code = strtoupper(trim($code));
        if ($code === '' || strtoupper(trim((string) ($redemption->redemption_code ?? ''))) !== $code) {
            return false;
        }

        $discountTotal = data_get($context, 'order_discount_total');
        $discountTotal = is_numeric($discountTotal) ? round((float) $discountTotal, 2) : 0.0;
        if ($discountTotal <= 0) {
            return false;
        }

        $couponSignals = $this->normalizeCodes((array) data_get($context, 'coupon_signals', []));
        if (! in_array($code, $couponSignals, true)) {
            return false;
        }

        $contextReasonCode = strtolower(trim((string) data_get($redemption->redemption_context, 'cancellation_reason_code', '')));
        $notes = strtolower(trim((string) ($redemption->reconciliation_notes ?? '')));
        $restorationApplied = (bool) data_get($redemption->redemption_context, 'restoration_applied', false);

        return $contextReasonCode === 'shopify_discount_sync_failed'
            || $restorationApplied
            || ($notes !== '' && str_contains($notes, 'could not prepare the reward discount'));
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    protected function reconciliationContext(CandleCashRedemption $redemption, array $context): array
    {
        return [
            ...$context,
            'attribution_meta' => $this->attributionSourceMetaBuilder->mergeSourceMeta(
                (array) (($redemption->redemption_context ?? [])['attribution_meta'] ?? []),
                is_array($context['attribution_meta'] ?? null) ? $context['attribution_meta'] : []
            ),
        ];
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $incoming
     * @return array<string,mixed>
     */
    protected function mergeContext(array $existing, array $incoming): array
    {
        return array_filter([
            ...$existing,
            ...$incoming,
            'reconciled_at' => now()->toIso8601String(),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    protected function logRejected(
        CandleCashRedemption $redemption,
        string $reason,
        string $code,
        string $externalOrderSource,
        string $externalOrderId,
        ?int $tenantId = null
    ): void {
        $this->eventLogger->log('redemption_reconcile_rejected', [
            'status' => 'error',
            'issue_type' => $reason,
            'source_surface' => 'ingestion',
            'endpoint' => $externalOrderSource,
            'marketing_profile_id' => (int) $redemption->marketing_profile_id,
            'candle_cash_redemption_id' => (int) $redemption->id,
            'source_type' => $externalOrderSource,
            'source_id' => $externalOrderId,
            'dedupe_key' => sha1('rej|' . $redemption->id . '|' . $externalOrderSource . '|' . $externalOrderId . '|' . $reason),
            'meta' => [
                'code' => $code,
                'reason' => $reason,
                'tenant_id' => $tenantId,
            ],
        ]);
    }
}
