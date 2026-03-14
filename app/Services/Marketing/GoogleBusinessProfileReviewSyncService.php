<?php

namespace App\Services\Marketing;

use App\Models\CandleCashTask;
use App\Models\GoogleBusinessProfileConnection;
use App\Models\GoogleBusinessProfileReview;
use App\Models\GoogleBusinessProfileSyncRun;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Models\MarketingStorefrontEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class GoogleBusinessProfileReviewSyncService
{
    public function __construct(
        protected GoogleBusinessProfileConnectionService $connectionService,
        protected GoogleBusinessProfileApiService $apiService,
        protected CandleCashVerificationService $verificationService,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function sync(?User $triggeredBy = null): array
    {
        $connection = $this->connectionService->current();
        if (! $connection) {
            throw new GoogleBusinessProfileException('not_connected', 'Google Business Profile is not connected yet.');
        }
        if (! $connection->linked_account_id || ! $connection->linked_location_id) {
            throw new GoogleBusinessProfileException('location_not_selected', 'Pick a Google Business location before syncing reviews.');
        }

        $run = GoogleBusinessProfileSyncRun::query()->create([
            'google_business_profile_connection_id' => $connection->id,
            'triggered_by_user_id' => $triggeredBy?->id,
            'trigger_type' => 'manual',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [
                'linked_location_id' => $connection->linked_location_id,
                'linked_location_title' => $connection->linked_location_title,
            ],
        ]);

        $counts = [
            'fetched' => 0,
            'new' => 0,
            'updated' => 0,
            'matched' => 0,
            'awarded' => 0,
            'duplicates' => 0,
            'unmatched' => 0,
        ];

        try {
            $pageToken = null;
            $pageCount = 0;

            do {
                $page = $this->apiService->fetchReviews($connection, $connection->linked_account_id, $connection->linked_location_id, $pageToken);
                $reviews = array_values(array_filter((array) ($page['reviews'] ?? []), 'is_array'));
                $counts['fetched'] += count($reviews);

                foreach ($reviews as $payload) {
                    $result = $this->upsertAndProcessReview($connection, $payload);
                    $counts['new'] += $result['new'] ? 1 : 0;
                    $counts['updated'] += $result['updated'] ? 1 : 0;
                    $counts['matched'] += $result['matched'] ? 1 : 0;
                    $counts['awarded'] += $result['awarded'] ? 1 : 0;
                    $counts['duplicates'] += $result['duplicate'] ? 1 : 0;
                    $counts['unmatched'] += $result['unmatched'] ? 1 : 0;
                }

                $pageToken = $page['nextPageToken'] ?? null;
                $pageCount++;
            } while ($pageToken !== null && $pageCount < 5);

            $run->forceFill([
                'status' => 'completed',
                'fetched_reviews_count' => $counts['fetched'],
                'new_reviews_count' => $counts['new'],
                'updated_reviews_count' => $counts['updated'],
                'matched_reviews_count' => $counts['matched'],
                'awarded_reviews_count' => $counts['awarded'],
                'duplicate_reviews_count' => $counts['duplicates'],
                'unmatched_reviews_count' => $counts['unmatched'],
                'finished_at' => now(),
            ])->save();

            $this->connectionService->markSynced($connection);

            return [
                'ok' => true,
                'run' => $run->fresh(),
                'counts' => $counts,
            ];
        } catch (GoogleBusinessProfileException $exception) {
            $run->forceFill([
                'status' => 'failed',
                'fetched_reviews_count' => $counts['fetched'],
                'new_reviews_count' => $counts['new'],
                'updated_reviews_count' => $counts['updated'],
                'matched_reviews_count' => $counts['matched'],
                'awarded_reviews_count' => $counts['awarded'],
                'duplicate_reviews_count' => $counts['duplicates'],
                'unmatched_reviews_count' => $counts['unmatched'],
                'error_code' => $exception->errorCode,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            $this->connectionService->markError($connection, $exception);

            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{new:bool,updated:bool,matched:bool,awarded:bool,duplicate:bool,unmatched:bool,review:GoogleBusinessProfileReview}
     */
    protected function upsertAndProcessReview(GoogleBusinessProfileConnection $connection, array $payload): array
    {
        $normalized = $this->normalizeReview($connection, $payload);
        $review = GoogleBusinessProfileReview::query()->firstOrNew([
            'google_business_profile_connection_id' => $connection->id,
            'external_review_id' => $normalized['external_review_id'],
        ]);

        $isNew = ! $review->exists;
        $alreadyMatched = (int) ($review->marketing_profile_id ?? 0) > 0;
        $alreadyAwarded = $review->awarded_at !== null || (int) ($review->candle_cash_task_event_id ?? 0) > 0;
        $wasUpdated = ! $isNew && (string) optional($review->updated_time)->toIso8601String() !== optional($normalized['updated_time'])->toIso8601String();

        $review->fill($normalized);
        $review->save();

        $matched = false;
        $awarded = false;
        $duplicate = false;
        $unmatched = false;

        if ($alreadyAwarded) {
            return [
                'new' => $isNew,
                'updated' => $wasUpdated,
                'matched' => true,
                'awarded' => true,
                'duplicate' => true,
                'unmatched' => false,
                'review' => $review->fresh(),
            ];
        }

        if ($alreadyMatched) {
            return [
                'new' => $isNew,
                'updated' => $wasUpdated,
                'matched' => true,
                'awarded' => false,
                'duplicate' => true,
                'unmatched' => false,
                'review' => $review->fresh(),
            ];
        }

        $candidate = $this->matchCandidateEvent($review);
        if ($candidate) {
            $matched = true;
            $profile = $candidate->profile;
            $review->forceFill([
                'marketing_profile_id' => $profile?->id,
                'marketing_storefront_event_id' => $candidate->id,
                'matched_at' => now(),
                'sync_status' => 'matched',
            ])->save();

            if ($profile) {
                $award = $this->verificationService->awardGoogleReview($profile, (string) $review->external_review_id, [
                    'review_name' => $review->review_name,
                    'reviewer_name' => $review->reviewer_name,
                    'rating' => $review->star_rating,
                    'location_id' => $review->location_id,
                ]);

                $review->forceFill([
                    'candle_cash_task_event_id' => (int) optional($award['event'])->id ?: $review->candle_cash_task_event_id,
                    'candle_cash_task_completion_id' => (int) optional($award['completion'])->id ?: $review->candle_cash_task_completion_id,
                    'awarded_at' => (bool) ($award['ok'] ?? false) ? now() : $review->awarded_at,
                    'sync_status' => (bool) ($award['ok'] ?? false) ? 'awarded' : 'matched',
                ])->save();

                $candidate->forceFill([
                    'resolution_status' => 'resolved',
                    'resolved_at' => now(),
                    'resolution_notes' => 'Matched to Google review ' . $review->external_review_id,
                    'meta' => array_merge((array) $candidate->meta, [
                        'google_review_id' => $review->external_review_id,
                        'google_review_rating' => $review->star_rating,
                    ]),
                ])->save();

                $awarded = (bool) ($award['ok'] ?? false);
                $duplicate = (string) ($award['error'] ?? '') === 'duplicate_event' || ((int) optional($award['event'])->duplicate_hits > 0);
            }
        } else {
            $review->forceFill([
                'sync_status' => 'unmatched',
            ])->save();
            $unmatched = true;
        }

        return [
            'new' => $isNew,
            'updated' => $wasUpdated,
            'matched' => $matched,
            'awarded' => $awarded,
            'duplicate' => $duplicate,
            'unmatched' => $unmatched,
            'review' => $review->fresh(),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    protected function normalizeReview(GoogleBusinessProfileConnection $connection, array $payload): array
    {
        $reviewName = trim((string) ($payload['name'] ?? ''));
        $reviewId = trim((string) ($payload['reviewId'] ?? ''));
        if ($reviewId === '' && $reviewName !== '') {
            $reviewId = Str::afterLast($reviewName, '/');
        }
        if ($reviewId === '') {
            $reviewId = sha1(json_encode($payload));
        }

        return [
            'review_name' => $reviewName !== '' ? $reviewName : null,
            'account_id' => $connection->linked_account_id,
            'account_name' => $connection->linked_account_name,
            'location_id' => $connection->linked_location_id,
            'location_name' => $connection->linked_location_name,
            'star_rating' => $this->normalizeStarRating($payload['starRating'] ?? null),
            'reviewer_name' => trim((string) Arr::get($payload, 'reviewer.displayName', '')) ?: null,
            'reviewer_profile_photo_url' => trim((string) Arr::get($payload, 'reviewer.profilePhotoUrl', '')) ?: null,
            'reviewer_is_anonymous' => (bool) Arr::get($payload, 'reviewer.isAnonymous', false),
            'comment' => trim((string) ($payload['comment'] ?? '')) ?: null,
            'review_reply_comment' => trim((string) Arr::get($payload, 'reviewReply.comment', '')) ?: null,
            'created_time' => $this->parseTime($payload['createTime'] ?? null),
            'updated_time' => $this->parseTime($payload['updateTime'] ?? null),
            'sync_status' => 'synced',
            'metadata' => [
                'review_reply_update_time' => $payload['reviewReply']['updateTime'] ?? null,
            ],
            'raw_payload' => $payload,
            'external_review_id' => $reviewId,
        ];
    }

    protected function normalizeStarRating(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $rating = (int) $value;
            return $rating > 0 ? $rating : null;
        }

        return match (Str::upper(trim((string) $value))) {
            'ONE', 'ONE_STAR' => 1,
            'TWO', 'TWO_STAR' => 2,
            'THREE', 'THREE_STAR' => 3,
            'FOUR', 'FOUR_STAR' => 4,
            'FIVE', 'FIVE_STAR' => 5,
            default => null,
        };
    }

    protected function parseTime(mixed $value): mixed
    {
        try {
            return filled($value) ? Carbon::parse((string) $value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function matchCandidateEvent(GoogleBusinessProfileReview $review): ?MarketingStorefrontEvent
    {
        $reviewerName = $this->normalizeName((string) ($review->reviewer_name ?? ''));
        if ($reviewerName === '') {
            return null;
        }

        $task = CandleCashTask::query()->where('handle', 'google-review')->first();
        $windowHours = max(1, (int) ($task?->verification_window_hours ?? 336));
        $integrationConfig = (array) optional(MarketingSetting::query()->where('key', 'candle_cash_integration_config')->first())->value;
        $strategy = trim((string) ($integrationConfig['google_review_matching_strategy'] ?? 'recent_click_name_match'));

        $candidates = MarketingStorefrontEvent::query()
            ->with('profile:id,first_name,last_name,email')
            ->where('event_type', 'google_business_review_start')
            ->where('resolution_status', 'open')
            ->whereNotNull('marketing_profile_id')
            ->where('occurred_at', '>=', now()->subHours($windowHours))
            ->latest('occurred_at')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $scored = $candidates->map(function (MarketingStorefrontEvent $candidate) use ($reviewerName): array {
            $expected = $this->normalizeName((string) data_get($candidate->meta, 'expected_reviewer_name', ''));
            $profile = $candidate->profile;
            $fallback = $this->normalizeName(trim((string) (($profile->first_name ?? '') . ' ' . ($profile->last_name ?? ''))));
            $score = 0;
            foreach (array_filter([$expected, $fallback]) as $name) {
                if ($name === $reviewerName) {
                    $score = max($score, 100);
                } elseif ($this->sameFirstAndLastInitial($name, $reviewerName)) {
                    $score = max($score, 80);
                }
            }

            return ['candidate' => $candidate, 'score' => $score];
        })->sortByDesc('score')->values();

        $best = $scored->first();
        if (! $best || (int) $best['score'] < 80) {
            return null;
        }

        $topScore = (int) $best['score'];
        $sameTop = $scored->filter(fn (array $row): bool => (int) $row['score'] === $topScore)->values();
        if ($sameTop->count() > 1 && $strategy !== 'recent_click_name_match_latest') {
            return null;
        }

        return $best['candidate'];
    }

    protected function normalizeName(string $value): string
    {
        $value = Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->value();

        return preg_replace('/\s+/', ' ', $value) ?: '';
    }

    protected function sameFirstAndLastInitial(string $left, string $right): bool
    {
        $leftParts = array_values(array_filter(explode(' ', $left)));
        $rightParts = array_values(array_filter(explode(' ', $right)));
        if ($leftParts === [] || $rightParts === []) {
            return false;
        }

        $leftFirst = $leftParts[0] ?? '';
        $rightFirst = $rightParts[0] ?? '';
        $leftLast = $leftParts[count($leftParts) - 1] ?? '';
        $rightLast = $rightParts[count($rightParts) - 1] ?? '';

        return $leftFirst === $rightFirst
            && $leftLast !== ''
            && $rightLast !== ''
            && Str::substr($leftLast, 0, 1) === Str::substr($rightLast, 0, 1);
    }
}
