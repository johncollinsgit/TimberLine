<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Marketing\BirthdayRewardRedemptionReconciliationService;
use App\Services\Marketing\CandleCashOrderEventService;
use App\Services\Marketing\CandleCashRedemptionReconciliationService;
use App\Services\Marketing\MarketingConversionAttributionService;
use App\Services\Marketing\MarketingProfileSyncService;
use App\Services\Marketing\MarketingStorefrontEventLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMarketingProfileFromOrder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string,mixed> $identityContext
     */
    public function __construct(
        public int $orderId,
        public array $identityContext = [],
        public ?int $tenantId = null
    ) {
    }

    public function handle(
        MarketingProfileSyncService $syncService,
        MarketingConversionAttributionService $conversionAttributionService,
        CandleCashOrderEventService $candleCashOrderEventService,
        CandleCashRedemptionReconciliationService $reconciliationService,
        BirthdayRewardRedemptionReconciliationService $birthdayReconciliationService
    ): void
    {
        $order = Order::query()->find($this->orderId);
        if (! $order) {
            return;
        }

        $attributionMeta = is_array($this->identityContext['attribution_meta'] ?? null)
            ? $this->identityContext['attribution_meta']
            : (is_array($order->attribution_meta ?? null) ? $order->attribution_meta : []);
        $tenantId = $this->tenantId
            ?: (is_numeric($this->identityContext['tenant_id'] ?? null) ? (int) $this->identityContext['tenant_id'] : null)
            ?: (is_numeric($order->tenant_id ?? null) ? (int) $order->tenant_id : null);

        $syncService->syncOrder($order, [
            'identity_context' => array_replace($this->identityContext, [
                'attribution_meta' => $attributionMeta,
                'tenant_id' => $tenantId,
            ]),
            'tenant_id' => $tenantId,
        ]);

        $tenantAwareIdentityContext = array_replace($this->identityContext, [
            'attribution_meta' => $attributionMeta,
            'tenant_id' => $tenantId,
        ]);

        if ($tenantId !== null && $tenantId > 0) {
            $candleCashOrderEventService->handle($order, $tenantAwareIdentityContext);
        } else {
            app(MarketingStorefrontEventLogger::class)->log('candle_cash_order_event_skipped_missing_tenant', [
                'status' => 'error',
                'issue_type' => 'tenant_context_missing',
                'source_surface' => 'ingestion',
                'endpoint' => 'shopify_order_ingest',
                'source_type' => 'order',
                'source_id' => (string) $order->id,
                'meta' => [
                    'shopify_order_id' => $order->shopify_order_id ? (string) $order->shopify_order_id : null,
                ],
            ]);
        }

        $rewardSummary = $this->missingTenantSummary();
        $birthdayRewardSummary = $this->missingTenantSummary();

        if ($tenantId !== null && $tenantId > 0) {
            $rewardSummary = $reconciliationService->reconcileShopifyOrder($order, [
                'codes' => (array) ($this->identityContext['applied_reward_codes'] ?? []),
                'attribution_meta' => $attributionMeta,
                'tenant_id' => $tenantId,
            ]);

            $birthdayRewardSummary = $birthdayReconciliationService->reconcileShopifyOrder($order, [
                'codes' => (array) ($this->identityContext['coupon_signals'] ?? []),
                'order_total' => $this->identityContext['order_total'] ?? null,
                'attribution_meta' => $attributionMeta,
                'tenant_id' => $tenantId,
            ]);
        }

        $conversionAttributionService->attributeForOrder($order, [
            'coupon_signals' => (array) ($this->identityContext['coupon_signals'] ?? []),
            'reward_reconcile_summary' => $rewardSummary,
            'birthday_reward_reconcile_summary' => $birthdayRewardSummary,
        ]);
    }

    /**
     * @return array<string,int>
     */
    protected function missingTenantSummary(): array
    {
        return [
            'processed' => 0,
            'matched' => 0,
            'reconciled' => 0,
            'already_reconciled' => 0,
            'rejected' => 0,
            'not_found' => 0,
            'redeemed' => 0,
            'already_redeemed' => 0,
            'tenant_context_missing' => 1,
        ];
    }
}
