<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Marketing\BirthdayRewardRedemptionReconciliationService;
use App\Services\Marketing\CandleCashRedemptionReconciliationService;
use App\Services\Marketing\MarketingConversionAttributionService;
use App\Services\Marketing\MarketingProfileSyncService;
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
        public array $identityContext = []
    ) {
    }

    public function handle(
        MarketingProfileSyncService $syncService,
        MarketingConversionAttributionService $conversionAttributionService,
        CandleCashRedemptionReconciliationService $reconciliationService,
        BirthdayRewardRedemptionReconciliationService $birthdayReconciliationService
    ): void
    {
        $order = Order::query()->find($this->orderId);
        if (!$order) {
            return;
        }

        $syncService->syncOrder($order, [
            'identity_context' => $this->identityContext,
        ]);

        $rewardSummary = $reconciliationService->reconcileShopifyOrder($order, [
            'codes' => (array) ($this->identityContext['applied_reward_codes'] ?? []),
        ]);

        $birthdayRewardSummary = $birthdayReconciliationService->reconcileShopifyOrder($order, [
            'codes' => (array) ($this->identityContext['coupon_signals'] ?? []),
            'order_total' => $this->identityContext['order_total'] ?? null,
        ]);

        $conversionAttributionService->attributeForOrder($order, [
            'coupon_signals' => (array) ($this->identityContext['coupon_signals'] ?? []),
            'reward_reconcile_summary' => $rewardSummary,
            'birthday_reward_reconcile_summary' => $birthdayRewardSummary,
        ]);
    }
}
