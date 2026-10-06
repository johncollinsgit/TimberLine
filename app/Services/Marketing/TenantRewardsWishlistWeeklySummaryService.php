<?php

namespace App\Services\Marketing;

use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfileWishlistItem;
use App\Models\MarketingStorefrontEvent;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class TenantRewardsWishlistWeeklySummaryService
{
    public function __construct(
        protected CandleCashService $candleCashService
    ) {}

    /**
     * Build a tenant-scoped operational summary without exposing customer PII.
     *
     * @return array<string,mixed>
     */
    public function reportSnapshot(Tenant|int $tenant, ?CarbonInterface $asOf = null, int $days = 7): array
    {
        $tenantModel = $tenant instanceof Tenant
            ? $tenant
            : Tenant::query()->findOrFail((int) $tenant);
        $resolvedDays = max(1, min(90, $days));
        $windowEnd = $asOf ? Carbon::instance($asOf) : now();
        $windowStart = $windowEnd->copy()->subDays($resolvedDays);

        $events = MarketingStorefrontEvent::query()
            ->where('tenant_id', $tenantModel->id)
            ->whereBetween('occurred_at', [$windowStart, $windowEnd]);

        $rewardEvents = (clone $events)->where(function (Builder $query): void {
            $query->where('event_type', 'like', 'reward_%')
                ->orWhere('event_type', 'widget_candle_cash_status_lookup');
        });

        $rewardViews = $this->eventCount($rewardEvents, 'reward_view');
        $statusLookups = $this->eventCount($rewardEvents, 'widget_candle_cash_status_lookup');
        $successfulStatusLookups = $this->eventCount($rewardEvents, 'widget_candle_cash_status_lookup', 'ok');
        $applyAttempts = $this->eventCount($rewardEvents, 'reward_apply_click');
        $applySuccesses = $this->eventCount($rewardEvents, 'reward_apply_success');
        $applyFailures = $this->eventCount($rewardEvents, 'reward_apply_failure');
        $fallbackRenders = $this->eventCount($rewardEvents, 'reward_status_fallback_rendered');
        $birthdayApplySuccesses = $this->eventCountForRewardKind($rewardEvents, 'reward_apply_success', 'birthday');
        $candleCashApplySuccesses = $this->eventCountForRewardKind($rewardEvents, 'reward_apply_success', 'candle_cash');

        $issuedRedemptions = $this->redemptionsForTenant((int) $tenantModel->id)
            ->whereBetween('issued_at', [$windowStart, $windowEnd]);
        $redeemedRedemptions = $this->redemptionsForTenant((int) $tenantModel->id)
            ->whereBetween('redeemed_at', [$windowStart, $windowEnd]);
        $activeRedemptions = $this->redemptionsForTenant((int) $tenantModel->id)
            ->where('status', 'issued')
            ->where(function (Builder $query) use ($windowEnd): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $windowEnd);
            });

        $earnedTransactions = $this->transactionsForTenant((int) $tenantModel->id)
            ->where('candle_cash_delta', '>', 0)
            ->whereBetween('created_at', [$windowStart, $windowEnd]);

        $wishlistEvents = (clone $events)
            ->whereIn('event_type', [
                'widget_wishlist_status_lookup',
                'widget_wishlist_add',
                'widget_wishlist_remove',
            ]);
        $wishlistAdds = $this->eventCount($wishlistEvents, 'widget_wishlist_add', 'ok');
        $wishlistRemovals = $this->eventCount($wishlistEvents, 'widget_wishlist_remove', 'ok');
        $wishlistErrors = (int) (clone $wishlistEvents)->where('status', 'error')->count();
        $wishlistLookups = $this->eventCount($wishlistEvents, 'widget_wishlist_status_lookup');

        $activeWishlistItems = MarketingProfileWishlistItem::query()
            ->where('tenant_id', $tenantModel->id)
            ->where('status', MarketingProfileWishlistItem::STATUS_ACTIVE);
        $activeWishlistOwners = (clone $activeWishlistItems)
            ->get(['marketing_profile_id', 'guest_token'])
            ->map(fn (MarketingProfileWishlistItem $item): string => $item->marketing_profile_id
                ? 'profile:'.$item->marketing_profile_id
                : 'guest:'.trim((string) $item->guest_token))
            ->filter(fn (string $owner): bool => ! str_ends_with($owner, ':'))
            ->unique()
            ->count();
        $topSavedProducts = (clone $activeWishlistItems)
            ->get(['product_id', 'product_title', 'product_handle'])
            ->groupBy(fn (MarketingProfileWishlistItem $item): string => (string) $item->product_id)
            ->map(function ($rows): array {
                /** @var MarketingProfileWishlistItem $first */
                $first = $rows->first();

                return [
                    'product_id' => (string) $first->product_id,
                    'title' => trim((string) $first->product_title) ?: trim((string) $first->product_handle) ?: 'Product '.$first->product_id,
                    'saves' => $rows->count(),
                ];
            })
            ->sortByDesc('saves')
            ->take(5)
            ->values()
            ->all();

        $rewardIssueCount = $applyFailures + $fallbackRenders;
        $totalIssueCount = $rewardIssueCount + $wishlistErrors;
        $activityCount = $successfulStatusLookups + $applySuccesses + $wishlistAdds + $wishlistRemovals;
        $issueBreakdown = (clone $events)
            ->where('status', 'error')
            ->where(function (Builder $query): void {
                $query->where('event_type', 'like', 'reward_%')
                    ->orWhereIn('event_type', ['widget_wishlist_add', 'widget_wishlist_remove']);
            })
            ->selectRaw('event_type, issue_type, COUNT(*) as aggregate')
            ->groupBy('event_type', 'issue_type')
            ->orderByDesc('aggregate')
            ->limit(10)
            ->get()
            ->map(fn (MarketingStorefrontEvent $event): array => [
                'event_type' => (string) $event->event_type,
                'issue_type' => trim((string) $event->issue_type) ?: 'unspecified',
                'count' => (int) ($event->aggregate ?? 0),
            ])
            ->all();
        [$healthStatus, $healthLabel, $healthSummary] = $this->healthSummary($totalIssueCount, $activityCount);

        return [
            'tenant' => [
                'id' => (int) $tenantModel->id,
                'name' => (string) $tenantModel->name,
                'slug' => (string) $tenantModel->slug,
            ],
            'window' => [
                'days' => $resolvedDays,
                'started_at' => $windowStart->toIso8601String(),
                'ended_at' => $windowEnd->toIso8601String(),
            ],
            'health' => [
                'status' => $healthStatus,
                'label' => $healthLabel,
                'summary' => $healthSummary,
                'issue_count' => $totalIssueCount,
                'activity_count' => $activityCount,
                'issues' => $issueBreakdown,
            ],
            'candle_cash' => [
                'status_lookups' => $statusLookups,
                'successful_status_lookups' => $successfulStatusLookups,
                'reward_views' => $rewardViews,
                'apply_attempts' => $applyAttempts,
                'apply_successes' => $applySuccesses,
                'apply_failures' => $applyFailures,
                'apply_success_rate' => $this->percentage($applySuccesses, $applyAttempts),
                'birthday_apply_successes' => $birthdayApplySuccesses,
                'candle_cash_apply_successes' => $candleCashApplySuccesses,
                'fallback_renders' => $fallbackRenders,
                'unique_applying_customers' => (int) (clone $rewardEvents)
                    ->where('event_type', 'reward_apply_success')
                    ->whereNotNull('marketing_profile_id')
                    ->distinct()
                    ->count('marketing_profile_id'),
                'earned' => [
                    'count' => (int) (clone $earnedTransactions)->count(),
                    'amount' => $this->amountFromStoredPoints((float) (clone $earnedTransactions)->sum('candle_cash_delta')),
                ],
                'issued' => [
                    'count' => (int) (clone $issuedRedemptions)->count(),
                    'amount' => $this->amountFromStoredPoints((float) (clone $issuedRedemptions)->sum('candle_cash_spent')),
                ],
                'redeemed' => [
                    'count' => (int) (clone $redeemedRedemptions)->count(),
                    'amount' => $this->amountFromStoredPoints((float) (clone $redeemedRedemptions)->sum('candle_cash_spent')),
                ],
                'currently_active_codes' => [
                    'count' => (int) (clone $activeRedemptions)->count(),
                    'amount' => $this->amountFromStoredPoints((float) (clone $activeRedemptions)->sum('candle_cash_spent')),
                ],
                'last_storefront_activity_at' => (clone $rewardEvents)->max('occurred_at'),
            ],
            'wishlist' => [
                'status_lookups' => $wishlistLookups,
                'adds' => $wishlistAdds,
                'removals' => $wishlistRemovals,
                'errors' => $wishlistErrors,
                'unique_adding_customers' => (int) (clone $wishlistEvents)
                    ->where('event_type', 'widget_wishlist_add')
                    ->where('status', 'ok')
                    ->whereNotNull('marketing_profile_id')
                    ->distinct()
                    ->count('marketing_profile_id'),
                'current_active_items' => (int) (clone $activeWishlistItems)->count(),
                'current_savers' => $activeWishlistOwners,
                'top_saved_products' => $topSavedProducts,
                'last_storefront_activity_at' => (clone $wishlistEvents)->max('occurred_at'),
            ],
        ];
    }

    protected function transactionsForTenant(int $tenantId): Builder
    {
        return CandleCashTransaction::query()->whereHas('profile', function (Builder $query) use ($tenantId): void {
            $query->where('marketing_profiles.tenant_id', $tenantId);
        });
    }

    protected function redemptionsForTenant(int $tenantId): Builder
    {
        return CandleCashRedemption::query()->whereHas('profile', function (Builder $query) use ($tenantId): void {
            $query->where('marketing_profiles.tenant_id', $tenantId);
        });
    }

    protected function eventCount(Builder $events, string $eventType, ?string $status = null): int
    {
        return (int) (clone $events)
            ->where('event_type', $eventType)
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->count();
    }

    protected function eventCountForRewardKind(Builder $events, string $eventType, string $rewardKind): int
    {
        return (int) (clone $events)
            ->where('event_type', $eventType)
            ->where('meta->reward_kind', $rewardKind)
            ->count();
    }

    protected function amountFromStoredPoints(float $points): float
    {
        return round($this->candleCashService->amountFromPoints($points), 2);
    }

    protected function percentage(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0.0;
    }

    /**
     * @return array{string,string,string}
     */
    protected function healthSummary(int $issueCount, int $activityCount): array
    {
        if ($issueCount > 0) {
            return [
                'needs_attention',
                'Needs attention',
                'Customer activity was recorded, but one or more rewards or wishlist interactions failed.',
            ];
        }

        if ($activityCount > 0) {
            return [
                'working',
                'Working',
                'Successful storefront activity was recorded during this reporting window.',
            ];
        }

        return [
            'no_activity',
            'No activity observed',
            'No customer activity was recorded in this window. This is neutral and does not by itself indicate an outage.',
        ];
    }
}
