<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingSegment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ModernForestryScentAudienceActivationService
{
    /**
     * @var array<string,array<string,string>>
     */
    protected const AUDIENCES = [
        'floral' => [
            'label' => 'Floral',
            'segment_name' => 'Floral Scent Quiz Audience',
            'segment_description' => 'Customers whose Modern Forestry scent quiz leans floral and bouquet-forward.',
            'campaign_name' => 'Floral audience discount draft',
            'campaign_description' => 'Draft offer for customers who score floral in the Modern Forestry scent quiz.',
            'campaign_subject' => 'A floral candle pick with your name on it',
            'campaign_body' => 'Your scent profile leans floral, so this draft is set up for a soft, fresh, bouquet-forward candle offer.',
            'coupon_code' => 'FLORAL10',
        ],
        'woodsy' => [
            'label' => 'Woodsy',
            'segment_name' => 'Woodsy Scent Quiz Audience',
            'segment_description' => 'Customers whose Modern Forestry scent quiz points strongly toward woodsy notes and grounded outdoorsy scents.',
            'campaign_name' => 'Woodsy audience discount draft',
            'campaign_description' => 'Draft offer for customers who score woodsy in the Modern Forestry scent quiz.',
            'campaign_subject' => 'A woodsy candle pick for your next burn',
            'campaign_body' => 'Your scent profile leans woodsy, so this draft is set up for cedar, pine, campfire, and grounded evergreen offers.',
            'coupon_code' => 'WOODSY10',
        ],
        'smoky' => [
            'label' => 'Smoky',
            'segment_name' => 'Smoky Scent Quiz Audience',
            'segment_description' => 'Customers whose Modern Forestry scent quiz favors richer smoky, fireside, and evening scents.',
            'campaign_name' => 'Smoky audience discount draft',
            'campaign_description' => 'Draft offer for customers who score smoky in the Modern Forestry scent quiz.',
            'campaign_subject' => 'A deeper smoky scent worth trying next',
            'campaign_body' => 'Your scent profile leans smoky, so this draft is set up for campfire, resin, and evening-burn offers.',
            'coupon_code' => 'SMOKY10',
        ],
        'sweet' => [
            'label' => 'Sweet',
            'segment_name' => 'Sweet Scent Quiz Audience',
            'segment_description' => 'Customers whose Modern Forestry scent quiz leans sweet, cozy, and gourmand.',
            'campaign_name' => 'Sweet audience discount draft',
            'campaign_description' => 'Draft offer for customers who score sweet in the Modern Forestry scent quiz.',
            'campaign_subject' => 'A cozy sweet candle pick for you',
            'campaign_body' => 'Your scent profile leans sweet, so this draft is set up for vanilla, bakery, and comfort-forward offers.',
            'coupon_code' => 'SWEET10',
        ],
        'masculine' => [
            'label' => 'Masculine',
            'segment_name' => 'Masculine Scent Quiz Audience',
            'segment_description' => 'Customers whose Modern Forestry scent quiz leans bold, tailored, and cologne-inspired.',
            'campaign_name' => 'Masculine audience discount draft',
            'campaign_description' => 'Draft offer for customers who score masculine in the Modern Forestry scent quiz.',
            'campaign_subject' => 'A bold candle profile worth a second look',
            'campaign_body' => 'Your scent profile leans masculine, so this draft is set up for darker, giftable, signature-scent offers.',
            'coupon_code' => 'BOLD10',
        ],
        'earthy' => [
            'label' => 'Earthy',
            'segment_name' => 'Earthy Scent Quiz Audience',
            'segment_description' => 'Customers whose Modern Forestry scent quiz leans earthy, calm, and naturally grounded.',
            'campaign_name' => 'Earthy audience discount draft',
            'campaign_description' => 'Draft offer for customers who score earthy in the Modern Forestry scent quiz.',
            'campaign_subject' => 'An earthy candle profile for slower moments',
            'campaign_body' => 'Your scent profile leans earthy, so this draft is set up for moss, herbal, and grounding ritual offers.',
            'coupon_code' => 'EARTHY10',
        ],
        'clean' => [
            'label' => 'Clean',
            'segment_name' => 'Clean Scent Quiz Audience',
            'segment_description' => 'Customers whose Modern Forestry scent quiz leans airy, polished, and fresh.',
            'campaign_name' => 'Clean audience discount draft',
            'campaign_description' => 'Draft offer for customers who score clean in the Modern Forestry scent quiz.',
            'campaign_subject' => 'A fresh clean-burn candle for your space',
            'campaign_body' => 'Your scent profile leans clean, so this draft is set up for spa, linen, and home-reset offers.',
            'coupon_code' => 'CLEAN10',
        ],
        'citrus' => [
            'label' => 'Citrus',
            'segment_name' => 'Citrus Scent Quiz Audience',
            'segment_description' => 'Customers whose Modern Forestry scent quiz leans bright, energetic, and citrus-led.',
            'campaign_name' => 'Citrus audience discount draft',
            'campaign_description' => 'Draft offer for customers who score citrus in the Modern Forestry scent quiz.',
            'campaign_subject' => 'A bright citrus candle to wake up the room',
            'campaign_body' => 'Your scent profile leans citrus, so this draft is set up for sunny, crisp, upbeat scent offers.',
            'coupon_code' => 'CITRUS10',
        ],
    ];

    /**
     * @return array<int,array<string,string>>
     */
    public function audienceDefinitions(): array
    {
        return collect(self::AUDIENCES)
            ->map(function (array $definition, string $trait): array {
                return [
                    'trait' => $trait,
                    ...$definition,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int,MarketingSegment>
     */
    public function scentSegmentsForTenant(int $tenantId): Collection
    {
        return MarketingSegment::query()
            ->where('tenant_id', $tenantId)
            ->where('slug', 'like', 'scent-quiz-%-audience')
            ->orderBy('name')
            ->get();
    }

    public function createSegment(int $tenantId, string $trait, ?int $userId = null): MarketingSegment
    {
        $definition = $this->definition($trait);
        $slug = 'scent-quiz-'.$trait.'-audience';

        /** @var MarketingSegment $segment */
        $segment = MarketingSegment::query()->firstOrNew([
            'tenant_id' => $tenantId,
            'slug' => $slug,
        ]);

        $segment->fill([
            'name' => $definition['segment_name'],
            'description' => $definition['segment_description'],
            'status' => 'active',
            'channel_scope' => 'any',
            'rules_json' => [
                'logic' => 'and',
                'conditions' => [
                    [
                        'field' => 'scent_dominant_traits',
                        'operator' => 'contains',
                        'value' => $trait,
                    ],
                ],
                'groups' => [],
            ],
            'is_system' => false,
            'updated_by' => $userId,
        ]);

        if (! $segment->exists) {
            $segment->tenant_id = $tenantId;
            $segment->created_by = $userId;
        }

        $segment->save();

        return $segment;
    }

    public function createCampaignDraft(int $tenantId, ?string $storeKey, string $trait, ?int $userId = null): MarketingCampaign
    {
        $definition = $this->definition($trait);
        $segment = $this->createSegment($tenantId, $trait, $userId);
        $slug = 'scent-audience-'.$trait.'-discount-draft';

        /** @var MarketingCampaign $campaign */
        $campaign = MarketingCampaign::query()->firstOrNew([
            'tenant_id' => $tenantId,
            'slug' => $slug,
            'source_label' => 'modern_forestry_scent_audience_draft',
        ]);

        $campaign->fill([
            'store_key' => $storeKey ?: 'retail',
            'name' => $definition['campaign_name'],
            'description' => $definition['campaign_description'],
            'status' => 'draft',
            'channel' => 'email',
            'segment_id' => $segment->id,
            'objective' => 'wishlist_triggered_offer',
            'attribution_window_days' => 7,
            'coupon_code' => $definition['coupon_code'],
            'message_subject' => $definition['campaign_subject'],
            'message_body' => $definition['campaign_body'],
            'target_snapshot' => [
                'audience_source' => 'modern_forestry_scent_quiz',
                'trait' => $trait,
                'trait_label' => $definition['label'],
                'segment_id' => $segment->id,
                'segment_name' => $segment->name,
            ],
            'updated_by' => $userId,
        ]);

        if (! $campaign->exists) {
            $campaign->created_by = $userId;
        }

        $campaign->save();

        return $campaign;
    }

    /**
     * @return array<string,string>
     */
    protected function definition(string $trait): array
    {
        $normalized = Str::lower(trim($trait));

        if (! array_key_exists($normalized, self::AUDIENCES)) {
            throw new InvalidArgumentException('Unsupported scent audience trait.');
        }

        return self::AUDIENCES[$normalized];
    }
}
