<?php

namespace App\Services\Marketing;

use App\Mail\ProductReviewSubmittedMail;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingReviewHistory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProductReviewService
{
    public function __construct(
        protected MarketingStorefrontIdentityService $identityService,
        protected CandleCashVerificationService $verificationService,
        protected CandleCashTaskService $taskService,
        protected MarketingStorefrontEventLogger $eventLogger
    ) {
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,store_key?:?string} $product
     * @return array<string,mixed>
     */
    public function storefrontPayload(array $product, ?MarketingProfile $viewer = null): array
    {
        $approvedQuery = $this->approvedReviewsQuery($product);
        $approvedForAverage = clone $approvedQuery;
        $approvedForCount = clone $approvedQuery;
        $reviews = $approvedQuery
            ->with('profile:id,first_name,last_name,email')
            ->orderByDesc('approved_at')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->limit(24)
            ->get();

        $summary = [
            'average_rating' => round((float) ($approvedForAverage->avg('rating') ?? 0), 1),
            'review_count' => (int) $approvedForCount->count(),
        ];

        $viewerReview = $viewer
            ? $this->reviewLookupQuery($product)
                ->where('marketing_profile_id', $viewer->id)
                ->latest('id')
                ->first()
            : null;

        $task = $this->taskService->taskByHandle('product-review');
        $minLength = $this->minimumBodyLength();
        $allowGuest = $this->allowGuestReviews();

        return [
            'product' => [
                'id' => (string) $product['product_id'],
                'handle' => $this->nullableString($product['product_handle'] ?? null),
                'title' => $this->nullableString($product['product_title'] ?? null),
                'url' => $this->canonicalProductUrl($product),
            ],
            'summary' => [
                'average_rating' => $summary['average_rating'],
                'review_count' => $summary['review_count'],
                'rating_label' => $summary['review_count'] > 0
                    ? number_format($summary['average_rating'], 1) . ' out of 5'
                    : 'No reviews yet',
            ],
            'task' => $task ? [
                'enabled' => (bool) $task->enabled,
                'reward_amount' => (string) $task->reward_amount,
                'button_text' => (string) ($task->button_text ?: 'Write a review'),
            ] : null,
            'settings' => [
                'allow_guest' => $allowGuest,
                'moderation_enabled' => $this->moderationEnabled(),
                'minimum_length' => $minLength,
                'notification_email' => $this->notificationEmail(),
            ],
            'viewer' => [
                'profile_id' => $viewer?->id,
                'state' => $viewerReview
                    ? ($viewerReview->status === 'approved' ? 'reviewed' : 'pending')
                    : ($viewer ? 'ready' : ($allowGuest ? 'guest_ready' : 'login_required')),
                'can_submit' => $viewer !== null || $allowGuest,
                'review' => $viewerReview ? $this->reviewPayload($viewerReview) : null,
            ],
            'reviews' => $reviews->map(fn (MarketingReviewHistory $review): array => $this->reviewPayload($review))->all(),
        ];
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,store_key?:?string} $product
     * @param array{rating:int,title:?string,body:string,name:?string,email:?string,request_key?:?string,source_surface?:?string} $payload
     * @return array<string,mixed>
     */
    public function submitReview(?MarketingProfile $viewer, array $product, array $payload): array
    {
        $allowGuest = $this->allowGuestReviews();
        if (! $viewer && ! $allowGuest) {
            return [
                'ok' => false,
                'error' => 'login_required',
                'message' => 'Sign in before leaving a product review.',
            ];
        }

        $reviewerName = $this->reviewerName($viewer, $payload['name'] ?? null);
        $reviewerEmail = $this->reviewerEmail($viewer, $payload['email'] ?? null);
        $normalizedEmail = $reviewerEmail ? Str::lower(trim($reviewerEmail)) : null;
        $title = $this->sanitizeLine($payload['title'] ?? null);
        $body = $this->sanitizeBody($payload['body'] ?? '');
        $rating = max(1, min(5, (int) ($payload['rating'] ?? 0)));
        $requestKey = trim((string) ($payload['request_key'] ?? ''));
        $sourceSurface = trim((string) ($payload['source_surface'] ?? 'product_page')) ?: 'product_page';

        if ($rating < 1 || $rating > 5) {
            return [
                'ok' => false,
                'error' => 'invalid_rating',
                'message' => 'A star rating is required.',
            ];
        }

        if (mb_strlen($body) < $this->minimumBodyLength()) {
            return [
                'ok' => false,
                'error' => 'review_too_short',
                'message' => 'Tell us a little more before submitting your review.',
            ];
        }

        if (! $viewer && $reviewerEmail === null) {
            return [
                'ok' => false,
                'error' => 'email_required',
                'message' => 'An email address is required for guest reviews.',
            ];
        }

        $profile = $viewer;
        if (! $profile && $reviewerEmail) {
            $resolution = $this->identityService->resolve([
                'first_name' => $reviewerName,
                'email' => $reviewerEmail,
            ], [
                'source_type' => 'product_review_submission',
                'source_id' => $this->submissionIdentitySourceId($product, $reviewerEmail),
                'allow_create' => true,
                'source_label' => 'product_review_submission',
                'source_channels' => ['shopify', 'product_review'],
                'source_meta' => [
                    'product_id' => (string) $product['product_id'],
                    'product_handle' => $product['product_handle'] ?? null,
                ],
            ]);

            if ($resolution['status'] === 'review_required') {
                return [
                    'ok' => false,
                    'error' => 'identity_review_required',
                    'message' => 'We could not safely attach that review to a customer profile yet.',
                ];
            }

            $profile = $resolution['profile'];
        }

        $externalReviewId = $this->externalReviewId($product, $profile, $normalizedEmail);
        $storeKey = $this->nullableString($product['store_key'] ?? null) ?: 'retail';
        $reviewLookup = [
            'provider' => 'backstage',
            'integration' => 'native',
            'store_key' => $storeKey,
            'external_review_id' => $externalReviewId,
        ];

        /** @var MarketingReviewHistory|null $existing */
        $existing = MarketingReviewHistory::query()->where($reviewLookup)->first();
        if ($existing && $this->reviewsMatch($existing, $rating, $title, $body)) {
            $this->eventLogger->log('product_review_duplicate_blocked', [
                'status' => 'error',
                'issue_type' => 'duplicate_review',
                'profile' => $profile,
                'request_key' => $requestKey !== '' ? $requestKey : null,
                'source_surface' => $sourceSurface,
                'source_type' => 'shopify_product_review',
                'source_id' => $externalReviewId,
                'meta' => [
                    'product_id' => (string) $product['product_id'],
                    'product_handle' => $product['product_handle'] ?? null,
                ],
                'resolution_status' => 'resolved',
            ]);

            return [
                'ok' => false,
                'error' => 'duplicate_review',
                'message' => 'You already sent this review for that product.',
                'review' => $existing,
            ];
        }

        $moderationEnabled = $this->moderationEnabled();
        $status = $moderationEnabled ? 'pending' : 'approved';
        $now = now();

        $review = MarketingReviewHistory::query()->updateOrCreate(
            $reviewLookup,
            [
                'marketing_profile_id' => $profile?->id,
                'marketing_review_summary_id' => null,
                'external_customer_id' => $this->externalCustomerId($profile, $normalizedEmail),
                'rating' => $rating,
                'title' => $title,
                'body' => $body,
                'reviewer_name' => $reviewerName,
                'reviewer_email' => $reviewerEmail,
                'is_published' => ! $moderationEnabled,
                'status' => $status,
                'submission_source' => 'native_storefront',
                'is_verified_buyer' => $profile ? $this->hasOrderLink($profile) : false,
                'product_id' => (string) $product['product_id'],
                'product_handle' => $this->nullableString($product['product_handle'] ?? null),
                'product_url' => $this->canonicalProductUrl($product),
                'product_title' => $this->nullableString($product['product_title'] ?? null),
                'submitted_at' => $existing?->submitted_at ?: $now,
                'reviewed_at' => ! $moderationEnabled ? $now : null,
                'approved_at' => ! $moderationEnabled ? $now : null,
                'rejected_at' => null,
                'moderated_by' => null,
                'moderation_notes' => null,
                'source_synced_at' => $now,
                'raw_payload' => [
                    'request_key' => $requestKey !== '' ? $requestKey : null,
                    'source_surface' => $sourceSurface,
                    'submitted_via' => 'storefront',
                ],
            ]
        );

        $award = null;
        if ($profile) {
            $award = $this->verificationService->awardProductReview($profile, (string) $review->external_review_id, [
                'product_id' => (string) $product['product_id'],
                'product_handle' => $product['product_handle'] ?? null,
                'product_title' => $product['product_title'] ?? null,
                'review_source' => 'backstage_native',
            ]);

            $completion = $award['completion'] ?? null;
            $event = $award['event'] ?? null;
            $review->forceFill([
                'candle_cash_task_event_id' => $event?->id ?: $review->candle_cash_task_event_id,
                'candle_cash_task_completion_id' => $completion?->id ?: $review->candle_cash_task_completion_id,
            ])->save();
        }

        if (! $existing) {
            $this->sendNotification($review);
        }

        $this->eventLogger->log('product_review_submitted', [
            'status' => 'ok',
            'profile' => $profile,
            'request_key' => $requestKey !== '' ? $requestKey : null,
            'source_surface' => $sourceSurface,
            'source_type' => 'shopify_product_review',
            'source_id' => (string) $review->external_review_id,
            'meta' => [
                'product_id' => (string) $product['product_id'],
                'product_handle' => $product['product_handle'] ?? null,
                'status' => $review->status,
                'award_state' => $award['state'] ?? null,
                'created' => ! $existing,
            ],
            'resolution_status' => 'resolved',
        ]);

        return [
            'ok' => true,
            'state' => ! $existing ? ($moderationEnabled ? 'review_pending' : 'review_live') : 'review_updated',
            'review' => $review->fresh(['profile']),
            'award' => $award,
            'created' => ! $existing,
        ];
    }

    public function approve(MarketingReviewHistory $review, ?int $moderatorId = null, ?string $note = null): MarketingReviewHistory
    {
        $review->forceFill([
            'status' => 'approved',
            'is_published' => true,
            'approved_at' => now(),
            'rejected_at' => null,
            'reviewed_at' => now(),
            'moderated_by' => $moderatorId,
            'moderation_notes' => $note ?: $review->moderation_notes,
        ])->save();

        return $review->fresh(['profile']);
    }

    public function reject(MarketingReviewHistory $review, ?int $moderatorId = null, ?string $note = null): MarketingReviewHistory
    {
        $review->forceFill([
            'status' => 'rejected',
            'is_published' => false,
            'rejected_at' => now(),
            'reviewed_at' => now(),
            'moderated_by' => $moderatorId,
            'moderation_notes' => $note ?: $review->moderation_notes,
        ])->save();

        return $review->fresh(['profile']);
    }

    public function delete(MarketingReviewHistory $review): void
    {
        $review->delete();
    }

    public function moderationEnabled(): bool
    {
        return (bool) data_get($this->taskService->integrationConfig(), 'product_review_moderation_enabled', false);
    }

    public function allowGuestReviews(): bool
    {
        return (bool) data_get($this->taskService->integrationConfig(), 'product_review_allow_guest', true);
    }

    public function minimumBodyLength(): int
    {
        return max(12, (int) data_get($this->taskService->integrationConfig(), 'product_review_min_length', 24));
    }

    public function notificationEmail(): ?string
    {
        return $this->nullableString(data_get($this->taskService->integrationConfig(), 'product_review_notification_email', 'info@theforestrystudio.com'));
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,store_key?:?string} $product
     */
    protected function approvedReviewsQuery(array $product)
    {
        return $this->reviewLookupQuery($product)->where('status', 'approved')->where('is_published', true);
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,store_key?:?string} $product
     */
    protected function reviewLookupQuery(array $product)
    {
        return MarketingReviewHistory::query()
            ->where(function ($query) use ($product): void {
                $query->where('product_id', (string) $product['product_id']);

                if (filled($product['product_handle'] ?? null)) {
                    $query->orWhere('product_handle', (string) $product['product_handle']);
                }
            });
    }

    protected function reviewPayload(MarketingReviewHistory $review): array
    {
        return [
            'id' => (int) $review->id,
            'rating' => (int) $review->rating,
            'title' => $review->title ? (string) $review->title : null,
            'body' => $review->body ? (string) $review->body : null,
            'reviewer_name' => $review->displayReviewerName(),
            'status' => (string) ($review->status ?: 'approved'),
            'submitted_at' => optional($review->submitted_at ?: $review->created_at)->toIso8601String(),
            'approved_at' => optional($review->approved_at ?: $review->reviewed_at)->toIso8601String(),
            'product_id' => $review->product_id ? (string) $review->product_id : null,
            'product_handle' => $review->product_handle ? (string) $review->product_handle : null,
            'product_title' => $review->product_title ? (string) $review->product_title : null,
            'is_verified_buyer' => (bool) $review->is_verified_buyer,
            'source' => (string) ($review->submission_source ?: 'native'),
        ];
    }

    protected function reviewerName(?MarketingProfile $profile, ?string $fallbackName): ?string
    {
        $fallbackName = $this->sanitizeLine($fallbackName);
        if ($fallbackName) {
            return $fallbackName;
        }

        if (! $profile) {
            return null;
        }

        $name = trim((string) ($profile->display_name ?: trim(($profile->first_name ?? '') . ' ' . ($profile->last_name ?? ''))));

        return $name !== '' ? $name : null;
    }

    protected function reviewerEmail(?MarketingProfile $profile, ?string $fallbackEmail): ?string
    {
        $fallbackEmail = $this->nullableString($fallbackEmail);
        if ($fallbackEmail) {
            return Str::lower($fallbackEmail);
        }

        if (! $profile) {
            return null;
        }

        return $this->nullableString($profile->normalized_email ?: $profile->email);
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,store_key?:?string} $product
     */
    protected function externalReviewId(array $product, ?MarketingProfile $profile, ?string $normalizedEmail): string
    {
        $identity = $profile?->id ? 'profile:' . $profile->id : 'email:' . sha1((string) $normalizedEmail);

        return 'native:' . sha1(implode('|', [
            (string) $product['product_id'],
            (string) ($product['product_handle'] ?? ''),
            $identity,
        ]));
    }

    protected function externalCustomerId(?MarketingProfile $profile, ?string $normalizedEmail): string
    {
        if ($profile?->id) {
            return 'profile:' . $profile->id;
        }

        return 'email:' . sha1((string) $normalizedEmail);
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,store_key?:?string} $product
     */
    protected function submissionIdentitySourceId(array $product, string $email): string
    {
        return 'product-review:' . sha1(implode('|', [
            (string) $product['product_id'],
            Str::lower(trim($email)),
        ]));
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,store_key?:?string} $product
     */
    protected function canonicalProductUrl(array $product): ?string
    {
        $url = $this->nullableString($product['product_url'] ?? null);
        if ($url) {
            if (Str::startsWith($url, 'http://') || Str::startsWith($url, 'https://')) {
                return $url;
            }

            return rtrim((string) config('marketing.candle_cash.storefront_base_url', 'https://theforestrystudio.com'), '/') . '/' . ltrim($url, '/');
        }

        if (filled($product['product_handle'] ?? null)) {
            return rtrim((string) config('marketing.candle_cash.storefront_base_url', 'https://theforestrystudio.com'), '/') . '/products/' . ltrim((string) $product['product_handle'], '/');
        }

        return null;
    }

    protected function sanitizeLine(?string $value): ?string
    {
        $value = trim(strip_tags((string) $value));

        return $value !== '' ? Str::limit($value, 190, '') : null;
    }

    protected function sanitizeBody(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '');

        return Str::limit($value, 4000, '');
    }

    protected function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function reviewsMatch(MarketingReviewHistory $existing, int $rating, ?string $title, string $body): bool
    {
        return (int) $existing->rating === $rating
            && trim((string) ($existing->title ?? '')) === trim((string) ($title ?? ''))
            && trim((string) ($existing->body ?? '')) === trim($body);
    }

    protected function hasOrderLink(MarketingProfile $profile): bool
    {
        return MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'order')
            ->exists();
    }

    protected function sendNotification(MarketingReviewHistory $review): void
    {
        $email = $this->notificationEmail();
        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->send(new ProductReviewSubmittedMail($review));
            $review->forceFill([
                'notification_sent_at' => now(),
            ])->save();
        } catch (\Throwable $exception) {
            Log::warning('product review notification failed', [
                'review_id' => $review->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
