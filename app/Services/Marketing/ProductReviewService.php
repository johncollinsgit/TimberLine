<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MappingException;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingReviewHistory;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ProductReviewService
{
    public function __construct(
        protected MarketingStorefrontIdentityService $identityService,
        protected CandleCashVerificationService $verificationService,
        protected CandleCashTaskService $taskService,
        protected MarketingStorefrontEventLogger $eventLogger,
        protected ShopifyProductReviewMetafieldService $shopifyProductReviewMetafieldService
    ) {
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,variant_id?:?string,store_key?:?string,tenant_id?:?int} $product
     * @return array<string,mixed>
     */
    public function storefrontPayload(array $product, ?MarketingProfile $viewer = null): array
    {
        $tenantId = $this->tenantIdForProduct($product, $viewer);
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

        $minLength = $this->minimumBodyLength($tenantId);
        $allowGuest = $this->allowGuestReviews($tenantId);
        $recentOrderCandidates = $viewer ? $this->recentOrderCandidates($viewer, $product) : [];
        $viewerEligibility = $this->viewerEligibilityPayload($viewer, $product, $recentOrderCandidates, $tenantId);
        $task = $this->storefrontTaskPayload($tenantId);

        return [
            'product' => [
                'id' => (string) $product['product_id'],
                'variant_id' => $this->nullableString($product['variant_id'] ?? null),
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
            'task' => $task,
            'settings' => [
                'enabled' => $this->reviewsEnabled($tenantId),
                'allow_guest' => $allowGuest,
                'moderation_enabled' => $this->moderationEnabled($tenantId),
                'minimum_length' => $minLength,
                'notification_email' => $this->notificationEmail($tenantId),
                'reward_requires_order_match' => $this->requireOrderMatchForReward($tenantId),
                'reward_amount_cents' => (int) ($task['reward_amount_cents'] ?? 0),
                'reward_dedupe_mode' => $this->rewardDedupeMode($tenantId),
                'publication_mode' => $this->moderationEnabled($tenantId) ? 'pending_moderation' : 'auto_publish',
            ],
            'viewer' => [
                'profile_id' => $viewer?->id,
                'state' => $viewerReview
                    ? ($viewerReview->status === 'approved' ? 'reviewed' : 'pending')
                    : ($viewer ? 'ready' : ($allowGuest ? 'guest_ready' : 'login_required')),
                'can_submit' => $viewer !== null || $allowGuest,
                'eligibility' => $viewerEligibility,
                'recent_order_candidates' => $recentOrderCandidates,
                'review' => $viewerReview ? $this->reviewPayload($viewerReview) : null,
            ],
            'sort_options' => [
                ['value' => 'most_relevant', 'label' => 'Most Relevant'],
                ['value' => 'newest', 'label' => 'Newest'],
                ['value' => 'highest_rating', 'label' => 'Highest Rating'],
                ['value' => 'lowest_rating', 'label' => 'Lowest Rating'],
            ],
            'reviews' => $reviews->map(fn (MarketingReviewHistory $review): array => $this->reviewPayload($review))->all(),
        ];
    }

    /**
     * @param array{store_key?:?string,tenant_id?:?int,limit?:mixed,sort?:?string} $context
     * @return array<string,mixed>
     */
    public function sitewideStorefrontPayload(array $context = [], ?MarketingProfile $viewer = null): array
    {
        $storeKey = $this->nullableString($context['store_key'] ?? null);
        $tenantId = $this->tenantIdForProduct([
            'tenant_id' => $context['tenant_id'] ?? null,
        ], $viewer);
        $limit = max(1, min(50, (int) ($context['limit'] ?? 24)));
        $sort = $this->normalizedSitewideSort($context['sort'] ?? null);

        $approvedQuery = $this->approvedSitewideReviewsQuery($storeKey, $tenantId);
        $approvedForAverage = clone $approvedQuery;
        $approvedForCount = clone $approvedQuery;
        $orderedQuery = $this->orderedSitewideReviewsQuery(clone $approvedQuery, $sort);

        $reviews = $orderedQuery
            ->with('profile:id,first_name,last_name,email')
            ->limit($limit + 1)
            ->get();

        $hasMore = $reviews->count() > $limit;
        $visibleReviews = $hasMore ? $reviews->take($limit)->values() : $reviews->values();
        $reviewCount = (int) $approvedForCount->count();
        $averageRating = round((float) ($approvedForAverage->avg('rating') ?? 0), 1);

        return [
            'summary' => [
                'average_rating' => $averageRating,
                'review_count' => $reviewCount,
                'rating_label' => $reviewCount > 0
                    ? number_format($averageRating, 1) . ' out of 5'
                    : 'No reviews yet',
            ],
            'viewer' => [
                'profile_id' => $viewer?->id,
                'state' => $viewer ? 'linked_customer' : 'guest_ready',
            ],
            'sort_options' => [
                ['value' => 'most_recent', 'label' => 'Most Recent'],
                ['value' => 'highest_rating', 'label' => 'Highest Rating'],
                ['value' => 'lowest_rating', 'label' => 'Lowest Rating'],
            ],
            'current_sort' => $sort,
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'returned' => $visibleReviews->count(),
            ],
            'reviews' => $visibleReviews
                ->map(fn (MarketingReviewHistory $review): array => $this->reviewPayload($review))
                ->all(),
        ];
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,variant_id?:?string,store_key?:?string,tenant_id?:?int} $product
     * @param array{rating:int,title:?string,body:string,name:?string,email:?string,order_id?:mixed,order_line_id?:mixed,variant_id?:?string,media_assets?:mixed,request_key?:?string,source_surface?:?string} $payload
     * @return array<string,mixed>
     */
    public function submitReview(?MarketingProfile $viewer, array $product, array $payload): array
    {
        $tenantId = $this->tenantIdForProduct($product, $viewer);
        if (! $this->reviewsEnabled($tenantId)) {
            return [
                'ok' => false,
                'error' => 'reviews_disabled',
                'message' => 'Product reviews are not enabled for this storefront right now.',
            ];
        }

        $allowGuest = $this->allowGuestReviews($tenantId);
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
        $selectedOrderId = $this->positiveInt($payload['order_id'] ?? null);
        $selectedOrderLineId = $this->positiveInt($payload['order_line_id'] ?? null);
        $variantId = $this->nullableString($payload['variant_id'] ?? ($product['variant_id'] ?? null));
        $mediaAssets = $this->normalizeMediaAssets($payload['media_assets'] ?? null);

        if ($rating < 1 || $rating > 5) {
            return [
                'ok' => false,
                'error' => 'invalid_rating',
                'message' => 'A star rating is required.',
            ];
        }

        if (mb_strlen($body) < $this->minimumBodyLength($tenantId)) {
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

        Log::info('native product review submission received', [
            'marketing_profile_id' => $viewer?->id,
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'product_id' => (string) $product['product_id'],
            'product_handle' => $product['product_handle'] ?? null,
            'variant_id' => $variantId,
            'order_id' => $selectedOrderId,
            'order_line_id' => $selectedOrderLineId,
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
                    'variant_id' => $variantId,
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

        $rewardState = $this->resolveRewardState($profile, $product, [
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'order_id' => $selectedOrderId,
            'order_line_id' => $selectedOrderLineId,
            'reviewer_email' => $reviewerEmail,
            'variant_id' => $variantId,
        ]);

        $externalReviewId = $this->externalReviewId(
            $product,
            $profile,
            $normalizedEmail,
            $rewardState['review_scope_key'] ?? null
        );
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

        $moderationEnabled = $this->moderationEnabled($tenantId);
        $status = $moderationEnabled ? 'pending' : 'approved';
        $now = now();
        try {
            [$review, $created] = $this->persistReview($reviewLookup, [
                'marketing_profile_id' => $profile?->id,
                'tenant_id' => $tenantId,
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
                'is_verified_buyer' => (bool) ($rewardState['verified_buyer'] ?? false),
                'product_id' => (string) $product['product_id'],
                'order_id' => $rewardState['order']?->id,
                'order_line_id' => $rewardState['order_line']?->id,
                'variant_id' => $variantId ?: ($rewardState['order_line']?->shopify_variant_id ? (string) $rewardState['order_line']?->shopify_variant_id : null),
                'product_handle' => $this->nullableString($product['product_handle'] ?? null),
                'product_url' => $this->canonicalProductUrl($product),
                'product_title' => $this->nullableString($product['product_title'] ?? null),
                'published_at' => ! $moderationEnabled ? $now : null,
                'submitted_at' => $existing?->submitted_at ?: $now,
                'reviewed_at' => ! $moderationEnabled ? $now : null,
                'approved_at' => ! $moderationEnabled ? $now : null,
                'rejected_at' => null,
                'moderated_by' => null,
                'moderation_notes' => null,
                'reward_eligibility_status' => (string) ($rewardState['status'] ?? 'unknown'),
                'reward_award_status' => $profile && (bool) ($rewardState['eligible_for_reward'] ?? false) ? 'pending_award' : 'not_awarded',
                'reward_amount_cents' => (int) ($rewardState['reward_amount_cents'] ?? 0) ?: null,
                'reward_rule_key' => $this->nullableString($rewardState['reward_rule_key'] ?? null),
                'media_assets' => $mediaAssets !== [] ? $mediaAssets : null,
                'has_media' => $mediaAssets !== [],
                'media_count' => count($mediaAssets),
                'source_synced_at' => $now,
                'raw_payload' => [
                    'request_key' => $requestKey !== '' ? $requestKey : null,
                    'source_surface' => $sourceSurface,
                    'submitted_via' => 'storefront',
                    'selected_order_id' => $selectedOrderId,
                    'selected_order_line_id' => $selectedOrderLineId,
                    'reward_state' => [
                        'status' => $rewardState['status'] ?? null,
                        'eligible_for_reward' => (bool) ($rewardState['eligible_for_reward'] ?? false),
                        'verified_buyer' => (bool) ($rewardState['verified_buyer'] ?? false),
                    ],
                    'media_assets' => $mediaAssets,
                ],
            ]);

            $award = null;
            if ($profile && (bool) ($rewardState['eligible_for_reward'] ?? false)) {
                $award = $this->verificationService->awardProductReview($profile, (string) $review->external_review_id, [
                    'product_id' => (string) $product['product_id'],
                    'product_handle' => $product['product_handle'] ?? null,
                    'product_title' => $product['product_title'] ?? null,
                    'review_source' => 'backstage_native',
                    'order_id' => $rewardState['order']?->id,
                    'order_line_id' => $rewardState['order_line']?->id,
                    'variant_id' => $variantId,
                    'reward_amount_cents' => (int) ($rewardState['reward_amount_cents'] ?? 0),
                ]);

                $completion = $award['completion'] ?? null;
                $event = $award['event'] ?? null;
                $review->forceFill([
                    'candle_cash_task_event_id' => $event?->id ?: $review->candle_cash_task_event_id,
                    'candle_cash_task_completion_id' => $completion?->id ?: $review->candle_cash_task_completion_id,
                    'reward_award_status' => (string) ($award['state'] ?? 'not_awarded'),
                ])->save();
            } elseif ($profile) {
                $review->forceFill([
                    'reward_award_status' => 'not_awarded',
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

        $this->syncProductReviewMetafields($review);

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
            'award' => [
                'state' => $award['state'] ?? ($profile && (bool) ($rewardState['eligible_for_reward'] ?? false) ? 'not_awarded' : 'ineligible'),
                'event' => $award['event'] ?? null,
                'completion' => $award['completion'] ?? null,
                'eligible' => (bool) ($rewardState['eligible_for_reward'] ?? false),
                'eligibility_status' => (string) ($rewardState['status'] ?? 'unknown'),
                'reward_amount_cents' => (int) ($rewardState['reward_amount_cents'] ?? 0),
                'reward_amount' => number_format(((int) ($rewardState['reward_amount_cents'] ?? 0)) / 100, 2, '.', ''),
                'message' => (string) ($rewardState['message'] ?? ''),
            ],
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

        $this->syncProductReviewMetafields($review);

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
            'published_at' => now(),
            'rejected_at' => null,
            'reviewed_at' => now(),
            'moderated_by' => $moderatorId,
            'moderation_notes' => $note ?: $review->moderation_notes,
        ])->save();

        $review = $review->fresh(['profile']);
        $this->syncProductReviewMetafields($review);

        return $review;
    }

    public function respondToReview(MarketingReviewHistory $review, string $response, User $user): MarketingReviewHistory
    {
        $now = now();
        $isFirst = blank($review->admin_response);

        $review->forceFill([
            'admin_response' => $this->sanitizeBody($response),
            'admin_response_by' => $review->admin_response_by ?: $user->id,
            'admin_response_created_at' => $isFirst
                ? $now
                : ($review->admin_response_created_at ?: $now),
            'admin_response_updated_at' => $isFirst ? null : $now,
        ])->save();

        return $review->fresh(['profile', 'adminResponder']);
    }

    /**
     * @param array{title:?string,body:?string,rating:?int} $payload
     */
    public function updateReviewContent(MarketingReviewHistory $review, array $payload, User $user): MarketingReviewHistory
    {
        $title = $this->sanitizeLine($payload['title'] ?? null);
        $body = $this->sanitizeBody((string) ($payload['body'] ?? ''));
        $rating = max(1, min(5, (int) ($payload['rating'] ?? $review->rating)));

        $review->forceFill([
            'title' => $title,
            'body' => $body,
            'rating' => $rating,
            'moderated_by' => $user->id,
            'reviewed_at' => $review->reviewed_at ?: now(),
        ])->save();

        $review = $review->fresh(['profile']);
        $this->syncProductReviewMetafields($review);

        return $review;
    }

    public function reject(MarketingReviewHistory $review, ?int $moderatorId = null, ?string $note = null): MarketingReviewHistory
    {
        $review->forceFill([
            'status' => 'rejected',
            'is_published' => false,
            'rejected_at' => now(),
            'published_at' => null,
            'reviewed_at' => now(),
            'moderated_by' => $moderatorId,
            'moderation_notes' => $note ?: $review->moderation_notes,
        ])->save();

        $review = $review->fresh(['profile']);
        $this->syncProductReviewMetafields($review);

        return $review;
    }

    public function delete(MarketingReviewHistory $review): void
    {
        $review->delete();
        $this->syncProductReviewMetafields($review);
    }

    public function moderationEnabled(?int $tenantId = null): bool
    {
        return (bool) data_get($this->integrationConfig($tenantId), 'product_review_moderation_enabled', false);
    }

    public function allowGuestReviews(?int $tenantId = null): bool
    {
        return (bool) data_get($this->integrationConfig($tenantId), 'product_review_allow_guest', true);
    }

    public function minimumBodyLength(?int $tenantId = null): int
    {
        return max(12, (int) data_get($this->integrationConfig($tenantId), 'product_review_min_length', 24));
    }

    public function notificationEmail(?int $tenantId = null): ?string
    {
        return $this->nullableString(data_get($this->integrationConfig($tenantId), 'product_review_notification_email', 'info@theforestrystudio.com'));
    }

    protected function integrationConfig(?int $tenantId = null): array
    {
        return $this->taskService->integrationConfig($tenantId);
    }

    /**
     * @return array{enabled:bool,reward_amount:string,reward_amount_cents:int,button_text:string}
     */
    protected function storefrontTaskPayload(?int $tenantId = null): array
    {
        $rewardAmountCents = $this->rewardAmountCents($tenantId);

        return [
            'enabled' => $this->reviewsEnabled($tenantId),
            'reward_amount' => number_format($rewardAmountCents / 100, 2, '.', ''),
            'reward_amount_cents' => $rewardAmountCents,
            // The native storefront always opens the owned review flow.
            'button_text' => 'Write a review',
        ];
    }

    protected function reviewsEnabled(?int $tenantId = null): bool
    {
        return (bool) data_get(
            $this->integrationConfig($tenantId),
            'reviews_enabled',
            data_get($this->integrationConfig($tenantId), 'product_review_enabled', true)
        );
    }

    protected function rewardAmountCents(?int $tenantId = null): int
    {
        $configured = (int) data_get($this->integrationConfig($tenantId), 'product_review_reward_amount_cents', 0);
        if ($configured > 0) {
            return $configured;
        }

        $task = $this->taskService->taskByHandle('product-review');

        return $task ? max(0, (int) round(((float) $task->reward_amount) * 100)) : 0;
    }

    protected function requireOrderMatchForReward(?int $tenantId = null): bool
    {
        return (bool) data_get($this->integrationConfig($tenantId), 'product_review_require_order_match', true);
    }

    protected function rewardDedupeMode(?int $tenantId = null): string
    {
        $mode = strtolower(trim((string) data_get($this->integrationConfig($tenantId), 'product_review_reward_dedupe_mode', 'order_line')));

        return in_array($mode, ['order_line', 'customer_product'], true) ? $mode : 'order_line';
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,variant_id?:?string,store_key?:?string,tenant_id?:?int} $product
     * @param array<int,array<string,mixed>> $recentOrderCandidates
     * @return array<string,mixed>
     */
    protected function viewerEligibilityPayload(?MarketingProfile $viewer, array $product, array $recentOrderCandidates, ?int $tenantId): array
    {
        if (! $viewer) {
            return [
                'eligible_for_review' => $this->allowGuestReviews($tenantId),
                'eligible_for_reward' => false,
                'status' => $this->allowGuestReviews($tenantId) ? 'guest_review_ready' : 'login_required',
                'message' => $this->allowGuestReviews($tenantId)
                    ? 'Guest reviews are allowed. Rewards are issued after a verified order match.'
                    : 'Sign in before leaving a product review.',
                'selected_order_candidate' => null,
            ];
        }

        $selected = collect($recentOrderCandidates)
            ->first(fn (array $candidate): bool => (bool) ($candidate['matches_current_product'] ?? false));

        return [
            'eligible_for_review' => true,
            'eligible_for_reward' => $selected !== null || ! $this->requireOrderMatchForReward($tenantId),
            'status' => $selected !== null ? 'eligible_verified_purchase' : 'no_recent_order_match',
            'message' => $selected !== null
                ? 'Reward credit is available after we verify this review against your recent order.'
                : 'You can still submit a review even if we do not find a matching recent order.',
            'selected_order_candidate' => $selected,
        ];
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,variant_id?:?string,store_key?:?string,tenant_id?:?int} $product
     * @return array<int,array<string,mixed>>
     */
    protected function recentOrderCandidates(MarketingProfile $profile, array $product = [], int $limit = 12): array
    {
        $storeKey = $this->nullableString($product['store_key'] ?? null);
        $tenantId = $this->tenantIdForProduct($product, $profile);

        $rows = $this->matchedOrderLineQuery($profile, $storeKey, $tenantId)
            ->limit(max(24, $limit * 4))
            ->get();

        return collect($rows)
            ->map(fn (OrderLine $row): array => $this->orderCandidatePayload($row, $product))
            ->unique(fn (array $candidate): string => (string) ($candidate['candidate_key'] ?? ''))
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,variant_id?:?string,store_key?:?string,tenant_id?:?int} $product
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    protected function resolveRewardState(?MarketingProfile $profile, array $product, array $payload): array
    {
        $tenantId = $this->tenantIdForProduct($product, $profile);
        $rewardAmountCents = $this->rewardAmountCents($tenantId);
        $selectedOrderId = $this->positiveInt($payload['order_id'] ?? null);
        $selectedOrderLineId = $this->positiveInt($payload['order_line_id'] ?? null);
        $requireOrderMatch = $this->requireOrderMatchForReward($tenantId);

        $base = [
            'eligible_for_reward' => false,
            'verified_buyer' => false,
            'status' => 'ineligible',
            'message' => 'This review does not qualify for reward credit.',
            'reward_amount_cents' => $rewardAmountCents,
            'reward_rule_key' => null,
            'review_scope_key' => null,
            'order' => null,
            'order_line' => null,
        ];

        if (! $profile) {
            return [
                ...$base,
                'status' => 'guest_submitted',
                'message' => 'Guest reviews are accepted, but reward credit requires a matched customer order.',
            ];
        }

        $storeKey = $this->nullableString($payload['store_key'] ?? ($product['store_key'] ?? null));
        $tenantScope = $this->tenantIdForProduct($product, $profile);
        $candidateRows = $this->matchedOrderLineQuery($profile, $storeKey, $tenantScope, product: $product)
            ->limit(40)
            ->get();

        $candidate = collect($candidateRows)->first(function (OrderLine $row) use ($selectedOrderId, $selectedOrderLineId): bool {
            if ($selectedOrderLineId !== null) {
                return (int) $row->id === $selectedOrderLineId;
            }

            if ($selectedOrderId !== null) {
                return (int) $row->order_id === $selectedOrderId;
            }

            return true;
        });

        if (! $candidate && $requireOrderMatch) {
            return [
                ...$base,
                'status' => $selectedOrderLineId !== null || $selectedOrderId !== null ? 'selected_order_not_eligible' : 'no_order_match',
                'message' => 'We could not match that review to a fulfilled or completed order for this product.',
            ];
        }

        $rewardRuleKey = $candidate instanceof OrderLine
            ? $this->rewardRuleKeyForOrderLine($candidate, $profile, $tenantId)
            : $this->rewardRuleKeyForCustomerProduct($profile, $product, $tenantId);

        if ($this->rewardAlreadyUsed($profile, $product, $candidate, $tenantId)) {
            return [
                ...$base,
                'status' => 'reward_already_awarded',
                'message' => 'Reward credit has already been issued for this review rule.',
                'reward_rule_key' => $rewardRuleKey,
                'review_scope_key' => $candidate instanceof OrderLine ? 'order-line:' . $candidate->id : 'customer-product',
                'order' => $candidate?->relationLoaded('order') ? $candidate->order : $candidate?->order()->first(),
                'order_line' => $candidate,
            ];
        }

        if ($candidate instanceof OrderLine) {
            $order = $candidate->relationLoaded('order') ? $candidate->order : $candidate->order()->first();

            return [
                ...$base,
                'eligible_for_reward' => $rewardAmountCents > 0,
                'verified_buyer' => true,
                'status' => $rewardAmountCents > 0 ? 'eligible_verified_purchase' : 'reward_disabled',
                'message' => $rewardAmountCents > 0
                    ? 'Reward credit will be issued after the verified order match passes our checks.'
                    : 'This review is verified, but reward credit is currently disabled.',
                'reward_rule_key' => $rewardRuleKey,
                'review_scope_key' => 'order-line:' . $candidate->id,
                'order' => $order,
                'order_line' => $candidate,
            ];
        }

        return [
            ...$base,
            'eligible_for_reward' => ! $requireOrderMatch && $rewardAmountCents > 0,
            'verified_buyer' => false,
            'status' => ! $requireOrderMatch && $rewardAmountCents > 0 ? 'eligible_without_order_match' : 'reward_disabled',
            'message' => ! $requireOrderMatch && $rewardAmountCents > 0
                ? 'Reward credit is allowed without a matched order for this tenant.'
                : 'This review does not currently qualify for reward credit.',
            'reward_rule_key' => $rewardRuleKey,
            'review_scope_key' => 'customer-product',
        ];
    }

    protected function matchedOrderLineQuery(
        MarketingProfile $profile,
        ?string $storeKey,
        ?int $tenantId,
        bool $productScoped = false,
        array $product = []
    ) {
        $customerIds = $this->profileShopifyCustomerIds($profile, $storeKey);
        $emails = $this->profileEmails($profile);

        $query = OrderLine::query()
            ->select([
                'order_lines.*',
                'orders.shopify_store_key as review_order_store_key',
                'orders.shopify_order_id as review_order_external_id',
                'orders.status as review_order_status',
                'orders.ordered_at as review_ordered_at',
                'orders.shopify_customer_id as review_order_customer_id',
            ])
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->when($tenantId !== null, fn ($builder) => $builder->where('orders.tenant_id', $tenantId))
            ->when($storeKey !== null, fn ($builder) => $builder->where('orders.shopify_store_key', $storeKey))
            ->whereIn('orders.status', $this->eligibleOrderStatuses())
            ->where(function ($builder) use ($customerIds, $emails): void {
                $applied = false;

                if ($customerIds !== []) {
                    $builder->whereIn('orders.shopify_customer_id', $customerIds);
                    $applied = true;
                }

                foreach (['orders.customer_email', 'orders.email', 'orders.shipping_email', 'orders.billing_email'] as $column) {
                    if ($emails === []) {
                        continue;
                    }

                    if ($applied) {
                        $builder->orWhereIn($column, $emails);
                    } else {
                        $builder->whereIn($column, $emails);
                        $applied = true;
                    }
                }

                if (! $applied) {
                    $builder->whereRaw('1 = 0');
                }
            })
            ->orderByDesc('orders.ordered_at')
            ->orderByDesc('orders.id')
            ->orderByDesc('order_lines.id');

        if ($productScoped) {
            $this->applyProductMatchToOrderLineQuery($query, $product);
        }

        return $query;
    }

    protected function applyProductMatchToOrderLineQuery($query, array $product): void
    {
        $productId = $this->nullableString($product['product_id'] ?? null);
        $variantId = $this->nullableString($product['variant_id'] ?? null);
        $productTitle = $this->nullableString($product['product_title'] ?? null);
        $productHandle = $this->nullableString($product['product_handle'] ?? null);
        $numericProductId = $productId !== null && ctype_digit($productId) ? (int) $productId : null;
        $numericVariantId = $variantId !== null && ctype_digit($variantId) ? (int) $variantId : null;
        $handleTitleGuess = $productHandle ? trim(str_replace(['-', '_'], ' ', strtolower($productHandle))) : null;

        $query->where(function ($builder) use ($numericProductId, $numericVariantId, $productTitle, $handleTitleGuess): void {
            $applied = false;

            if ($numericProductId !== null) {
                $builder->where('order_lines.shopify_product_id', $numericProductId);
                $applied = true;
            }

            if ($numericVariantId !== null) {
                if ($applied) {
                    $builder->orWhere('order_lines.shopify_variant_id', $numericVariantId);
                } else {
                    $builder->where('order_lines.shopify_variant_id', $numericVariantId);
                    $applied = true;
                }
            }

            if ($productTitle !== null) {
                if ($applied) {
                    $builder->orWhereRaw('lower(order_lines.raw_title) = ?', [mb_strtolower($productTitle)]);
                } else {
                    $builder->whereRaw('lower(order_lines.raw_title) = ?', [mb_strtolower($productTitle)]);
                    $applied = true;
                }
            }

            if ($handleTitleGuess !== null) {
                $pattern = '%' . preg_replace('/\s+/', '%', $handleTitleGuess) . '%';
                if ($applied) {
                    $builder->orWhereRaw('lower(order_lines.raw_title) like ?', [$pattern]);
                } else {
                    $builder->whereRaw('lower(order_lines.raw_title) like ?', [$pattern]);
                }
            }
        });
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,variant_id?:?string,store_key?:?string,tenant_id?:?int} $product
     * @return array<string,mixed>
     */
    protected function orderCandidatePayload(OrderLine $line, array $product = []): array
    {
        $orderedAt = $line->getAttribute('review_ordered_at');
        $status = $this->nullableString((string) $line->getAttribute('review_order_status'));
        $productId = $line->shopify_product_id ? (string) $line->shopify_product_id : null;
        $variantId = $line->shopify_variant_id ? (string) $line->shopify_variant_id : null;
        $title = $this->nullableString($line->raw_title);

        return [
            'candidate_key' => 'order-line:' . $line->id,
            'order_id' => (int) $line->order_id,
            'order_line_id' => (int) $line->id,
            'order_external_id' => $this->nullableString((string) $line->getAttribute('review_order_external_id')),
            'ordered_at' => $orderedAt instanceof CarbonInterface ? $orderedAt->toIso8601String() : $this->nullableString((string) $orderedAt),
            'order_status' => $status,
            'store_key' => $this->nullableString((string) $line->getAttribute('review_order_store_key')),
            'product_id' => $productId,
            'variant_id' => $variantId,
            'product_title' => $title,
            'product_handle' => $this->nullableString($product['product_handle'] ?? null),
            'product_url' => $this->canonicalProductUrl([
                'product_handle' => $product['product_handle'] ?? null,
                'product_url' => $product['product_url'] ?? null,
            ]),
            'matches_current_product' => $this->orderLineMatchesProduct($line, $product),
        ];
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,variant_id?:?string,store_key?:?string,tenant_id?:?int} $product
     */
    protected function orderLineMatchesProduct(OrderLine $line, array $product): bool
    {
        $productId = $this->nullableString($product['product_id'] ?? null);
        $variantId = $this->nullableString($product['variant_id'] ?? null);
        $productTitle = $this->nullableString($product['product_title'] ?? null);

        if ($productId !== null && $line->shopify_product_id !== null && (string) $line->shopify_product_id === $productId) {
            return true;
        }

        if ($variantId !== null && $line->shopify_variant_id !== null && (string) $line->shopify_variant_id === $variantId) {
            return true;
        }

        return $productTitle !== null
            && $this->normalizedName((string) $line->raw_title) === $this->normalizedName($productTitle);
    }

    /**
     * @return array<int,string>
     */
    protected function eligibleOrderStatuses(): array
    {
        return [
            'complete',
            'completed',
            'closed',
            'delivered',
            'fulfilled',
            'paid',
            'shipped',
        ];
    }

    /**
     * @return array<int,string>
     */
    protected function profileShopifyCustomerIds(MarketingProfile $profile, ?string $storeKey = null): array
    {
        return CustomerExternalProfile::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('integration', 'shopify_customer')
            ->when($storeKey !== null, fn ($query) => $query->where('store_key', $storeKey))
            ->pluck('external_customer_id')
            ->map(fn ($value): ?string => $this->nullableString((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    protected function profileEmails(MarketingProfile $profile): array
    {
        return collect([
            $this->nullableString($profile->normalized_email ?: $profile->email),
        ])
            ->merge(
                CustomerExternalProfile::query()
                    ->where('marketing_profile_id', $profile->id)
                    ->pluck('normalized_email')
            )
            ->map(fn ($value): ?string => $this->nullableString((string) $value))
            ->filter()
            ->map(fn (string $value): string => Str::lower($value))
            ->unique()
            ->values()
            ->all();
    }

    protected function rewardRuleKeyForOrderLine(OrderLine $line, MarketingProfile $profile, ?int $tenantId): string
    {
        return implode(':', array_filter([
            'tenant',
            $tenantId,
            'profile',
            $profile->id,
            $this->rewardDedupeMode($tenantId),
            'order-line',
            $line->id,
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,variant_id?:?string,store_key?:?string,tenant_id?:?int} $product
     */
    protected function rewardRuleKeyForCustomerProduct(MarketingProfile $profile, array $product, ?int $tenantId): string
    {
        return implode(':', array_filter([
            'tenant',
            $tenantId,
            'profile',
            $profile->id,
            'customer-product',
            $this->nullableString($product['store_key'] ?? null),
            (string) ($product['product_id'] ?? ''),
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,variant_id?:?string,store_key?:?string,tenant_id?:?int} $product
     */
    protected function rewardAlreadyUsed(MarketingProfile $profile, array $product, ?OrderLine $candidate, ?int $tenantId): bool
    {
        $query = $this->reviewLookupQuery([
            ...$product,
            'tenant_id' => $tenantId,
        ])->where('marketing_profile_id', $profile->id)
            ->where(function ($builder): void {
                $builder->whereNotNull('candle_cash_task_completion_id')
                    ->orWhereIn('reward_award_status', ['awarded', 'pending_award', 'pending', 'submitted']);
            });

        if ($candidate instanceof OrderLine && $this->rewardDedupeMode($tenantId) === 'order_line') {
            $query->where('order_line_id', $candidate->id);
        }

        return $query->exists();
    }

    /**
     * @param mixed $mediaAssets
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeMediaAssets(mixed $mediaAssets): array
    {
        $assets = is_array($mediaAssets) ? $mediaAssets : [];

        return collect($assets)
            ->map(function ($asset): ?array {
                if (is_string($asset)) {
                    $label = $this->nullableString($asset);

                    return $label ? ['label' => $label] : null;
                }

                if (! is_array($asset)) {
                    return null;
                }

                $url = $this->nullableString($asset['url'] ?? null);
                $label = $this->nullableString($asset['label'] ?? $asset['name'] ?? null);
                $contentType = $this->nullableString($asset['content_type'] ?? $asset['type'] ?? null);

                if ($url === null && $label === null) {
                    return null;
                }

                return array_filter([
                    'url' => $url,
                    'label' => $label,
                    'content_type' => $contentType,
                ], fn ($value) => $value !== null && $value !== '');
            })
            ->filter()
            ->values()
            ->take(5)
            ->all();
    }

    protected function tenantIdForProduct(array $product, ?MarketingProfile $profile = null): ?int
    {
        $tenantId = is_numeric($product['tenant_id'] ?? null) && (int) ($product['tenant_id'] ?? 0) > 0
            ? (int) $product['tenant_id']
            : null;

        if ($tenantId !== null) {
            return $tenantId;
        }

        $profileTenantId = (int) ($profile?->tenant_id ?? 0);

        return $profileTenantId > 0 ? $profileTenantId : null;
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
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,variant_id?:?string,store_key?:?string,tenant_id?:?int} $product
     */
    protected function approvedReviewsQuery(array $product)
    {
        return $this->reviewLookupQuery($product)->where('status', 'approved')->where('is_published', true);
    }

    protected function approvedSitewideReviewsQuery(?string $storeKey = null, ?int $tenantId = null)
    {
        return MarketingReviewHistory::query()
            ->when($tenantId !== null, fn ($query) => $query->where(function ($builder) use ($tenantId): void {
                $builder->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            }))
            ->when($storeKey !== null, fn ($query) => $query->where('store_key', $storeKey))
            ->where('status', 'approved')
            ->where('is_published', true);
    }

    protected function orderedSitewideReviewsQuery($query, string $sort)
    {
        return match ($this->normalizedSitewideSort($sort)) {
            'highest_rating' => $query
                ->orderByDesc('rating')
                ->orderByDesc('approved_at')
                ->orderByDesc('reviewed_at')
                ->orderByDesc('id'),
            'lowest_rating' => $query
                ->orderBy('rating')
                ->orderByDesc('approved_at')
                ->orderByDesc('reviewed_at')
                ->orderByDesc('id'),
            default => $query
                ->orderByDesc('approved_at')
                ->orderByDesc('reviewed_at')
                ->orderByDesc('published_at')
                ->orderByDesc('submitted_at')
                ->orderByDesc('id'),
        };
    }

    /**
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,variant_id?:?string,store_key?:?string,tenant_id?:?int} $product
     */
    protected function reviewLookupQuery(array $product)
    {
        $storeKey = $this->nullableString($product['store_key'] ?? null);
        $productId = trim((string) ($product['product_id'] ?? ''));
        $productHandle = $this->nullableString($product['product_handle'] ?? null);
        $tenantId = $this->tenantIdForProduct($product);

        return MarketingReviewHistory::query()
            ->when($tenantId !== null, fn ($query) => $query->where(function ($builder) use ($tenantId): void {
                $builder->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            }))
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
        $rawProductId = $this->nullableString(data_get($review->raw_payload, 'product.id'))
            ?: $this->nullableString(data_get($review->raw_payload, 'product.shopifyProductId'))
            ?: $this->nullableString(data_get($review->raw_payload, 'product.productId'));
        $rawProductHandle = $this->nullableString(data_get($review->raw_payload, 'product.handle'));
        $rawProductTitle = $this->nullableString(data_get($review->raw_payload, 'product.title'));
        $rawProductUrl = $this->nullableString(data_get($review->raw_payload, 'product.url'));
        $productId = $review->product_id ? (string) $review->product_id : $rawProductId;
        $productHandle = $review->product_handle ? (string) $review->product_handle : $rawProductHandle;
        $productTitle = $review->product_title ? (string) $review->product_title : $rawProductTitle;
        $productUrl = $review->product_url ? (string) $review->product_url : $this->canonicalProductUrl([
            'product_id' => $productId,
            'product_handle' => $productHandle,
            'product_title' => $productTitle,
            'product_url' => $rawProductUrl,
            'store_key' => $review->store_key,
        ]);

        return [
            'id' => (int) $review->id,
            'rating' => (int) $review->rating,
            'title' => $review->title ? (string) $review->title : null,
            'body' => $review->body ? (string) $review->body : null,
            'reviewer_name' => $review->displayReviewerName(),
            'status' => (string) ($review->status ?: 'approved'),
            'submitted_at' => optional($review->submitted_at ?: $review->created_at)->toIso8601String(),
            'approved_at' => optional($review->approved_at ?: $review->reviewed_at)->toIso8601String(),
            'published_at' => optional($review->published_at)->toIso8601String(),
            'product_id' => $productId,
            'order_id' => $review->order_id ? (int) $review->order_id : null,
            'order_line_id' => $review->order_line_id ? (int) $review->order_line_id : null,
            'variant_id' => $review->variant_id ? (string) $review->variant_id : null,
            'product_handle' => $productHandle,
            'product_title' => $productTitle,
            'product_url' => $productUrl,
            'is_verified_buyer' => (bool) $review->is_verified_buyer,
            'verified_purchase' => (bool) $review->is_verified_buyer,
            'media_assets' => is_array($review->media_assets) ? $review->media_assets : [],
            'helpful_count' => (int) ($review->votes ?? 0),
            'publication_state' => (string) ($review->status ?: 'approved'),
            'admin_response' => $review->status === 'approved' && filled($review->admin_response)
                ? [
                    'body' => (string) $review->admin_response,
                    'responded_at' => optional($review->admin_response_created_at)->toIso8601String(),
                ]
                : null,
            'reward' => [
                'eligibility_status' => $review->reward_eligibility_status ? (string) $review->reward_eligibility_status : null,
                'award_status' => $review->reward_award_status ? (string) $review->reward_award_status : null,
                'amount_cents' => $review->reward_amount_cents ? (int) $review->reward_amount_cents : 0,
                'amount' => number_format(((int) ($review->reward_amount_cents ?? 0)) / 100, 2, '.', ''),
            ],
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
     * @param array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,variant_id?:?string,store_key?:?string,tenant_id?:?int} $product
     */
    protected function externalReviewId(array $product, ?MarketingProfile $profile, ?string $normalizedEmail, ?string $scopeKey = null): string
    {
        $identity = $profile?->id ? 'profile:' . $profile->id : 'email:' . sha1((string) $normalizedEmail);
        $storeKey = $this->nullableString($product['store_key'] ?? null) ?: 'unknown_store';

        return 'native:' . sha1(implode('|', [
            $storeKey,
            (string) $product['product_id'],
            (string) ($product['product_handle'] ?? ''),
            (string) ($scopeKey ?? 'customer-product'),
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

    protected function syncProductReviewMetafields(MarketingReviewHistory $review): void
    {
        try {
            $this->shopifyProductReviewMetafieldService->syncReview($review);
        } catch (\Throwable $e) {
            Log::warning('native product review metafield sync failed', [
                'review_id' => $review->id,
                'store_key' => $review->store_key,
                'product_id' => $review->product_id,
                'product_handle' => $review->product_handle,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function normalizedSitewideSort(?string $value): string
    {
        return match ($this->nullableString($value)) {
            'highest_rating' => 'highest_rating',
            'lowest_rating' => 'lowest_rating',
            default => 'most_recent',
        };
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
        return $this->recentOrderCandidates($profile) !== [];
    }

    protected function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

}
