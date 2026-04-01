<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\Tenant;

class CandleCashOrderEventService
{
    public function __construct(
        protected CandleCashTaskService $taskService,
        protected CandleCashReferralService $referralService,
        protected MarketingStorefrontEventLogger $eventLogger,
        protected CandleCashTaskEligibilityService $eligibilityService
    ) {
    }

    /**
     * @param array<string,mixed> $identityContext
     */
    public function handle(Order $order, array $identityContext = []): void
    {
        $tenantId = is_numeric($identityContext['tenant_id'] ?? null) && (int) ($identityContext['tenant_id'] ?? 0) > 0
            ? (int) $identityContext['tenant_id']
            : (is_numeric($order->tenant_id ?? null) && (int) $order->tenant_id > 0 ? (int) $order->tenant_id : null);

        if ($tenantId === null && Tenant::query()->exists()) {
            $this->eventLogger->log('candle_cash_order_event_skipped_missing_tenant', [
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

            return;
        }

        $profile = $this->profileForOrder($order, $tenantId);

        if ($profile) {
            $this->awardSecondOrderTask($profile, $order);
            $this->awardCandleClubJoinTask($profile, $order);
        }

        $referralCode = trim((string) ($identityContext['referral_code'] ?? ''));
        if ($referralCode !== '') {
            $this->referralService->qualifyFromOrder($order, $profile, [
                'referral_code' => $referralCode,
                'tenant_id' => $tenantId,
                'attribution_meta' => (array) ($identityContext['attribution_meta'] ?? []),
            ]);
        }
    }

    protected function profileForOrder(Order $order, ?int $tenantId): ?MarketingProfile
    {
        $shopifySourceId = (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown') . ':' . $order->shopify_order_id;

        $link = MarketingProfileLink::query()
            ->with('marketingProfile')
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
            ->first();

        $profile = $link?->marketingProfile;
        if (! $profile) {
            return null;
        }

        if ($tenantId !== null && (int) ($profile->tenant_id ?? 0) !== $tenantId) {
            return null;
        }

        return $profile;
    }

    protected function awardSecondOrderTask(MarketingProfile $profile, Order $order): void
    {
        $linkedOrderCount = $profile->links()
            ->where('source_type', 'order')
            ->count();

        if ($linkedOrderCount !== 2) {
            return;
        }

        $result = $this->taskService->awardSystemTask($profile, 'second-order', [
            'source_type' => 'system_event',
            'source_id' => 'second-order:profile:' . $profile->id . ':order:' . $order->id,
            'source_event_key' => 'second-order:profile:' . $profile->id . ':order:' . $order->id,
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'shopify_order_id' => $order->shopify_order_id,
            ],
        ]);

        if (! ($result['ok'] ?? false)) {
            $this->eventLogger->log('candle_cash_second_order_not_awarded', [
                'status' => 'pending',
                'issue_type' => (string) ($result['error'] ?? 'not_awarded'),
                'profile' => $profile,
                'source_surface' => 'ingestion',
                'endpoint' => 'shopify_order_ingest',
                'source_type' => 'order',
                'source_id' => (string) $order->id,
                'meta' => [
                    'linked_order_count' => $linkedOrderCount,
                    'order_number' => $order->order_number,
                ],
                'resolution_status' => 'open',
            ]);
        }
    }

    protected function awardCandleClubJoinTask(MarketingProfile $profile, Order $order): void
    {
        if ($this->eligibilityService->membershipStatusForProfile($profile) !== 'active_candle_club_member') {
            return;
        }

        $this->taskService->awardSystemTask($profile, 'candle-club-join', [
            'source_type' => 'system_event',
            'source_id' => 'candle-club-join:profile:' . $profile->id . ':order:' . $order->id,
            'source_event_key' => 'candle-club-join:profile:' . $profile->id,
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'shopify_order_id' => $order->shopify_order_id,
            ],
        ]);
    }
}
