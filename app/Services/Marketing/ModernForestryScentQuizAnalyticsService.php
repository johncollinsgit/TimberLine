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

        return [
            'tenant_id' => $tenantId,
            'recent_window_days' => max(1, $recentDays),
            'recent_window_started_at' => $recentStart->toIso8601String(),
            'as_of' => $resolvedAsOf->toIso8601String(),
            'quiz' => [
                'recent_takers' => (int) $recentResults->distinct()->count('marketing_profile_id'),
                'total_takers' => (int) $quizResults->distinct()->count('marketing_profile_id'),
                'recent_completions' => (int) $recentResults->count(),
                'total_completions' => (int) $quizResults->count(),
                'top_personalities' => $personalityCounts,
            ],
            'wishlist' => [
                'recent_additions' => (int) $recentWishlistEvents->count(),
                'total_additions' => (int) $wishlistEvents->count(),
            ],
            'orders' => [
                'recent_purchases' => (int) $recentOrders->count(),
                'total_purchases' => (int) $orders->count(),
                'recent_revenue' => round((float) ((clone $recentOrders)->sum('total_price') ?: 0), 2),
                'total_revenue' => round((float) ((clone $orders)->sum('total_price') ?: 0), 2),
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
}
