<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileScentQuizResult;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ModernForestryScentQuizAnalyticsService
{
    public const ATTRIBUTION_SOURCE_LABEL = 'scent_quiz';

    public const ATTRIBUTION_TEMPLATE_KEY = 'modern_forestry_scent_quiz';

    public const ATTRIBUTION_MODULE_TYPE = 'scent_quiz';

    /**
     * @return array<string,mixed>
     */
    public function reportSnapshot(int $tenantId = 1, ?CarbonInterface $asOf = null, int $recentDays = 7): array
    {
        $resolvedAsOf = $asOf ? Carbon::instance($asOf) : now();
        $recentStart = $resolvedAsOf->copy()->subDays(max(1, $recentDays));

        return $this->reportWindow($tenantId, $recentStart, $resolvedAsOf, max(1, $recentDays));
    }

    /**
     * @return array<string,mixed>
     */
    public function reportWindow(
        int $tenantId = 1,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?int $windowDays = null
    ): array {
        $resolvedAsOf = $to ? Carbon::instance($to) : now();
        $recentStart = $from ? Carbon::instance($from) : $resolvedAsOf->copy()->subDays(max(1, $windowDays ?? 7));
        if ($recentStart->greaterThan($resolvedAsOf)) {
            [$recentStart, $resolvedAsOf] = [$resolvedAsOf->copy(), $recentStart->copy()];
        }
        $resolvedWindowDays = $windowDays ?? max(1, $recentStart->diffInDays($resolvedAsOf) ?: 1);

        $quizResults = MarketingProfileScentQuizResult::query()
            ->where('tenant_id', $tenantId);

        $recentResults = (clone $quizResults)
            ->where('completed_at', '>=', $recentStart);

        $wishlistEvents = MarketingStorefrontEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('source_type', 'shopify_storefront_funnel')
            ->where('event_type', 'wishlist_added')
            ->where('meta->mf_source_label', self::ATTRIBUTION_SOURCE_LABEL);

        $recentWishlistEvents = (clone $wishlistEvents)
            ->where('occurred_at', '>=', $recentStart);

        $cartEvents = MarketingStorefrontEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('source_type', 'shopify_storefront_funnel')
            ->where('event_type', 'add_to_cart')
            ->where('meta->mf_source_label', self::ATTRIBUTION_SOURCE_LABEL);

        $recentCartEvents = (clone $cartEvents)
            ->where('occurred_at', '>=', $recentStart);

        $orders = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('attribution_meta->email_source_label', self::ATTRIBUTION_SOURCE_LABEL);

        $recentOrders = (clone $orders)
            ->where(function ($query) use ($recentStart): void {
                $query->where('ordered_at', '>=', $recentStart)
                    ->orWhere(function ($nested) use ($recentStart): void {
                        $nested->whereNull('ordered_at')
                            ->where('created_at', '>=', $recentStart);
                    });
            });

        $personalityCounts = MarketingProfileScentQuizResult::query()
            ->where('tenant_id', $tenantId)
            ->selectRaw('personality_title, COUNT(*) as aggregate')
            ->groupBy('personality_title')
            ->orderByDesc('aggregate')
            ->limit(5)
            ->get()
            ->map(fn (MarketingProfileScentQuizResult $result): array => [
                'title' => trim((string) $result->personality_title) !== ''
                    ? (string) $result->personality_title
                    : 'Unlabeled profile',
                'count' => (int) ($result->aggregate ?? 0),
            ])
            ->all();

        $recentTakers = (int) $recentResults->distinct()->count('marketing_profile_id');
        $totalTakers = (int) $quizResults->distinct()->count('marketing_profile_id');
        $recentWishlistAdds = (int) $recentWishlistEvents->count();
        $totalWishlistAdds = (int) $wishlistEvents->count();
        $recentCartAdds = (int) $recentCartEvents->count();
        $totalCartAdds = (int) $cartEvents->count();
        $recentPurchases = (int) $recentOrders->count();
        $totalPurchases = (int) $orders->count();

        return [
            'tenant_id' => $tenantId,
            'recent_window_days' => max(1, $resolvedWindowDays),
            'recent_window_started_at' => $recentStart->toIso8601String(),
            'as_of' => $resolvedAsOf->toIso8601String(),
            'quiz' => [
                'recent_takers' => $recentTakers,
                'total_takers' => $totalTakers,
                'recent_completions' => (int) $recentResults->count(),
                'total_completions' => (int) $quizResults->count(),
                'top_personalities' => $personalityCounts,
            ],
            'wishlist' => [
                'recent_additions' => $recentWishlistAdds,
                'total_additions' => $totalWishlistAdds,
            ],
            'cart' => [
                'recent_additions' => $recentCartAdds,
                'total_additions' => $totalCartAdds,
            ],
            'orders' => [
                'recent_purchases' => $recentPurchases,
                'total_purchases' => $totalPurchases,
                'recent_revenue' => round((float) ((clone $recentOrders)->sum('total_price') ?: 0), 2),
                'total_revenue' => round((float) ((clone $orders)->sum('total_price') ?: 0), 2),
            ],
            'conversion' => [
                'quiz_to_wishlist_rate' => $this->ratio($recentWishlistAdds, $recentTakers),
                'quiz_to_cart_rate' => $this->ratio($recentCartAdds, $recentTakers),
                'quiz_to_purchase_rate' => $this->ratio($recentPurchases, $recentTakers),
                'wishlist_to_purchase_rate' => $this->ratio($recentPurchases, $recentWishlistAdds),
                'cart_to_purchase_rate' => $this->ratio($recentPurchases, $recentCartAdds),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function attributionPayload(MarketingProfile $profile, ?string $linkLabel = null): array
    {
        return [
            'signature' => implode(':', [
                self::ATTRIBUTION_SOURCE_LABEL,
                self::ATTRIBUTION_TEMPLATE_KEY,
                (string) $profile->id,
                (string) now()->timestamp,
            ]),
            'landing_url' => null,
            'landing_path' => '/apps/forestry/account',
            'referrer' => null,
            'expires_at' => now()->addDays(7)->getTimestampMs(),
            'captured_at' => now()->getTimestampMs(),
            'mf_source_label' => self::ATTRIBUTION_SOURCE_LABEL,
            'mf_template_key' => self::ATTRIBUTION_TEMPLATE_KEY,
            'mf_profile_id' => (string) $profile->id,
            'mf_module_type' => self::ATTRIBUTION_MODULE_TYPE,
            'mf_link_label' => trim((string) $linkLabel) !== '' ? trim((string) $linkLabel) : 'Scent quiz',
        ];
    }

    protected function ratio(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 1);
    }
}
