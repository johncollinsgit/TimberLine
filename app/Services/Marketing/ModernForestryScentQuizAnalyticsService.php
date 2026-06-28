<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileScentQuizResult;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ModernForestryScentQuizAnalyticsService
{
    public const ATTRIBUTION_SOURCE_LABEL = 'scent_quiz';

    public const ATTRIBUTION_TEMPLATE_KEY = 'modern_forestry_scent_quiz';

    public const ATTRIBUTION_MODULE_TYPE = 'scent_quiz';

    /**
     * @var array<string,string>
     */
    protected const AXIS_LABELS = [
        'floral' => 'Floral',
        'woodsy' => 'Woodsy',
        'smoky' => 'Smoky',
        'sweet' => 'Sweet',
        'masculine' => 'Masculine',
        'earthy' => 'Earthy',
        'clean' => 'Clean',
        'citrus' => 'Citrus',
    ];

    /**
     * @var array<string,array{campaign_title:string,campaign_body:string,discount_hint:string}>
     */
    protected const DISCOUNT_PLAYBOOK = [
        'floral' => [
            'campaign_title' => 'Floral profile feature',
            'campaign_body' => 'Lead with bright bouquet notes, romantic copy, and fresh seasonal drops.',
            'discount_hint' => 'Bundle floral-forward candles with spring or garden-story creative.',
        ],
        'woodsy' => [
            'campaign_title' => 'Cabin woods offer',
            'campaign_body' => 'Highlight forest, cedar, pine, and grounded outdoorsy scent stories.',
            'discount_hint' => 'Promote woodsy bundles and campfire-style best sellers.',
        ],
        'smoky' => [
            'campaign_title' => 'Campfire depth campaign',
            'campaign_body' => 'Use moody, fireside, evening, and low-light scent language.',
            'discount_hint' => 'Aim discounts at richer smoky candles and seasonal evening sets.',
        ],
        'sweet' => [
            'campaign_title' => 'Comfort gourmand push',
            'campaign_body' => 'Frame the offer around cozy treats, bakery warmth, and comfort gifting.',
            'discount_hint' => 'Use bakery, vanilla, dessert, and kitchen-story scents in the discount set.',
        ],
        'masculine' => [
            'campaign_title' => 'Bold signature scent push',
            'campaign_body' => 'Position the offer as confident, tailored, dark, and giftable.',
            'discount_hint' => 'Pair cologne-inspired candles with gift-ready messaging.',
        ],
        'earthy' => [
            'campaign_title' => 'Grounded natural ritual',
            'campaign_body' => 'Speak to calm rituals, nature textures, and slow-living scents.',
            'discount_hint' => 'Build offers around earthy, resin, moss, and herbal scent families.',
        ],
        'clean' => [
            'campaign_title' => 'Fresh home reset',
            'campaign_body' => 'Sell the feeling of an airy, polished, easy-to-love everyday candle.',
            'discount_hint' => 'Offer fresh-linen, spa, and clean-finish fragrances as a reset set.',
        ],
        'citrus' => [
            'campaign_title' => 'Bright energy drop',
            'campaign_body' => 'Lean into morning energy, sunlight, sparkle, and crisp optimism.',
            'discount_hint' => 'Feature citrus-led candles in launch banners and starter bundles.',
        ],
    ];

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

        /** @var Collection<int,MarketingProfileScentQuizResult> $profiledResults */
        $profiledResults = MarketingProfileScentQuizResult::query()
            ->where('tenant_id', $tenantId)
            ->with(['profile:id,tenant_id,first_name,last_name,email,phone'])
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'marketing_profile_id',
                'tenant_id',
                'axis_scores',
                'dominant_traits',
                'headline',
                'personality_title',
                'completed_at',
            ]);

        $dominantTraitCounts = $profiledResults
            ->flatMap(function (MarketingProfileScentQuizResult $result): array {
                return collect((array) ($result->dominant_traits ?? []))
                    ->map(fn (mixed $trait): string => Str::lower(trim((string) $trait)))
                    ->filter()
                    ->values()
                    ->all();
            })
            ->countBy()
            ->sortDesc();

        $recentTakers = (int) $recentResults->distinct()->count('marketing_profile_id');
        $totalTakers = (int) $quizResults->distinct()->count('marketing_profile_id');
        $recentWishlistAdds = (int) $recentWishlistEvents->count();
        $totalWishlistAdds = (int) $wishlistEvents->count();
        $recentCartAdds = (int) $recentCartEvents->count();
        $totalCartAdds = (int) $cartEvents->count();
        $recentPurchases = (int) $recentOrders->count();
        $totalPurchases = (int) $orders->count();

        $topTraitAudienceRows = $dominantTraitCounts
            ->map(function (int $count, string $trait) use ($totalTakers): array {
                $playbook = self::DISCOUNT_PLAYBOOK[$trait] ?? [
                    'campaign_title' => Str::headline($trait).' scent campaign',
                    'campaign_body' => 'Target customers who consistently score into this scent family.',
                    'discount_hint' => 'Build a scent-specific offer around this audience.',
                ];

                return [
                    'trait' => $trait,
                    'label' => $this->axisLabel($trait),
                    'count' => $count,
                    'share_of_profiles' => $this->ratio($count, max(1, $totalTakers)),
                    'campaign_title' => $playbook['campaign_title'],
                    'campaign_body' => $playbook['campaign_body'],
                    'discount_hint' => $playbook['discount_hint'],
                ];
            })
            ->values()
            ->take(6)
            ->all();

        $axisAverages = collect(self::AXIS_LABELS)
            ->map(function (string $label, string $axisId) use ($profiledResults): array {
                $scores = $profiledResults
                    ->map(function (MarketingProfileScentQuizResult $result) use ($axisId): ?float {
                        $score = data_get($result->axis_scores, $axisId);

                        return is_numeric($score) ? (float) $score : null;
                    })
                    ->filter(fn (?float $score): bool => $score !== null)
                    ->values();

                $average = $scores->isNotEmpty()
                    ? round(((float) $scores->sum()) / $scores->count(), 1)
                    : 0.0;

                return [
                    'axis' => $axisId,
                    'label' => $label,
                    'average_score' => $average,
                ];
            })
            ->values()
            ->all();

        $recentProfiles = $profiledResults
            ->take(8)
            ->map(function (MarketingProfileScentQuizResult $result): array {
                $profile = $result->profile;
                $displayName = $profile instanceof MarketingProfile
                    ? trim((string) $profile->first_name.' '.(string) $profile->last_name)
                    : '';

                return [
                    'marketing_profile_id' => (int) ($result->marketing_profile_id ?? 0),
                    'display_name' => $displayName !== ''
                        ? $displayName
                        : (trim((string) ($profile?->email ?? '')) !== ''
                            ? (string) $profile->email
                            : 'Customer #'.(int) ($result->marketing_profile_id ?? 0)),
                    'email' => $profile instanceof MarketingProfile ? trim((string) $profile->email) : null,
                    'phone' => $profile instanceof MarketingProfile ? trim((string) $profile->phone) : null,
                    'headline' => trim((string) ($result->headline ?? '')) !== ''
                        ? (string) $result->headline
                        : 'Scent profile saved',
                    'personality_title' => trim((string) ($result->personality_title ?? '')) !== ''
                        ? (string) $result->personality_title
                        : 'Scent personality',
                    'dominant_traits' => collect((array) ($result->dominant_traits ?? []))
                        ->map(fn (mixed $trait): string => $this->axisLabel((string) $trait))
                        ->values()
                        ->all(),
                    'completed_at' => optional($result->completed_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();

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
            'audiences' => [
                'top_traits' => $topTraitAudienceRows,
                'axis_averages' => $axisAverages,
                'recent_profiles' => $recentProfiles,
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

    protected function axisLabel(string $axisId): string
    {
        $key = Str::lower(trim($axisId));

        return self::AXIS_LABELS[$key] ?? Str::headline($axisId);
    }
}
