<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MappingException;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingReviewHistory;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

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
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,store_key?:?string,tenant_id?:?int} $product
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
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,store_key?:?string,tenant_id?:?int} $product
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

        $storeKey = $this->nullableString($product['store_key'] ?? null);
        if ($storeKey === null) {
            throw new \InvalidArgumentException('A verified Shopify store context is required before submitting a product review.');
        }

        $tenantId = is_numeric($product['tenant_id'] ?? null) && (int) ($product['tenant_id'] ?? 0) > 0
            ? (int) $product['tenant_id']
            : null;

        Log::info('native product review submission received', [
            'marketing_profile_id' => $viewer?->id,
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'product_id' => (string) $product['product_id'],
            'product_handle' => $product['product_handle'] ?? null,
            'rating' => $rating,
            'request_key' => $requestKey !== '' ? $requestKey : null,
            'source_surface' => $sourceSurface,
            'allow_guest' => $allowGuest,
            'has_reviewer_email' => $reviewerEmail !== null,
            'reviewer_email_hash' => $normalizedEmail ? sha1($normalizedEmail) : null,
        ]);

        $profile = $viewer;
        if (! $profile && $reviewerEmail) {
            $resolution = $this->identityService->resolve([
                'first_name' => $reviewerName,
                'email' => $reviewerEmail,
            ], [
                'source_type' => 'product_review_submission',
                'source_id' => $this->submissionIdentitySourceId($product, $reviewerEmail),
                'tenant_id' => $tenantId,
                'allow_create' => true,
                'source_label' => 'product_review_submission',
                'source_channels' => ['shopify', 'product_review'],
                'source_meta' => [
                    'product_id' => (string) $product['product_id'],
                    'product_handle' => $product['product_handle'] ?? null,
                    'shopify_store_key' => $storeKey,
                    'tenant_id' => $tenantId,
                ],
            ]);

            if ($resolution['status'] === 'review_required') {
                Log::warning('native product review identity unresolved', [
                    'tenant_id' => $tenantId,
                    'store_key' => $storeKey,
                    'product_id' => (string) $product['product_id'],
                    'product_handle' => $product['product_handle'] ?? null,
                    'request_key' => $requestKey !== '' ? $requestKey : null,
                    'source_surface' => $sourceSurface,
                    'identity_status' => (string) ($resolution['status'] ?? 'review_required'),
                    'reviewer_email_hash' => $normalizedEmail ? sha1($normalizedEmail) : null,
                ]);

                return [
                    'ok' => false,
                    'error' => 'identity_review_required',
                    'message' => 'We could not safely attach that review to a customer profile yet.',
                ];
            }

            $profile = $resolution['profile'];

            Log::info('native product review identity resolved', [
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'product_id' => (string) $product['product_id'],
                'product_handle' => $product['product_handle'] ?? null,
                'request_key' => $requestKey !== '' ? $requestKey : null,
                'source_surface' => $sourceSurface,
                'identity_status' => (string) ($resolution['status'] ?? 'resolved'),
                'marketing_profile_id' => $profile?->id,
                'reviewer_email_hash' => $normalizedEmail ? sha1($normalizedEmail) : null,
            ]);
        }

        $externalReviewId = $this->externalReviewId($product, $profile, $normalizedEmail);
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

            Log::info('native product review duplicate blocked', [
                'marketing_profile_id' => $profile?->id,
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'product_id' => (string) $product['product_id'],
                'product_handle' => $product['product_handle'] ?? null,
                'request_key' => $requestKey !== '' ? $requestKey : null,
                'external_review_id' => $externalReviewId,
                'existing_review_id' => $existing->id,
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
        try {
            [$review, $created] = $this->persistReview($reviewLookup, [
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
            ]);

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
        } catch (\Throwable $exception) {
            Log::error('native product review submit failed', [
                'marketing_profile_id' => $profile?->id,
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'product_id' => (string) $product['product_id'],
                'product_handle' => $product['product_handle'] ?? null,
                'request_key' => $requestKey !== '' ? $requestKey : null,
                'source_surface' => $sourceSurface,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        Log::info('native product review persisted', [
            'marketing_profile_id' => $profile?->id,
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'product_id' => (string) $product['product_id'],
            'product_handle' => $product['product_handle'] ?? null,
            'request_key' => $requestKey !== '' ? $requestKey : null,
            'source_surface' => $sourceSurface,
            'review_id' => $review->id,
            'external_review_id' => (string) $review->external_review_id,
            'review_status' => (string) $review->status,
            'created' => $created,
            'award_state' => (string) ($award['state'] ?? 'not_attempted'),
            'task_event_id' => data_get($award, 'event.id'),
            'task_completion_id' => data_get($award, 'completion.id'),
            'transaction_id' => data_get($award, 'completion.candle_cash_transaction_id'),
        ]);

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
                'created' => $created,
            ],
            'resolution_status' => 'resolved',
        ]);

        return [
            'ok' => true,
            'state' => $created ? ($moderationEnabled ? 'review_pending' : 'review_live') : 'review_updated',
            'review' => $review->fresh(['profile']),
            'award' => $award,
            'created' => $created,
        ];
    }

    /**
     * @param array{
     *   reviewer_name:string,
     *   product_title:string,
     *   rating:int,
     *   title:?string,
     *   body:string,
     *   submitted_at:string|\DateTimeInterface,
     *   store_key?:?string,
     *   submission_source?:?string,
     *   product_id?:?string,
     *   product_handle?:?string,
     *   product_url?:?string
     * } $payload
     * @return array{
     *   ok:bool,
     *   created:bool,
     *   review:\App\Models\MarketingReviewHistory,
     *   match:array<string,mixed>,
     *   product:array<string,mixed>
     * }
     */
    public function importReview(array $payload): array
    {
        $reviewerName = $this->sanitizeLine($payload['reviewer_name'] ?? null);
        $productTitle = $this->sanitizeLine($payload['product_title'] ?? null);
        $title = $this->sanitizeLine($payload['title'] ?? null);
        $body = $this->sanitizeBody((string) ($payload['body'] ?? ''));
        $rating = max(1, min(5, (int) ($payload['rating'] ?? 0)));

        if ($reviewerName === null || $productTitle === null || $body === '' || $rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('Imported review payload is missing required review fields.');
        }

        $submittedAt = $this->importTimestamp($payload['submitted_at'] ?? null);
        $submissionSource = $this->nullableString($payload['submission_source'] ?? null) ?: 'growave_import';
        $product = $this->resolveImportedProduct([
            'product_title' => $productTitle,
            'product_id' => $payload['product_id'] ?? null,
            'product_handle' => $payload['product_handle'] ?? null,
            'product_url' => $payload['product_url'] ?? null,
            'store_key' => $payload['store_key'] ?? null,
        ]);
        $match = $this->matchImportedProfile($reviewerName, $product);

        $existing = $this->findExistingImportedReview(
            reviewerName: $reviewerName,
            product: $product,
            rating: $rating,
            title: $title,
            body: $body,
            submittedAt: $submittedAt
        );

        if ($existing) {
            $review = $this->refreshImportedReview(
                review: $existing,
                reviewerName: $reviewerName,
                product: $product,
                title: $title,
                body: $body,
                rating: $rating,
                submittedAt: $submittedAt,
                submissionSource: $submissionSource,
                match: $match
            );

            if ($match['profile'] ?? null) {
                $this->recordImportProfileLink($review, $match);
            }

            return [
                'ok' => true,
                'created' => false,
                'review' => $review->fresh(['profile']),
                'match' => $match,
                'product' => $product,
            ];
        }

        $lookup = [
            'provider' => 'backstage',
            'integration' => 'native',
            'store_key' => (string) $product['store_key'],
            'external_review_id' => $this->importExternalReviewId($reviewerName, $product, $submittedAt, $title, $body),
        ];

        [$review, $created] = $this->persistReview($lookup, [
            'marketing_profile_id' => $match['profile']?->id,
            'marketing_review_summary_id' => null,
            'external_customer_id' => $this->resolvedExternalCustomerId($match, $reviewerName),
            'rating' => $rating,
            'title' => $title,
            'body' => $body,
            'reviewer_name' => $reviewerName,
            'reviewer_email' => $match['reviewer_email'],
            'is_published' => true,
            'status' => 'approved',
            'submission_source' => $submissionSource,
            'is_verified_buyer' => (bool) ($match['verified_buyer'] ?? false),
            'product_id' => $product['product_id'],
            'product_handle' => $product['product_handle'],
            'product_url' => $product['product_url'],
            'product_title' => $product['product_title'],
            'submitted_at' => $submittedAt,
            'reviewed_at' => $submittedAt,
            'approved_at' => $submittedAt,
            'rejected_at' => null,
            'moderated_by' => null,
            'moderation_notes' => null,
            'source_synced_at' => now(),
            'raw_payload' => [
                'import' => [
                    'source' => 'growave_replacement_migration',
                    'reviewer_name' => $reviewerName,
                    'rating' => $rating,
                    'title' => $title,
                    'body' => $body,
                    'submitted_at' => $submittedAt->toIso8601String(),
                    'match' => $this->matchSummary($match),
                    'product' => $this->productSummary($product),
                ],
            ],
        ]);

        if ($match['profile'] ?? null) {
            $this->recordImportProfileLink($review, $match);
        }

        return [
            'ok' => true,
            'created' => $created,
            'review' => $review->fresh(['profile']),
            'match' => $match,
            'product' => $product,
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
     * @param array<string,mixed> $lookup
     * @param array<string,mixed> $attributes
     * @return array{0:\App\Models\MarketingReviewHistory,1:bool}
     */
    protected function persistReview(array $lookup, array $attributes): array
    {
        /** @var MarketingReviewHistory|null $existing */
        $existing = MarketingReviewHistory::query()->where($lookup)->first();
        $review = MarketingReviewHistory::query()->updateOrCreate($lookup, $attributes);
        $created = ! $existing;

        return [$review, $created];
    }

    /**
     * @param array{product_id:?string,product_handle:?string,product_title:?string,product_url:?string,store_key:string,match_evidence:array<int,string>} $product
     * @return array<string,mixed>
     */
    protected function matchImportedProfile(string $reviewerName, array $product): array
    {
        [$firstName, $lastName] = $this->splitName($reviewerName);
        $storeKey = $this->nullableString($product['store_key'] ?? null) ?: 'retail';
        $empty = [
            'matched' => false,
            'profile' => null,
            'external_profile' => null,
            'reviewer_email' => null,
            'verified_buyer' => false,
            'method' => 'unmatched',
            'confidence' => 0.0,
            'evidence' => ['No confident customer match was found.'],
        ];

        if ($firstName === null || $lastName === null) {
            return $empty;
        }

        $profileMatches = MarketingProfile::query()
            ->whereRaw('lower(first_name) = ?', [$firstName])
            ->whereRaw('lower(last_name) = ?', [$lastName])
            ->get(['id', 'first_name', 'last_name', 'email', 'normalized_email']);
        $exactProfile = $profileMatches->count() === 1 ? $profileMatches->first() : null;

        $externalMatches = CustomerExternalProfile::query()
            ->whereRaw('lower(first_name) = ?', [$firstName])
            ->whereRaw('lower(last_name) = ?', [$lastName])
            ->whereNotNull('marketing_profile_id')
            ->when($storeKey !== '', fn ($query) => $query->where('store_key', $storeKey))
            ->get([
                'id',
                'marketing_profile_id',
                'integration',
                'store_key',
                'external_customer_id',
                'email',
                'normalized_email',
                'first_name',
                'last_name',
                'full_name',
            ]);
        $exactExternalProfileIds = $externalMatches->pluck('marketing_profile_id')->filter()->unique()->values();
        $exactExternal = $exactExternalProfileIds->count() === 1
            ? $externalMatches->firstWhere('marketing_profile_id', (int) $exactExternalProfileIds->first())
            : null;

        $orderMatch = $this->orderBackedProfileMatch($reviewerName, $product, $exactProfile, $exactExternal, $storeKey);
        if ($orderMatch['matched']) {
            return $orderMatch;
        }

        if ($exactProfile && (! $exactExternal || (int) $exactExternal->marketing_profile_id === (int) $exactProfile->id)) {
            $externalProfile = $exactExternal ?: $this->preferredExternalProfile($exactProfile, $storeKey);

            return [
                'matched' => true,
                'profile' => $exactProfile,
                'external_profile' => $externalProfile,
                'reviewer_email' => $this->nullableString($exactProfile->normalized_email ?: $exactProfile->email ?: $externalProfile?->normalized_email ?: $externalProfile?->email),
                'verified_buyer' => false,
                'method' => 'exact_full_name_unique',
                'confidence' => 0.94,
                'evidence' => array_values(array_filter([
                    'Exact full-name match to a single marketing profile.',
                    $externalProfile ? 'Matching external customer profile confirms the same customer identity.' : null,
                ])),
            ];
        }

        if ($exactExternal) {
            $profile = $exactProfile && (int) $exactProfile->id === (int) $exactExternal->marketing_profile_id
                ? $exactProfile
                : MarketingProfile::query()->find((int) $exactExternal->marketing_profile_id);

            if ($profile) {
                return [
                    'matched' => true,
                    'profile' => $profile,
                    'external_profile' => $exactExternal,
                    'reviewer_email' => $this->nullableString($profile->normalized_email ?: $profile->email ?: $exactExternal->normalized_email ?: $exactExternal->email),
                    'verified_buyer' => false,
                    'method' => 'exact_external_profile_name',
                    'confidence' => 0.9,
                    'evidence' => [
                        'Exact full-name match to a single linked external customer profile.',
                    ],
                ];
            }
        }

        $closeMatch = $this->closeProfileMatch($reviewerName, $lastName, $storeKey);
        if ($closeMatch !== null) {
            return $closeMatch;
        }

        return $empty;
    }

    /**
     * @param array{product_title:?string,product_id:?string,product_handle:?string,product_url:?string,store_key:?string} $payload
     * @return array{product_id:?string,product_handle:?string,product_title:string,product_url:?string,store_key:string,match_evidence:array<int,string>}
     */
    protected function resolveImportedProduct(array $payload): array
    {
        $title = $this->sanitizeLine($payload['product_title'] ?? null);
        if ($title === null) {
            throw new \InvalidArgumentException('Imported review payload is missing a product title.');
        }

        $product = [
            'product_id' => $this->nullableString($payload['product_id'] ?? null),
            'product_handle' => $this->nullableString($payload['product_handle'] ?? null),
            'product_title' => $title,
            'product_url' => $this->nullableString($payload['product_url'] ?? null),
            'store_key' => $this->nullableString($payload['store_key'] ?? null) ?: 'retail',
            'match_evidence' => [],
        ];

        $mappingCandidates = MappingException::query()
            ->where('raw_title', $title)
            ->orderByDesc('id')
            ->get(['shopify_order_id', 'shopify_line_item_id', 'payload_json']);

        foreach ($mappingCandidates as $candidate) {
            $candidateId = $this->nullableString((string) data_get($candidate->payload_json, 'product_id'));
            if ($candidateId && ! $product['product_id']) {
                $product['product_id'] = $candidateId;
                $product['match_evidence'][] = 'Matched Shopify product id from imported order line data.';
            }
        }

        $handleGuess = Str::slug($title);
        $reviewCandidates = MarketingReviewHistory::query()
            ->where(function ($query) use ($title, $handleGuess, $product): void {
                $query->where('product_title', $title);

                if ($product['product_id']) {
                    $query->orWhere('product_id', $product['product_id'])
                        ->orWhere('raw_payload', 'like', '%' . $product['product_id'] . '%');
                }

                if ($handleGuess !== '') {
                    $query->orWhere('product_handle', $handleGuess)
                        ->orWhere('raw_payload', 'like', '%"handle":"' . $handleGuess . '"%');
                }
            })
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'product_id', 'product_handle', 'product_url', 'product_title', 'raw_payload']);

        foreach ($reviewCandidates as $candidate) {
            $metadata = $this->productMetadataFromReview($candidate);

            if (! $product['product_handle'] && $metadata['product_handle']) {
                $product['product_handle'] = $metadata['product_handle'];
                $product['match_evidence'][] = 'Matched product handle from existing review data.';
            }

            if (! $product['product_id'] && $metadata['product_id']) {
                $product['product_id'] = $metadata['product_id'];
                $product['match_evidence'][] = 'Matched Shopify product id from existing review data.';
            }

            if (! $product['product_url'] && $metadata['product_url']) {
                $product['product_url'] = $metadata['product_url'];
            }
        }

        $product['product_url'] = $product['product_url'] ?: $this->canonicalProductUrl($product);

        return $product;
    }

    /**
     * @param array{product_id:?string,product_handle:?string,product_title:string,product_url:?string,store_key:string,match_evidence:array<int,string>} $product
     */
    protected function findExistingImportedReview(
        string $reviewerName,
        array $product,
        int $rating,
        ?string $title,
        string $body,
        CarbonInterface $submittedAt
    ): ?MarketingReviewHistory {
        $start = $submittedAt->copy()->startOfDay();
        $end = $submittedAt->copy()->endOfDay();
        $normalizedReviewerName = $this->normalizedName($reviewerName);

        $candidates = MarketingReviewHistory::query()
            ->with('profile:id,first_name,last_name,email')
            ->where('rating', $rating)
            ->where('body', $body)
            ->where(function ($query) use ($title): void {
                if ($title === null) {
                    $query->whereNull('title')->orWhere('title', '');

                    return;
                }

                $query->where('title', $title);
            })
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('submitted_at', [$start, $end])
                    ->orWhere(function ($fallback) use ($start, $end): void {
                        $fallback->whereNull('submitted_at')
                            ->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->normalizedName($candidate->displayReviewerName()) !== $normalizedReviewerName) {
                continue;
            }

            if (! $this->reviewMatchesImportedProduct($candidate, $product)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param array{product_id:?string,product_handle:?string,product_title:string,product_url:?string,store_key:string,match_evidence:array<int,string>} $product
     * @param array<string,mixed> $match
     */
    protected function refreshImportedReview(
        MarketingReviewHistory $review,
        string $reviewerName,
        array $product,
        ?string $title,
        string $body,
        int $rating,
        CarbonInterface $submittedAt,
        string $submissionSource,
        array $match
    ): MarketingReviewHistory {
        $review->forceFill([
            'marketing_profile_id' => $review->marketing_profile_id ?: $match['profile']?->id,
            'external_customer_id' => $review->external_customer_id ?: $this->resolvedExternalCustomerId($match, $reviewerName),
            'rating' => $review->rating ?: $rating,
            'title' => $review->title ?: $title,
            'body' => $review->body ?: $body,
            'reviewer_name' => $review->reviewer_name ?: $reviewerName,
            'reviewer_email' => $review->reviewer_email ?: ($match['reviewer_email'] ?? null),
            'is_published' => $review->is_published ?? true,
            'status' => $review->status ?: 'approved',
            'submission_source' => $review->submission_source ?: $submissionSource,
            'is_verified_buyer' => (bool) $review->is_verified_buyer || (bool) ($match['verified_buyer'] ?? false),
            'product_id' => $review->product_id ?: $product['product_id'],
            'product_handle' => $review->product_handle ?: $product['product_handle'],
            'product_url' => $review->product_url ?: $product['product_url'],
            'product_title' => $review->product_title ?: $product['product_title'],
            'submitted_at' => $this->preferredTimestamp($review->submitted_at, $submittedAt),
            'reviewed_at' => $review->reviewed_at ?: $submittedAt,
            'approved_at' => $review->approved_at ?: $submittedAt,
        ])->save();

        return $review->fresh(['profile']);
    }

    /**
     * @param array<string,mixed> $match
     */
    protected function recordImportProfileLink(MarketingReviewHistory $review, array $match): void
    {
        $profile = $match['profile'] ?? null;
        if (! $profile instanceof MarketingProfile) {
            return;
        }

        MarketingProfileLink::query()->updateOrCreate(
            [
                'marketing_profile_id' => $profile->id,
                'source_type' => 'product_review_import',
                'source_id' => $this->importLinkSourceId($review),
            ],
            [
                'source_meta' => [
                    'review_id' => $review->id,
                    'product_title' => $review->product_title,
                    'submitted_at' => optional($review->submitted_at)->toIso8601String(),
                    'match_method' => $match['method'] ?? null,
                    'match_evidence' => $match['evidence'] ?? [],
                ],
                'match_method' => (string) ($match['method'] ?? 'exact_review_import'),
                'confidence' => (float) ($match['confidence'] ?? 1),
            ]
        );
    }

    /**
     * @param array<string,mixed> $match
     * @return array<string,mixed>
     */
    protected function matchSummary(array $match): array
    {
        return [
            'matched' => (bool) ($match['matched'] ?? false),
            'profile_id' => $match['profile']?->id,
            'method' => $match['method'] ?? null,
            'confidence' => $match['confidence'] ?? null,
            'evidence' => $match['evidence'] ?? [],
        ];
    }

    /**
     * @param array{product_id:?string,product_handle:?string,product_title:string,product_url:?string,store_key:string,match_evidence:array<int,string>} $product
     * @return array<string,mixed>
     */
    protected function productSummary(array $product): array
    {
        return [
            'product_id' => $product['product_id'],
            'product_handle' => $product['product_handle'],
            'product_title' => $product['product_title'],
            'product_url' => $product['product_url'],
            'match_evidence' => $product['match_evidence'],
        ];
    }

    /**
     * @param array{product_id:?string,product_handle:?string,product_title:string,product_url:?string,store_key:string,match_evidence:array<int,string>} $product
     * @param \App\Models\MarketingProfile|null $exactProfile
     * @param \App\Models\CustomerExternalProfile|null $exactExternal
     * @return array<string,mixed>
     */
    protected function orderBackedProfileMatch(
        string $reviewerName,
        array $product,
        ?MarketingProfile $exactProfile,
        ?CustomerExternalProfile $exactExternal,
        string $storeKey
    ): array {
        $candidateEmails = collect([
            $this->nullableString($exactProfile?->normalized_email ?: $exactProfile?->email),
            $this->nullableString($exactExternal?->normalized_email ?: $exactExternal?->email),
        ])->filter()->map(fn (string $email) => Str::lower($email))->unique()->values();

        $orders = Order::query()
            ->select([
                'orders.id',
                'orders.shopify_customer_id',
                'orders.customer_name',
                'orders.first_name',
                'orders.last_name',
                'orders.email',
                'orders.customer_email',
                'orders.shipping_email',
                'orders.billing_email',
                'orders.ordered_at',
            ])
            ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->where('order_lines.raw_title', $product['product_title'])
            ->orderByDesc('orders.ordered_at')
            ->get();

        $matchingOrders = $orders->filter(function (Order $order) use ($candidateEmails, $reviewerName): bool {
            $nameMatch = $this->normalizedName($this->orderName($order)) === $this->normalizedName($reviewerName);
            $email = $this->nullableString($this->orderEmail($order));
            $emailMatch = $email !== null && $candidateEmails->contains(Str::lower($email));

            return $nameMatch || $emailMatch;
        })->values();

        if ($matchingOrders->isEmpty()) {
            return ['matched' => false];
        }

        $shopifyCustomerIds = $matchingOrders->pluck('shopify_customer_id')->filter()->unique()->values();
        if ($shopifyCustomerIds->isEmpty()) {
            return ['matched' => false];
        }

        $externalProfiles = CustomerExternalProfile::query()
            ->where('integration', 'shopify_customer')
            ->when($storeKey !== '', fn ($query) => $query->where('store_key', $storeKey))
            ->whereIn('external_customer_id', $shopifyCustomerIds->all())
            ->get([
                'id',
                'marketing_profile_id',
                'store_key',
                'external_customer_id',
                'email',
                'normalized_email',
                'first_name',
                'last_name',
                'full_name',
            ]);

        $profileIds = $externalProfiles->pluck('marketing_profile_id')->filter()->unique()->values();
        if ($profileIds->count() !== 1) {
            return ['matched' => false];
        }

        $profile = $exactProfile && (int) $exactProfile->id === (int) $profileIds->first()
            ? $exactProfile
            : MarketingProfile::query()->find((int) $profileIds->first());

        if (! $profile) {
            return ['matched' => false];
        }

        $externalProfile = $externalProfiles->firstWhere('marketing_profile_id', $profile->id);

        return [
            'matched' => true,
            'profile' => $profile,
            'external_profile' => $externalProfile,
            'reviewer_email' => $this->nullableString($profile->normalized_email ?: $profile->email ?: $externalProfile?->normalized_email ?: $externalProfile?->email),
            'verified_buyer' => true,
            'method' => 'exact_order_product_match',
            'confidence' => 1.0,
            'evidence' => [
                'Exact product order history matched the reviewer name/email and a single linked Shopify customer.',
            ],
        ];
    }

    protected function closeProfileMatch(string $reviewerName, string $lastName, string $storeKey): ?array
    {
        $candidates = MarketingProfile::query()
            ->whereRaw('lower(last_name) = ?', [$lastName])
            ->get(['id', 'first_name', 'last_name', 'email', 'normalized_email']);

        if ($candidates->isEmpty()) {
            return null;
        }

        $scores = $candidates->map(function (MarketingProfile $profile) use ($reviewerName): array {
            $profileName = trim((string) ($profile->first_name . ' ' . $profile->last_name));
            similar_text($this->normalizedName($reviewerName), $this->normalizedName($profileName), $score);

            return [
                'profile' => $profile,
                'score' => $score / 100,
            ];
        })->sortByDesc('score')->values();

        $top = $scores->first();
        $runnerUp = $scores->get(1);

        if (! $top || (float) $top['score'] < 0.93 || ($runnerUp && ((float) $top['score'] - (float) $runnerUp['score']) < 0.05)) {
            return null;
        }

        /** @var MarketingProfile $profile */
        $profile = $top['profile'];
        $externalProfile = $this->preferredExternalProfile($profile, $storeKey);

        return [
            'matched' => true,
            'profile' => $profile,
            'external_profile' => $externalProfile,
            'reviewer_email' => $this->nullableString($profile->normalized_email ?: $profile->email ?: $externalProfile?->normalized_email ?: $externalProfile?->email),
            'verified_buyer' => false,
            'method' => 'close_name_unique',
            'confidence' => round((float) $top['score'], 2),
            'evidence' => [
                'Single high-confidence close-name match after exact match checks came back empty.',
            ],
        ];
    }

    protected function preferredExternalProfile(MarketingProfile $profile, string $storeKey): ?CustomerExternalProfile
    {
        return CustomerExternalProfile::query()
            ->where('marketing_profile_id', $profile->id)
            ->when($storeKey !== '', fn ($query) => $query->where('store_key', $storeKey))
            ->orderByRaw("case when integration = 'shopify_customer' then 0 when integration = 'growave' then 1 else 2 end")
            ->orderBy('id')
            ->first([
                'id',
                'marketing_profile_id',
                'integration',
                'store_key',
                'external_customer_id',
                'email',
                'normalized_email',
                'first_name',
                'last_name',
                'full_name',
            ]);
    }

    protected function resolvedExternalCustomerId(array $match, string $reviewerName): string
    {
        $externalProfile = $match['external_profile'] ?? null;
        if ($externalProfile instanceof CustomerExternalProfile && filled($externalProfile->external_customer_id)) {
            return (string) $externalProfile->external_customer_id;
        }

        $reviewerEmail = $this->nullableString($match['reviewer_email'] ?? null);
        if ($reviewerEmail !== null) {
            return 'email:' . sha1(Str::lower($reviewerEmail));
        }

        return 'importer:' . sha1($this->normalizedName($reviewerName));
    }

    /**
     * @param array{product_id:?string,product_handle:?string,product_title:string,product_url:?string,store_key:string,match_evidence:array<int,string>} $product
     */
    protected function importExternalReviewId(
        string $reviewerName,
        array $product,
        CarbonInterface $submittedAt,
        ?string $title,
        string $body
    ): string {
        return 'import:' . sha1(implode('|', [
            (string) $product['store_key'],
            (string) ($product['product_id'] ?? ''),
            (string) ($product['product_handle'] ?? ''),
            $this->normalizedName($reviewerName),
            $submittedAt->toDateString(),
            trim((string) ($title ?? '')),
            trim($body),
        ]));
    }

    protected function importLinkSourceId(MarketingReviewHistory $review): string
    {
        return 'product-review-import:' . sha1(implode('|', [
            (string) $review->id,
            (string) ($review->product_id ?? ''),
            (string) ($review->product_handle ?? ''),
            (string) ($review->submitted_at?->toDateString() ?? $review->created_at?->toDateString() ?? ''),
        ]));
    }

    /**
     * @param array{product_id:?string,product_handle:?string,product_title:string,product_url:?string,store_key:string,match_evidence:array<int,string>} $product
     */
    protected function reviewMatchesImportedProduct(MarketingReviewHistory $review, array $product): bool
    {
        $existing = $this->productMetadataFromReview($review);

        if (($product['product_id'] ?? null) && ($existing['product_id'] ?? null) && (string) $product['product_id'] === (string) $existing['product_id']) {
            return true;
        }

        if (($product['product_handle'] ?? null) && ($existing['product_handle'] ?? null) && (string) $product['product_handle'] === (string) $existing['product_handle']) {
            return true;
        }

        return $this->normalizedName((string) ($product['product_title'] ?? '')) === $this->normalizedName((string) ($existing['product_title'] ?? ''));
    }

    /**
     * @return array{product_id:?string,product_handle:?string,product_title:?string,product_url:?string}
     */
    protected function productMetadataFromReview(MarketingReviewHistory $review): array
    {
        $payload = is_array($review->raw_payload) ? $review->raw_payload : [];
        $fromPayload = $this->productMetadataFromPayload($payload);

        return [
            'product_id' => $this->nullableString($review->product_id ?: ($fromPayload['product_id'] ?? null)),
            'product_handle' => $this->nullableString($review->product_handle ?: ($fromPayload['product_handle'] ?? null)),
            'product_title' => $this->nullableString($review->product_title ?: ($fromPayload['product_title'] ?? null)),
            'product_url' => $this->nullableString($review->product_url ?: ($fromPayload['product_url'] ?? null)),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{product_id:?string,product_handle:?string,product_title:?string,product_url:?string}
     */
    protected function productMetadataFromPayload(array $payload): array
    {
        $product = is_array($payload['product'] ?? null) ? $payload['product'] : [];
        $handle = $this->nullableString(data_get($product, 'handle'));

        return [
            'product_id' => $this->nullableString((string) data_get($product, 'id')),
            'product_handle' => $handle,
            'product_title' => $this->nullableString(data_get($product, 'title')),
            'product_url' => $handle ? '/products/' . ltrim($handle, '/') : null,
        ];
    }

    protected function importTimestamp(mixed $value): CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy();
        }

        if ($value instanceof \DateTimeInterface) {
            return now()->setDate((int) $value->format('Y'), (int) $value->format('m'), (int) $value->format('d'))->startOfDay();
        }

        $parsed = trim((string) $value);
        if ($parsed === '') {
            throw new \InvalidArgumentException('Imported review payload is missing a submitted_at value.');
        }

        return now()->parse($parsed)->startOfDay();
    }

    protected function preferredTimestamp(?CarbonInterface $existing, CarbonInterface $imported): CarbonInterface
    {
        if ($existing === null) {
            return $imported;
        }

        return $existing->toDateString() === $imported->toDateString()
            ? $existing
            : $imported;
    }

    protected function normalizedName(string $value): string
    {
        return trim(mb_strtolower(preg_replace('/\s+/', ' ', $value) ?? ''));
    }

    /**
     * @return array{0:?string,1:?string}
     */
    protected function splitName(string $value): array
    {
        $normalized = $this->normalizedName($value);
        if ($normalized === '' || ! str_contains($normalized, ' ')) {
            return [null, null];
        }

        $parts = explode(' ', $normalized);
        $first = array_shift($parts);
        $last = array_pop($parts);

        return [$first ?: null, $last ?: null];
    }

    protected function orderName(Order $order): string
    {
        $name = trim((string) ($order->customer_name ?: trim(($order->first_name ?? '') . ' ' . ($order->last_name ?? ''))));

        return $name !== '' ? $name : trim((string) ($order->first_name ?? '') . ' ' . ($order->last_name ?? ''));
    }

    protected function orderEmail(Order $order): ?string
    {
        return $this->nullableString($order->customer_email ?: $order->email ?: $order->shipping_email ?: $order->billing_email);
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,store_key?:?string,tenant_id?:?int} $product
     */
    protected function approvedReviewsQuery(array $product)
    {
        return $this->reviewLookupQuery($product)->where('status', 'approved')->where('is_published', true);
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,store_key?:?string,tenant_id?:?int} $product
     */
    protected function reviewLookupQuery(array $product)
    {
        $storeKey = $this->nullableString($product['store_key'] ?? null);
        $productId = trim((string) ($product['product_id'] ?? ''));
        $productHandle = $this->nullableString($product['product_handle'] ?? null);

        return MarketingReviewHistory::query()
            ->when($storeKey !== null, fn ($query) => $query->where('store_key', $storeKey))
            ->where(function ($query) use ($productId, $productHandle): void {
                // Legacy Growave rows can store product identity only inside raw_payload.
                $query->where('product_id', $productId)
                    ->orWhere('raw_payload->product->id', $productId)
                    ->orWhere('raw_payload->product->shopifyProductId', $productId)
                    ->orWhere('raw_payload->product->productId', $productId);

                if ($productHandle !== null) {
                    $query->orWhere('product_handle', $productHandle)
                        ->orWhere('raw_payload->product->handle', $productHandle);
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
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,store_key?:?string,tenant_id?:?int} $product
     */
    protected function externalReviewId(array $product, ?MarketingProfile $profile, ?string $normalizedEmail): string
    {
        $identity = $profile?->id ? 'profile:' . $profile->id : 'email:' . sha1((string) $normalizedEmail);
        $storeKey = $this->nullableString($product['store_key'] ?? null) ?: 'unknown_store';

        return 'native:' . sha1(implode('|', [
            $storeKey,
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
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,store_key?:?string,tenant_id?:?int} $product
     */
    protected function submissionIdentitySourceId(array $product, string $email): string
    {
        $storeKey = $this->nullableString($product['store_key'] ?? null) ?: 'unknown_store';

        return 'product-review:' . sha1(implode('|', [
            $storeKey,
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

}
