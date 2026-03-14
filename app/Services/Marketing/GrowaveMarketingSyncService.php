<?php

namespace App\Services\Marketing;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingReviewHistory;
use App\Models\MarketingReviewSummary;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GrowaveMarketingSyncService
{
    public function __construct(
        protected GrowaveClient $client,
        protected MarketingIdentityNormalizer $normalizer
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function sync(array $options = []): array
    {
        if (! (bool) config('marketing.growave.enabled', false)) {
            return [
                'status' => 'skipped',
                'reason' => 'growave_sync_disabled',
                'summary' => [],
                'run_id' => null,
            ];
        }

        $store = $this->nullableString($options['store'] ?? null);
        $limit = $this->nullableInt($options['limit'] ?? null);
        $afterCandidateId = $this->nullableInt($options['after_candidate_id'] ?? null);
        $checkpointEvery = max(1, (int) ($options['checkpoint_every'] ?? 100));
        $onlyMissing = (bool) ($options['only_missing'] ?? false);
        $reviewsPerPage = min(max(1, (int) ($options['reviews_per_page'] ?? 50)), 50);
        $activitiesPerPage = min(max(1, (int) ($options['activities_per_page'] ?? 100)), 250);
        $maxReviewPages = max(1, (int) ($options['max_review_pages'] ?? 20));
        $maxActivityPages = max(1, (int) ($options['max_activity_pages'] ?? 20));
        $candidateDelayMs = max(0, (int) ($options['candidate_delay_ms'] ?? config('marketing.growave.candidate_delay_ms', 50)));
        $pageDelayMs = max(0, (int) ($options['page_delay_ms'] ?? config('marketing.growave.page_delay_ms', 150)));

        $this->client->configureRuntime(array_filter([
            'retry_attempts' => $this->nullableInt($options['retry_attempts'] ?? null),
            'request_min_interval_ms' => $this->nullableInt($options['request_min_interval_ms'] ?? null),
            'request_jitter_ms' => $this->nullableInt($options['request_jitter_ms'] ?? null),
            'backoff_base_ms' => $this->nullableInt($options['backoff_base_ms'] ?? null),
            'backoff_max_ms' => $this->nullableInt($options['backoff_max_ms'] ?? null),
        ], static fn (mixed $value): bool => $value !== null));

        $summary = [
            'processed' => 0,
            'growave_found' => 0,
            'growave_not_found' => 0,
            'profiles_resolved' => 0,
            'profiles_unresolved' => 0,
            'external_created' => 0,
            'external_updated' => 0,
            'review_summaries_created' => 0,
            'review_summaries_updated' => 0,
            'review_rows_created' => 0,
            'review_rows_updated' => 0,
            'activity_rows_created' => 0,
            'activity_rows_skipped_existing' => 0,
            'activity_rows_skipped_no_profile' => 0,
            'candle_balance_delta' => 0,
            'errors' => 0,
        ];

        $run = MarketingImportRun::query()->create([
            'type' => 'growave_customer_sync',
            'status' => 'running',
            'source_label' => $store !== null ? 'growave:' . $store : 'growave:all',
            'started_at' => now(),
            'summary' => [
                'store' => $store,
                'limit' => $limit,
                'after_candidate_id' => $afterCandidateId,
                'checkpoint_every' => $checkpointEvery,
                'only_missing' => $onlyMissing,
                'reviews_per_page' => $reviewsPerPage,
                'activities_per_page' => $activitiesPerPage,
                'max_review_pages' => $maxReviewPages,
                'max_activity_pages' => $maxActivityPages,
                'candidate_delay_ms' => $candidateDelayMs,
                'page_delay_ms' => $pageDelayMs,
                'checkpoint' => [
                    'last_candidate_id' => $afterCandidateId,
                    'processed' => 0,
                    'updated_at' => now()->toDateTimeString(),
                ],
            ],
        ]);

        $lastCandidateId = $afterCandidateId;

        try {
            $candidateQuery = $this->candidateQuery($store, $afterCandidateId, $onlyMissing);
            if ($limit !== null) {
                $candidateQuery->limit($limit);
            }

            foreach ($candidateQuery->cursor() as $candidate) {
                $summary['processed']++;
                $lastCandidateId = (int) $candidate->id;

                try {
                    $this->syncCandidate(
                        $candidate,
                        $summary,
                        $reviewsPerPage,
                        $activitiesPerPage,
                        $maxReviewPages,
                        $maxActivityPages,
                        $pageDelayMs
                    );
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    Log::warning('growave customer sync candidate failed', [
                        'candidate_id' => $candidate->id,
                        'store_key' => $candidate->store_key,
                        'external_customer_id' => $candidate->external_customer_id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $this->sleepMilliseconds($candidateDelayMs);

                if ($summary['processed'] % $checkpointEvery === 0) {
                    $this->persistCheckpoint($run, $summary, $lastCandidateId);
                }
            }

            $this->persistCheckpoint($run, $summary, $lastCandidateId);
            $finalSummary = array_merge($summary, [
                'store' => $store,
                'limit' => $limit,
                'after_candidate_id' => $afterCandidateId,
                'checkpoint_every' => $checkpointEvery,
                'only_missing' => $onlyMissing,
                'checkpoint' => [
                    'last_candidate_id' => $lastCandidateId,
                    'processed' => (int) ($summary['processed'] ?? 0),
                    'errors' => (int) ($summary['errors'] ?? 0),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ]);
            $run->forceFill([
                'status' => $summary['errors'] > 0 ? 'partial' : 'completed',
                'finished_at' => now(),
                'summary' => $finalSummary,
            ])->save();

            return [
                'status' => (string) $run->status,
                'run_id' => (int) $run->id,
                'summary' => $finalSummary,
            ];
        } catch (\Throwable $e) {
            $summary['errors']++;
            $this->persistCheckpoint($run, $summary, $lastCandidateId);
            $failedSummary = array_merge($summary, [
                'store' => $store,
                'limit' => $limit,
                'after_candidate_id' => $afterCandidateId,
                'checkpoint_every' => $checkpointEvery,
                'only_missing' => $onlyMissing,
                'checkpoint' => [
                    'last_candidate_id' => $lastCandidateId,
                    'processed' => (int) ($summary['processed'] ?? 0),
                    'errors' => (int) ($summary['errors'] ?? 0),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ]);

            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'summary' => $failedSummary,
                'notes' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }

    /**
     * @param array<string,int> $summary
     */
    protected function syncCandidate(
        CustomerExternalProfile $candidate,
        array &$summary,
        int $reviewsPerPage,
        int $activitiesPerPage,
        int $maxReviewPages,
        int $maxActivityPages,
        int $pageDelayMs
    ): void {
        $lookup = $this->lookupCustomer($candidate);
        $customer = $lookup['customer'];
        $lookupIdentifier = $lookup['identifier'];

        if (! is_array($customer) || $lookupIdentifier === null) {
            $summary['growave_not_found']++;

            return;
        }

        $summary['growave_found']++;

        $storeKey = $this->nullableString($candidate->store_key);
        $customerId = $this->resolveCustomerId($customer, $candidate);
        $customerQueryIdentifier = $this->preferredCustomerQueryIdentifier($customer, $candidate, $lookupIdentifier);

        $profileId = $this->resolveMarketingProfileId($candidate, $customerId, $customer, $storeKey);
        if ($profileId !== null) {
            $summary['profiles_resolved']++;
        } else {
            $summary['profiles_unresolved']++;
        }

        $reviews = $this->fetchAllReviews(
            $customerQueryIdentifier,
            $reviewsPerPage,
            $maxReviewPages,
            $pageDelayMs
        );

        $activities = $this->fetchAllActivities(
            $customerQueryIdentifier,
            $activitiesPerPage,
            $maxActivityPages,
            $pageDelayMs
        );

        $reviewSummary = $this->buildReviewSummary($reviews);
        $referralCount = $this->referralCountFromActivities($activities['items']);

        $rawMetafields = $this->buildRawMetafields(
            customer: $customer,
            reviewSummary: $reviewSummary,
            referralCount: $referralCount,
            activitySummary: $activities['summary']
        );

        $externalAction = $this->upsertGrowaveExternalProfile(
            candidate: $candidate,
            customer: $customer,
            customerId: $customerId,
            marketingProfileId: $profileId,
            rawMetafields: $rawMetafields,
            storeKey: $storeKey
        );
        $summary[$externalAction]++;

        [$reviewSummaryAction, $reviewCounters] = $this->upsertReviewSummaryAndHistory(
            marketingProfileId: $profileId,
            storeKey: $storeKey,
            customerId: $customerId,
            customer: $customer,
            reviewSummary: $reviewSummary,
            reviews: $reviews
        );
        $summary[$reviewSummaryAction]++;
        $summary['review_rows_created'] += $reviewCounters['created'];
        $summary['review_rows_updated'] += $reviewCounters['updated'];

        $activityCounters = $this->applyActivitiesToCandleCash(
            marketingProfileId: $profileId,
            storeKey: $storeKey,
            customerId: $customerId,
            activities: $activities['items']
        );
        $summary['activity_rows_created'] += $activityCounters['created'];
        $summary['activity_rows_skipped_existing'] += $activityCounters['skipped_existing'];
        $summary['activity_rows_skipped_no_profile'] += $activityCounters['skipped_no_profile'];
        $summary['candle_balance_delta'] += $activityCounters['balance_delta'];
    }

    protected function candidateQuery(?string $store, ?int $afterCandidateId = null, bool $onlyMissing = false): Builder
    {
        return CustomerExternalProfile::query()
            ->where('provider', 'shopify')
            ->where('integration', 'shopify_customer')
            ->when($store !== null, fn (Builder $query) => $query->where('store_key', $store))
            ->when($afterCandidateId !== null, fn (Builder $query) => $query->where('id', '>', $afterCandidateId))
            ->when($onlyMissing, function (Builder $query): void {
                $query->whereNotExists(function ($subQuery): void {
                    $subQuery->selectRaw('1')
                        ->from('customer_external_profiles as growave_profiles')
                        ->whereColumn('growave_profiles.store_key', 'customer_external_profiles.store_key')
                        ->whereColumn('growave_profiles.external_customer_id', 'customer_external_profiles.external_customer_id')
                        ->where('growave_profiles.provider', 'shopify')
                        ->where('growave_profiles.integration', 'growave');
                });
            })
            ->orderBy('id');
    }

    /**
     * @param array<string,int> $summary
     */
    protected function persistCheckpoint(MarketingImportRun $run, array $summary, ?int $lastCandidateId): void
    {
        $existing = is_array($run->summary) ? $run->summary : [];

        $run->forceFill([
            'summary' => array_merge($existing, [
                'checkpoint' => [
                    'last_candidate_id' => $lastCandidateId,
                    'processed' => (int) ($summary['processed'] ?? 0),
                    'errors' => (int) ($summary['errors'] ?? 0),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ]),
        ])->save();
    }

    protected function preferredIdentifier(CustomerExternalProfile $candidate): ?string
    {
        return $this->nullableString($candidate->external_customer_id)
            ?: $this->nullableString($candidate->email)
            ?: $this->nullableString($candidate->phone);
    }

    /**
     * @return array{customer:?array,identifier:?string}
     */
    protected function lookupCustomer(CustomerExternalProfile $candidate): array
    {
        foreach ($this->candidateIdentifiers($candidate) as $identifier) {
            $customer = $this->client->getCustomer($identifier);
            if (is_array($customer)) {
                return [
                    'customer' => $customer,
                    'identifier' => $identifier,
                ];
            }
        }

        return [
            'customer' => null,
            'identifier' => null,
        ];
    }

    /**
     * @return array<int,string>
     */
    protected function candidateIdentifiers(CustomerExternalProfile $candidate): array
    {
        $values = [
            $this->nullableString($candidate->external_customer_id),
            $this->nullableString($candidate->email),
            $this->nullableString($candidate->normalized_email),
            $this->nullableString($candidate->phone),
            $this->nullableString($candidate->normalized_phone),
        ];

        $identifiers = [];
        foreach ($values as $value) {
            if ($value === null || in_array($value, $identifiers, true)) {
                continue;
            }

            $identifiers[] = $value;
        }

        return $identifiers;
    }

    protected function resolveCustomerId(array $customer, CustomerExternalProfile $candidate): string
    {
        $fromApi = $this->nullableString($customer['customerId'] ?? null);
        if ($fromApi !== null) {
            return $fromApi;
        }

        return $this->nullableString($candidate->external_customer_id)
            ?: ('candidate-' . $candidate->id);
    }

    protected function preferredCustomerQueryIdentifier(
        array $customer,
        CustomerExternalProfile $candidate,
        ?string $lookupIdentifier = null
    ): string
    {
        return $this->nullableString($customer['customerId'] ?? null)
            ?: $lookupIdentifier
            ?: $this->nullableString($customer['email'] ?? null)
            ?: $this->nullableString($customer['phone'] ?? null)
            ?: $this->preferredIdentifier($candidate)
            ?: ('candidate-' . $candidate->id);
    }

    protected function resolveMarketingProfileId(
        CustomerExternalProfile $candidate,
        string $customerId,
        array $customer,
        ?string $storeKey
    ): ?int {
        $existingProfileId = (int) ($candidate->marketing_profile_id ?? 0);
        if ($existingProfileId > 0) {
            return $existingProfileId;
        }

        $sourceId = $storeKey !== null ? $storeKey . ':' . $customerId : $customerId;
        $linkedProfileId = MarketingProfileLink::query()
            ->where('source_type', 'shopify_customer')
            ->where('source_id', $sourceId)
            ->value('marketing_profile_id');

        if (is_numeric($linkedProfileId) && (int) $linkedProfileId > 0) {
            return (int) $linkedProfileId;
        }

        $normalizedEmail = $this->normalizer->normalizeEmail($this->nullableString($customer['email'] ?? null));
        if ($normalizedEmail !== null) {
            $profileId = MarketingProfile::query()
                ->where('normalized_email', $normalizedEmail)
                ->value('id');
            if (is_numeric($profileId) && (int) $profileId > 0) {
                return (int) $profileId;
            }
        }

        $normalizedPhone = $this->normalizer->normalizePhone($this->nullableString($customer['phone'] ?? null));
        if ($normalizedPhone !== null) {
            $profileId = MarketingProfile::query()
                ->where('normalized_phone', $normalizedPhone)
                ->value('id');
            if (is_numeric($profileId) && (int) $profileId > 0) {
                return (int) $profileId;
            }
        }

        return null;
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,summary:array<string,int>}
     */
    protected function fetchAllReviews(string $identifier, int $perPage, int $maxPages, int $pageDelayMs): array
    {
        $offset = 0;
        $pages = 0;
        $total = null;
        $items = [];

        while ($pages < $maxPages) {
            $pages++;
            $payload = $this->client->getReviews($identifier, $perPage, $offset);
            $pageItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $items = [...$items, ...$pageItems];

            $perPageValue = max(1, (int) ($payload['perPage'] ?? $perPage));
            $currentOffset = max(0, (int) ($payload['currentOffset'] ?? $offset));
            $offset = $currentOffset + $perPageValue;
            $total = max(0, (int) ($payload['totalCount'] ?? count($items)));

            if ($offset >= $total || $pageItems === []) {
                break;
            }

            $this->sleepMilliseconds($pageDelayMs);
        }

        return [
            'items' => $items,
            'summary' => [
                'total' => max(0, (int) ($total ?? count($items))),
                'pages' => $pages,
            ],
        ];
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,summary:array<string,int>}
     */
    protected function fetchAllActivities(string $identifier, int $perPage, int $maxPages, int $pageDelayMs): array
    {
        $page = 1;
        $pages = 0;
        $total = null;
        $items = [];

        while ($pages < $maxPages) {
            $pages++;
            $payload = $this->client->getActivityHistory($identifier, $perPage, $page);
            $pageItems = is_array($payload['activities'] ?? null) ? $payload['activities'] : [];
            $items = [...$items, ...$pageItems];

            $currentPage = max(1, (int) ($payload['currentPage'] ?? $page));
            $perPageValue = max(1, (int) ($payload['perPage'] ?? $perPage));
            $total = max(0, (int) ($payload['totalCount'] ?? count($items)));

            if (($currentPage * $perPageValue) >= $total || $pageItems === []) {
                break;
            }

            $this->sleepMilliseconds($pageDelayMs);
            $page = $currentPage + 1;
        }

        return [
            'items' => $items,
            'summary' => [
                'total' => max(0, (int) ($total ?? count($items))),
                'pages' => $pages,
            ],
        ];
    }

    /**
     * @param array{items:array<int,array<string,mixed>>,summary:array<string,int>} $reviews
     * @return array{review_count:int,published_review_count:int,average_rating:?float,last_reviewed_at:?CarbonImmutable}
     */
    protected function buildReviewSummary(array $reviews): array
    {
        $items = (array) ($reviews['items'] ?? []);
        if ($items === []) {
            return [
                'review_count' => 0,
                'published_review_count' => 0,
                'average_rating' => null,
                'last_reviewed_at' => null,
            ];
        }

        $totalRating = 0.0;
        $ratingCount = 0;
        $publishedCount = 0;
        $lastReviewedAt = null;

        foreach ($items as $item) {
            $rate = $item['rate'] ?? null;
            if (is_numeric($rate)) {
                $totalRating += (float) $rate;
                $ratingCount++;
            }

            if (is_bool($item['isPublished'] ?? null) && $item['isPublished'] === true) {
                $publishedCount++;
            }

            $createdAt = $this->asDate($item['createdAt'] ?? null);
            if ($createdAt && ($lastReviewedAt === null || $createdAt->greaterThan($lastReviewedAt))) {
                $lastReviewedAt = $createdAt;
            }
        }

        return [
            'review_count' => count($items),
            'published_review_count' => $publishedCount,
            'average_rating' => $ratingCount > 0 ? round($totalRating / $ratingCount, 2) : null,
            'last_reviewed_at' => $lastReviewedAt,
        ];
    }

    protected function referralCountFromActivities(array $activities): int
    {
        $count = 0;
        foreach ($activities as $activity) {
            $type = strtolower(trim((string) ($activity['type'] ?? '')));
            if (in_array($type, ['referrer', 'referred'], true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array{review_count:int,published_review_count:int,average_rating:?float,last_reviewed_at:?CarbonImmutable} $reviewSummary
     * @param array<string,int> $activitySummary
     * @return array<int,array{namespace:string,key:string,value:string,type:?string}>
     */
    protected function buildRawMetafields(
        array $customer,
        array $reviewSummary,
        int $referralCount,
        array $activitySummary
    ): array {
        $rows = [];

        $add = function (string $key, mixed $value, ?string $type = null) use (&$rows): void {
            $string = trim((string) $value);
            if ($string === '') {
                return;
            }

            $rows[] = [
                'namespace' => 'growave',
                'key' => $key,
                'value' => $string,
                'type' => $type,
            ];
        };

        $add('customer_id', $customer['customerId'] ?? null, 'number_integer');
        $add('birthday', $customer['birthday'] ?? null, 'single_line_text_field');
        $add('points_expires_at', $customer['pointsExpiresAt'] ?? null, 'date_time');
        $add('reward_program_available', $this->boolString($customer['isRewardProgramAvailable'] ?? null), 'boolean');
        $add('referral_program_available', $this->boolString($customer['isReferralProgramAvailable'] ?? null), 'boolean');

        $reviewCount = (int) ($reviewSummary['review_count'] ?? 0);
        $publishedCount = (int) ($reviewSummary['published_review_count'] ?? 0);
        $averageRating = $reviewSummary['average_rating'] ?? null;

        $add('review_count', $reviewCount, 'number_integer');
        $add('published_review_count', $publishedCount, 'number_integer');
        $add('review_average_rating', $averageRating !== null ? number_format((float) $averageRating, 2, '.', '') : null, 'number_decimal');
        $add('referral_count', $referralCount, 'number_integer');

        $add('activity_total', (int) ($activitySummary['total'] ?? 0), 'number_integer');
        $add('activity_pages', (int) ($activitySummary['pages'] ?? 0), 'number_integer');

        return $rows;
    }

    protected function boolString(mixed $value): ?string
    {
        if (! is_bool($value)) {
            return null;
        }

        return $value ? 'true' : 'false';
    }

    /**
     * @param array<int,array{namespace:string,key:string,value:string,type:?string}> $rawMetafields
     * @return 'external_created'|'external_updated'
     */
    protected function upsertGrowaveExternalProfile(
        CustomerExternalProfile $candidate,
        array $customer,
        string $customerId,
        ?int $marketingProfileId,
        array $rawMetafields,
        ?string $storeKey
    ): string {
        $lookup = [
            'provider' => 'shopify',
            'integration' => 'growave',
            'store_key' => $storeKey,
            'external_customer_id' => $customerId,
        ];

        $existing = CustomerExternalProfile::query()->where($lookup)->first();

        $firstName = $this->nullableString($customer['firstName'] ?? null) ?: $this->nullableString($candidate->first_name);
        $lastName = $this->nullableString($customer['lastName'] ?? null) ?: $this->nullableString($candidate->last_name);
        $fullName = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
        $email = $this->nullableString($customer['email'] ?? null) ?: $this->nullableString($candidate->email);
        $phone = $this->nullableString($customer['phone'] ?? null) ?: $this->nullableString($candidate->phone);

        $currentTier = $this->nullableString(data_get($customer, 'currentTier.title'));
        $referralLink = $this->nullableString($customer['referralLink'] ?? null);

        CustomerExternalProfile::query()->updateOrCreate(
            $lookup,
            [
                'marketing_profile_id' => $marketingProfileId,
                'external_customer_gid' => $this->nullableString($candidate->external_customer_gid)
                    ?: ('growave:' . $customerId),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => $fullName !== '' ? $fullName : null,
                'email' => $email,
                'normalized_email' => $this->normalizer->normalizeEmail($email),
                'phone' => $phone,
                'normalized_phone' => $this->normalizer->normalizePhone($phone),
                'accepts_marketing' => is_bool($customer['acceptsEmailMarketing'] ?? null)
                    ? (bool) $customer['acceptsEmailMarketing']
                    : null,
                'source_channels' => array_values(array_filter(array_unique([
                    'shopify',
                    'growave',
                ]))),
                'raw_metafields' => $rawMetafields,
                'points_balance' => is_numeric($customer['pointsBalance'] ?? null)
                    ? (int) round((float) $customer['pointsBalance'])
                    : null,
                'vip_tier' => $currentTier,
                'referral_link' => $referralLink,
                'last_activity_at' => $this->asDate($customer['pointsExpiresAt'] ?? null),
                'synced_at' => now(),
            ]
        );

        return $existing ? 'external_updated' : 'external_created';
    }

    /**
     * @param array{items:array<int,array<string,mixed>>,summary:array<string,int>} $reviews
     * @param array{review_count:int,published_review_count:int,average_rating:?float,last_reviewed_at:?CarbonImmutable} $reviewSummary
     * @return array{0:'review_summaries_created'|'review_summaries_updated',1:array{created:int,updated:int}}
     */
    protected function upsertReviewSummaryAndHistory(
        ?int $marketingProfileId,
        ?string $storeKey,
        string $customerId,
        array $customer,
        array $reviewSummary,
        array $reviews
    ): array {
        $lookup = [
            'provider' => 'growave',
            'integration' => 'growave',
            'store_key' => $storeKey,
            'external_customer_id' => $customerId,
        ];

        $existingSummary = MarketingReviewSummary::query()->where($lookup)->first();

        $summaryRow = MarketingReviewSummary::query()->updateOrCreate(
            $lookup,
            [
                'marketing_profile_id' => $marketingProfileId,
                'external_customer_email' => $this->nullableString($customer['email'] ?? null),
                'review_count' => (int) ($reviewSummary['review_count'] ?? 0),
                'published_review_count' => (int) ($reviewSummary['published_review_count'] ?? 0),
                'average_rating' => $reviewSummary['average_rating'],
                'last_reviewed_at' => $reviewSummary['last_reviewed_at'],
                'source_synced_at' => now(),
                'raw_payload' => [
                    'api_total' => (int) data_get($reviews, 'summary.total', 0),
                    'pages' => (int) data_get($reviews, 'summary.pages', 0),
                ],
            ]
        );

        $created = 0;
        $updated = 0;

        foreach ((array) ($reviews['items'] ?? []) as $review) {
            $externalReviewId = $this->nullableString($review['id'] ?? null);
            if ($externalReviewId === null) {
                continue;
            }

            $reviewLookup = [
                'provider' => 'growave',
                'integration' => 'growave',
                'store_key' => $storeKey,
                'external_review_id' => $externalReviewId,
            ];

            $existingReview = MarketingReviewHistory::query()->where($reviewLookup)->first();

            $productId = $this->nullableString(data_get($review, 'product.shopifyProductId'))
                ?: $this->nullableString(data_get($review, 'product.productId'));
            $reviewedAt = $this->asDate($review['createdAt'] ?? null);
            $reviewerName = $this->nullableString(data_get($review, 'customer.name'))
                ?: $this->nullableString(data_get($review, 'author.name'))
                ?: $this->nullableString(data_get($review, 'customer.fullName'));
            $reviewerEmail = $this->nullableString(data_get($review, 'customer.email'))
                ?: $this->nullableString($customer['email'] ?? null);
            $productHandle = $this->nullableString(data_get($review, 'product.handle'));
            $productUrl = $productHandle ? '/products/' . ltrim($productHandle, '/') : null;
            $isPublished = is_bool($review['isPublished'] ?? null) ? (bool) $review['isPublished'] : null;

            MarketingReviewHistory::query()->updateOrCreate(
                $reviewLookup,
                [
                    'marketing_profile_id' => $marketingProfileId,
                    'marketing_review_summary_id' => $summaryRow->id,
                    'external_customer_id' => $customerId,
                    'rating' => is_numeric($review['rate'] ?? null) ? (int) $review['rate'] : null,
                    'title' => $this->nullableString($review['title'] ?? null),
                    'body' => $this->nullableString($review['body'] ?? null),
                    'reviewer_name' => $reviewerName,
                    'reviewer_email' => $reviewerEmail,
                    'is_published' => $isPublished,
                    'status' => $isPublished === false ? 'rejected' : 'approved',
                    'submission_source' => 'growave_import',
                    'is_pinned' => is_bool($review['isPinned'] ?? null) ? (bool) $review['isPinned'] : null,
                    'is_verified_buyer' => is_bool($review['isVerifiedBuyer'] ?? null) ? (bool) $review['isVerifiedBuyer'] : null,
                    'votes' => is_numeric($review['votes'] ?? null) ? (int) $review['votes'] : null,
                    'has_media' => is_array($review['images'] ?? null) && count((array) $review['images']) > 0,
                    'media_count' => is_array($review['images'] ?? null) ? count((array) $review['images']) : 0,
                    'product_id' => $productId,
                    'product_handle' => $productHandle,
                    'product_url' => $productUrl,
                    'product_title' => $this->nullableString(data_get($review, 'product.title')),
                    'reviewed_at' => $reviewedAt,
                    'submitted_at' => $reviewedAt,
                    'approved_at' => $isPublished === false ? null : $reviewedAt,
                    'rejected_at' => $isPublished === false ? $reviewedAt : null,
                    'source_synced_at' => now(),
                    'raw_payload' => $review,
                ]
            );

            if ($existingReview) {
                $updated++;
            } else {
                $created++;
            }
        }

        return [
            $existingSummary ? 'review_summaries_updated' : 'review_summaries_created',
            ['created' => $created, 'updated' => $updated],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $activities
     * @return array{created:int,skipped_existing:int,skipped_no_profile:int,balance_delta:int}
     */
    protected function applyActivitiesToCandleCash(
        ?int $marketingProfileId,
        ?string $storeKey,
        string $customerId,
        array $activities
    ): array {
        $summary = [
            'created' => 0,
            'skipped_existing' => 0,
            'skipped_no_profile' => 0,
            'balance_delta' => 0,
        ];

        if (! $marketingProfileId || $marketingProfileId <= 0) {
            $summary['skipped_no_profile'] = count($activities);

            return $summary;
        }

        foreach ($activities as $activity) {
            $activityId = $this->nullableString($activity['id'] ?? null);
            if ($activityId === null) {
                continue;
            }

            $delta = $this->pointsDeltaFromActivity($activity);
            if ($delta === null || $delta === 0) {
                continue;
            }

            $sourceId = implode(':', array_filter([
                $storeKey,
                $customerId,
                $activityId,
            ]));

            $type = $this->transactionTypeFromActivity($activity);
            $description = $this->descriptionFromActivity($activity);

            $result = DB::transaction(function () use ($marketingProfileId, $sourceId, $delta, $type, $description): string {
                $existing = CandleCashTransaction::query()
                    ->where('marketing_profile_id', $marketingProfileId)
                    ->where('source', 'growave_activity')
                    ->where('source_id', $sourceId)
                    ->lockForUpdate()
                    ->exists();

                if ($existing) {
                    return 'skipped_existing';
                }

                $balance = CandleCashBalance::query()
                    ->lockForUpdate()
                    ->firstOrCreate(
                        ['marketing_profile_id' => $marketingProfileId],
                        ['balance' => 0]
                    );

                $balance->forceFill([
                    'balance' => (int) $balance->balance + $delta,
                ])->save();

                CandleCashTransaction::query()->create([
                    'marketing_profile_id' => $marketingProfileId,
                    'type' => $type,
                    'points' => $delta,
                    'source' => 'growave_activity',
                    'source_id' => $sourceId,
                    'description' => $description,
                ]);

                return 'created';
            });

            if ($result === 'created') {
                $summary['created']++;
                $summary['balance_delta'] += $delta;
            } else {
                $summary['skipped_existing']++;
            }
        }

        return $summary;
    }

    protected function pointsDeltaFromActivity(array $activity): ?int
    {
        $type = strtolower(trim((string) ($activity['type'] ?? '')));

        return match ($type) {
            'redeem' => $this->negative($activity['spentPoints'] ?? null),
            'expired' => $this->negative($activity['expiredPoints'] ?? null),
            'manual' => $this->signed($activity['points'] ?? null),
            'refund' => $this->positive($activity['refundedPoints'] ?? null),
            'reward', 'referrer', 'referred', 'import' => $this->positive(data_get($activity, 'reward.points')),
            default => null,
        };
    }

    protected function transactionTypeFromActivity(array $activity): string
    {
        $type = strtolower(trim((string) ($activity['type'] ?? '')));

        return match ($type) {
            'redeem' => 'redeem',
            'expired' => 'expire',
            'manual', 'refund', 'import' => 'adjust',
            default => 'earn',
        };
    }

    protected function descriptionFromActivity(array $activity): string
    {
        $type = strtolower(trim((string) ($activity['type'] ?? 'unknown')));
        $id = $this->nullableString($activity['id'] ?? null) ?: 'n/a';
        $note = $this->nullableString($activity['note'] ?? null);

        $description = 'Imported Growave activity #' . $id . ' (' . $type . ')';
        if ($note !== null) {
            $description .= ': ' . $note;
        }

        return $description;
    }

    protected function signed(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    protected function positive(mixed $value): ?int
    {
        $signed = $this->signed($value);
        if ($signed === null) {
            return null;
        }

        return abs($signed);
    }

    protected function negative(mixed $value): ?int
    {
        $signed = $this->signed($value);
        if ($signed === null) {
            return null;
        }

        return -abs($signed);
    }

    protected function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    protected function asDate(mixed $value): ?CarbonImmutable
    {
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
