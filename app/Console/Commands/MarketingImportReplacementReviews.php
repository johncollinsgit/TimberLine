<?php

namespace App\Console\Commands;

use App\Services\Marketing\ProductReviewService;
use Illuminate\Console\Command;

class MarketingImportReplacementReviews extends Command
{
    protected $signature = 'marketing:import-replacement-reviews';

    protected $description = 'Import the requested Growave replacement product reviews into the internal review system.';

    public function handle(ProductReviewService $productReviewService): int
    {
        $summary = [
            'processed' => 0,
            'created' => 0,
            'existing' => 0,
            'matched' => 0,
            'unmatched' => 0,
        ];

        $results = [];

        foreach ($this->reviews() as $payload) {
            $result = $productReviewService->importReview($payload);
            $review = $result['review'];
            $match = (array) ($result['match'] ?? []);
            $created = (bool) ($result['created'] ?? false);

            $summary['processed']++;
            $summary[$created ? 'created' : 'existing']++;
            $summary[(bool) ($match['matched'] ?? false) ? 'matched' : 'unmatched']++;

            $results[] = [
                'reviewer_name' => (string) ($payload['reviewer_name'] ?? 'Unknown reviewer'),
                'review_id' => (int) $review->id,
                'action' => $created ? 'created' : 'existing',
                'matched' => (bool) ($match['matched'] ?? false) ? 'yes' : 'no',
                'profile_id' => $match['profile']?->id ?: 'n/a',
                'method' => (string) ($match['method'] ?? 'unmatched'),
                'evidence' => $this->evidenceSummary((array) ($match['evidence'] ?? [])),
            ];
        }

        foreach (['processed', 'created', 'existing', 'matched', 'unmatched'] as $key) {
            $this->line($key . '=' . $summary[$key]);
        }

        foreach ($results as $result) {
            $this->line(implode('|', [
                'reviewer=' . $result['reviewer_name'],
                'review_id=' . $result['review_id'],
                'action=' . $result['action'],
                'matched=' . $result['matched'],
                'profile_id=' . $result['profile_id'],
                'method=' . $result['method'],
                'evidence=' . $result['evidence'],
            ]));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function reviews(): array
    {
        return [
            [
                'reviewer_name' => 'Linda Pittman',
                'product_title' => 'Sale Candles',
                'rating' => 5,
                'submitted_at' => '2026-03-15',
                'title' => 'Amazing candles',
                'body' => 'These candles are amazing. So many great scents to choose from. Love every one I’ve had.',
                'store_key' => 'retail',
                'submission_source' => 'growave_import',
            ],
            [
                'reviewer_name' => 'Erin Viera',
                'product_title' => 'Candle Club 6 month prepaid Gift option (Shipping included)',
                'rating' => 5,
                'submitted_at' => '2026-03-12',
                'title' => 'Candle Club subscription!',
                'body' => 'I am super excited to be joining this club! There are so many candles I\'m anxious to try out and this is a great way to do that!',
                'store_key' => 'retail',
                'submission_source' => 'growave_import',
            ],
        ];
    }

    /**
     * @param array<int,string> $evidence
     */
    protected function evidenceSummary(array $evidence): string
    {
        $evidence = array_values(array_filter(array_map('trim', $evidence)));

        return $evidence === [] ? 'none' : implode('; ', $evidence);
    }
}
