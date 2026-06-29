<?php

namespace App\Services\Marketing;

use App\Services\Mobile\ModernForestryMobileProductCatalogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ModernForestryScentQuizRecommendationService
{
    /**
     * @var array<string,array<int,string>>
     */
    protected const AXIS_KEYWORDS = [
        'floral' => ['floral', 'flower', 'petal', 'rose', 'lavender', 'bloom', 'jasmine', 'garden'],
        'woodsy' => ['wood', 'woodsy', 'cedar', 'pine', 'fir', 'forest', 'birch', 'oak', 'moss'],
        'smoky' => ['smoke', 'smoky', 'ember', 'fireside', 'hearth', 'charcoal', 'tobacco', 'clove'],
        'sweet' => ['sweet', 'vanilla', 'cream', 'dessert', 'bakery', 'sugar', 'cozy'],
        'masculine' => ['leather', 'tobacco', 'bourbon', 'cologne', 'masculine', 'amber', 'oak'],
        'earthy' => ['earth', 'earthy', 'moss', 'herbal', 'clay', 'stone', 'grounded', 'rain'],
        'clean' => ['clean', 'linen', 'cotton', 'fresh', 'spa', 'airy', 'crisp', 'bright'],
        'citrus' => ['citrus', 'orange', 'lemon', 'grapefruit', 'peel', 'sunlit', 'zest'],
    ];

    public function __construct(
        protected ModernForestryMobileProductCatalogService $catalog
    ) {
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<int,array<string,mixed>>
     */
    public function topMatches(array $result, int $limit = 4): array
    {
        $axes = collect((array) ($result['axes'] ?? []))
            ->filter(fn (mixed $axis): bool => is_array($axis))
            ->map(fn (array $axis): array => [
                'id' => Str::lower(trim((string) ($axis['id'] ?? ''))),
                'label' => trim((string) ($axis['label'] ?? '')),
                'score' => max(0, min(100, (int) ($axis['score'] ?? 0))),
            ])
            ->filter(fn (array $axis): bool => $axis['id'] !== '')
            ->sortByDesc('score')
            ->values();

        if ($axes->isEmpty()) {
            return [];
        }

        $products = collect($this->catalog->products(40))
            ->filter(fn (mixed $product): bool => is_array($product) && trim((string) ($product['handle'] ?? '')) !== '')
            ->map(function (array $product) use ($axes): array {
                $blob = Str::lower(implode(' ', array_filter([
                    (string) ($product['title'] ?? ''),
                    (string) ($product['handle'] ?? ''),
                    implode(' ', array_map('strval', (array) ($product['tags'] ?? []))),
                ])));

                $score = 0;
                $matchedAxes = [];

                foreach ($axes as $axis) {
                    $axisScore = (int) ($axis['score'] ?? 0);
                    if ($axisScore <= 0) {
                        continue;
                    }

                    $matches = 0;
                    foreach (self::AXIS_KEYWORDS[$axis['id']] ?? [] as $keyword) {
                        if ($keyword !== '' && str_contains($blob, $keyword)) {
                            $matches++;
                        }
                    }

                    if ($matches > 0) {
                        $score += ($axisScore * 2) + ($matches * 12);
                        $matchedAxes[] = $axis;
                    } else {
                        $score += (int) round($axisScore * 0.14);
                    }
                }

                if ($score <= 0) {
                    $score = 1;
                }

                return [
                    ...$product,
                    '_recommendation_score' => $score,
                    '_matched_axes' => array_values($matchedAxes),
                ];
            })
            ->sortByDesc('_recommendation_score')
            ->values();

        return $products
            ->take(max(1, $limit))
            ->map(function (array $product): array {
                $matchedAxes = collect((array) ($product['_matched_axes'] ?? []))
                    ->take(2)
                    ->map(fn (array $axis): string => trim((string) ($axis['label'] ?? '')))
                    ->filter()
                    ->values();

                $reason = match ($matchedAxes->count()) {
                    0 => 'A strong overall fit for the way this scent personality leans.',
                    1 => 'Especially strong for your '.$matchedAxes->first().' streak.',
                    default => 'Pulls strongly toward your '.$matchedAxes->implode(' + ').' profile.',
                };

                return [
                    'handle' => (string) ($product['handle'] ?? ''),
                    'title' => (string) ($product['title'] ?? 'Modern Forestry candle'),
                    'imageUrl' => $product['imageUrl'] ?? null,
                    'price' => $product['price'] ?? null,
                    'variantId' => $product['variantId'] ?? null,
                    'url' => $product['url'] ?? null,
                    'reason' => $reason,
                    'dominantAxes' => $matchedAxes->all(),
                ];
            })
            ->values()
            ->all();
    }
}
