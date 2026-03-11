<?php

namespace App\Services\Marketing;

use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Facades\DB;

class MarketingProfileSyncService
{
    public function __construct(
        protected MarketingIdentityExtractor $extractor,
        protected MarketingProfileMatcher $matcher,
        protected MarketingIdentityNormalizer $normalizer
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array{
     *   status:string,
     *   reason:string,
     *   profile_id:?int,
     *   profiles_created:int,
     *   profiles_updated:int,
     *   links_created:int,
     *   links_reused:int,
     *   reviews_created:int,
     *   records_skipped:int
     * }
     */
    public function syncOrder(Order $order, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $context = is_array($options['identity_context'] ?? null) ? $options['identity_context'] : [];

        $identity = $this->extractor->extractFromOrder($order, $context);
        $reviewContext = [
            'source_label' => 'order_sync',
            'order_id' => (int) $order->id,
            'order_number' => (string) ($order->order_number ?? ''),
            'order_type' => (string) ($order->order_type ?? ''),
            'source' => (string) ($order->source ?? ''),
        ];

        return $this->syncIdentity(
            identity: $identity,
            reviewContext: $reviewContext,
            dryRun: $dryRun
        );
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $options
     * @return array{
     *   status:string,
     *   reason:string,
     *   profile_id:?int,
     *   profiles_created:int,
     *   profiles_updated:int,
     *   links_created:int,
     *   links_reused:int,
     *   reviews_created:int,
     *   records_skipped:int
     * }
     */
    public function syncExternalIdentity(array $identity, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $reviewContext = is_array($options['review_context'] ?? null) ? $options['review_context'] : [];

        return $this->syncIdentity(
            identity: $this->normalizeIdentityPayload($identity),
            reviewContext: $reviewContext,
            dryRun: $dryRun
        );
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $reviewContext
     * @return array{
     *   status:string,
     *   reason:string,
     *   profile_id:?int,
     *   profiles_created:int,
     *   profiles_updated:int,
     *   links_created:int,
     *   links_reused:int,
     *   reviews_created:int,
     *   records_skipped:int
     * }
     */
    protected function syncIdentity(array $identity, array $reviewContext, bool $dryRun): array
    {
        $sourceLinkProfiles = $this->profilesFromSourceLinks($identity['source_links']);
        $sourceLinkedProfile = $sourceLinkProfiles->count() === 1 ? $sourceLinkProfiles->first() : null;

        if ($sourceLinkProfiles->count() > 1) {
            $reviewCreated = $this->createOrUpdateReview(
                $identity,
                'source_link_conflict',
                ['source_link_profile_ids' => $sourceLinkProfiles->pluck('id')->values()->all()],
                $reviewContext,
                $dryRun
            );

            return $this->result('review', 'source_link_conflict', null, 0, 0, 0, 0, $reviewCreated ? 1 : 0, 0);
        }

        if (! $identity['has_identity']) {
            if (! $sourceLinkedProfile) {
                if (! $this->shouldCreateSourceLinkedProfileWithoutIdentity($identity)) {
                    return $this->result('skipped', 'missing_email_phone', null, 0, 0, 0, 0, 0, 1);
                }

                return $this->persistProfileAndLinks(
                    identity: $identity,
                    profile: new MarketingProfile(),
                    matchMethod: 'created_from_source_without_identity',
                    confidence: null,
                    createProfile: true,
                    reviewContext: $reviewContext,
                    dryRun: $dryRun
                );
            }

            return $this->persistProfileAndLinks(
                identity: $identity,
                profile: $sourceLinkedProfile,
                matchMethod: 'existing_source_link',
                confidence: null,
                createProfile: false,
                reviewContext: $reviewContext,
                dryRun: $dryRun
            );
        }

        $match = $this->matcher->match($identity['normalized_email'], $identity['normalized_phone']);

        if ($sourceLinkedProfile && $match['outcome'] === 'matched' && (int) $match['profile']?->id !== (int) $sourceLinkedProfile->id) {
            $reviewCreated = $this->createOrUpdateReview(
                $identity,
                'source_link_vs_match_conflict',
                [
                    'source_link_profile_id' => (int) $sourceLinkedProfile->id,
                    'matched_profile_id' => (int) $match['profile']?->id,
                    'match_reason' => $match['reason'],
                ],
                $reviewContext,
                $dryRun
            );

            return $this->result('review', 'source_link_vs_match_conflict', null, 0, 0, 0, 0, $reviewCreated ? 1 : 0, 0);
        }

        if ($match['outcome'] === 'review') {
            $reviewCreated = $this->createOrUpdateReview(
                $identity,
                $match['reason'],
                [
                    'email_match_profile_ids' => $match['email_matches']->pluck('id')->values()->all(),
                    'phone_match_profile_ids' => $match['phone_matches']->pluck('id')->values()->all(),
                ],
                $reviewContext,
                $dryRun
            );

            return $this->result('review', $match['reason'], null, 0, 0, 0, 0, $reviewCreated ? 1 : 0, 0);
        }

        if ($sourceLinkedProfile && $match['outcome'] === 'create') {
            return $this->persistProfileAndLinks(
                identity: $identity,
                profile: $sourceLinkedProfile,
                matchMethod: 'existing_source_link',
                confidence: null,
                createProfile: false,
                reviewContext: $reviewContext,
                dryRun: $dryRun
            );
        }

        if ($match['outcome'] === 'matched' && $match['profile'] instanceof MarketingProfile) {
            $confidence = in_array($match['reason'], ['exact_email_phone', 'exact_email', 'exact_phone'], true) ? 1.00 : null;

            return $this->persistProfileAndLinks(
                identity: $identity,
                profile: $match['profile'],
                matchMethod: $match['reason'],
                confidence: $confidence,
                createProfile: false,
                reviewContext: $reviewContext,
                dryRun: $dryRun
            );
        }

        if ($match['outcome'] === 'create') {
            $profile = new MarketingProfile();

            return $this->persistProfileAndLinks(
                identity: $identity,
                profile: $profile,
                matchMethod: 'created_from_source',
                confidence: null,
                createProfile: true,
                reviewContext: $reviewContext,
                dryRun: $dryRun
            );
        }

        return $this->result('skipped', 'no_action_taken', null, 0, 0, 0, 0, 0, 1);
    }

    /**
     * Allow source-only profile creation for trusted customer source links so
     * canonical profiles can still be created when email/phone are missing.
     *
     * @param array<string,mixed> $identity
     */
    protected function shouldCreateSourceLinkedProfileWithoutIdentity(array $identity): bool
    {
        $channels = collect((array) ($identity['source_channels'] ?? []))
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->values();

        if ($channels->intersect(['shopify', 'square', 'growave', 'wholesale', 'event', 'manual'])->isNotEmpty()) {
            return true;
        }

        $allowedSourceTypes = [
            'shopify_order',
            'shopify_customer',
            'growave_customer',
            'square_customer',
            'square_order',
            'square_payment',
            'wholesale_customer',
            'event_customer',
            'internal_customer',
            'manual_customer',
        ];

        foreach ((array) ($identity['source_links'] ?? []) as $link) {
            if (! is_array($link)) {
                continue;
            }

            $sourceType = strtolower(trim((string) ($link['source_type'] ?? '')));
            if (in_array($sourceType, $allowedSourceTypes, true)) {
                return true;
            }
        }

        return false;
    }

    public function resolveReviewToExistingProfile(
        MarketingIdentityReview $review,
        MarketingProfile $profile,
        ?int $reviewedBy = null,
        ?string $resolutionNotes = null
    ): MarketingIdentityReview {
        return DB::transaction(function () use ($review, $profile, $reviewedBy, $resolutionNotes): MarketingIdentityReview {
            $this->updateProfileFromReview($profile, $review);

            MarketingProfileLink::query()->updateOrCreate(
                [
                    'source_type' => $review->source_type,
                    'source_id' => $review->source_id,
                ],
                [
                    'marketing_profile_id' => $profile->id,
                    'source_meta' => [
                        'review_id' => $review->id,
                        'resolved_via' => 'manual_review',
                    ],
                    'match_method' => 'manual_review',
                    'confidence' => 1.00,
                ]
            );

            $review->forceFill([
                'status' => 'resolved',
                'proposed_marketing_profile_id' => $profile->id,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
                'resolution_notes' => $resolutionNotes,
            ])->save();

            return $review->fresh();
        });
    }

    /**
     * @param array<string,mixed> $profileData
     */
    public function resolveReviewToNewProfile(
        MarketingIdentityReview $review,
        array $profileData,
        ?int $reviewedBy = null,
        ?string $resolutionNotes = null
    ): MarketingIdentityReview {
        return DB::transaction(function () use ($review, $profileData, $reviewedBy, $resolutionNotes): MarketingIdentityReview {
            $fullName = trim((string) ($profileData['full_name'] ?? ''));
            [$splitFirst, $splitLast] = $this->normalizer->splitName($fullName);

            $email = (string) ($profileData['email'] ?? $review->raw_email ?? '');
            $phone = (string) ($profileData['phone'] ?? $review->raw_phone ?? '');

            $normalizedEmail = $this->normalizer->normalizeEmail($email);
            $normalizedPhone = $this->normalizer->normalizePhone($phone);

            $profile = MarketingProfile::query()->create([
                'first_name' => trim((string) ($profileData['first_name'] ?? $splitFirst ?? $review->raw_first_name)) ?: null,
                'last_name' => trim((string) ($profileData['last_name'] ?? $splitLast ?? $review->raw_last_name)) ?: null,
                'email' => $normalizedEmail ? trim($email) : null,
                'normalized_email' => $normalizedEmail,
                'phone' => $normalizedPhone ? trim($phone) : null,
                'normalized_phone' => $normalizedPhone,
                'source_channels' => ['manual_review'],
            ]);

            return $this->resolveReviewToExistingProfile(
                review: $review,
                profile: $profile,
                reviewedBy: $reviewedBy,
                resolutionNotes: $resolutionNotes
            );
        });
    }

    public function ignoreReview(
        MarketingIdentityReview $review,
        ?int $reviewedBy = null,
        ?string $resolutionNotes = null
    ): MarketingIdentityReview {
        $review->forceFill([
            'status' => 'ignored',
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
            'resolution_notes' => $resolutionNotes,
        ])->save();

        return $review->fresh();
    }

    /**
     * @param array<string,mixed> $identity
     * @return array{
     *   status:string,
     *   reason:string,
     *   profile_id:?int,
     *   profiles_created:int,
     *   profiles_updated:int,
     *   links_created:int,
     *   links_reused:int,
     *   reviews_created:int,
     *   records_skipped:int
     * }
     */
    protected function persistProfileAndLinks(
        array $identity,
        MarketingProfile $profile,
        string $matchMethod,
        ?float $confidence,
        bool $createProfile,
        array $reviewContext,
        bool $dryRun
    ): array {
        $profileCreated = 0;
        $profileUpdated = 0;
        $linksCreated = 0;
        $linksReused = 0;

        $conflictingLink = $this->firstConflictingSourceLink($profile, $identity['source_links']);
        if ($conflictingLink) {
            $reviewCreated = $this->createOrUpdateReview(
                $identity,
                'source_link_owned_by_other_profile',
                [
                    'target_profile_id' => (int) $profile->id,
                    'conflict_source_type' => $conflictingLink->source_type,
                    'conflict_source_id' => $conflictingLink->source_id,
                    'conflict_profile_id' => (int) $conflictingLink->marketing_profile_id,
                ],
                $reviewContext,
                $dryRun
            );

            return $this->result('review', 'source_link_owned_by_other_profile', null, 0, 0, 0, 0, $reviewCreated ? 1 : 0, 0);
        }

        if (! $dryRun) {
            DB::transaction(function () use (
                &$profileCreated,
                &$profileUpdated,
                &$linksCreated,
                &$linksReused,
                $profile,
                $identity,
                $matchMethod,
                $confidence,
                $createProfile
            ): void {
                if ($createProfile) {
                    $profile->fill([
                        'first_name' => $identity['first_name'],
                        'last_name' => $identity['last_name'],
                        'email' => $identity['raw_email'],
                        'normalized_email' => $identity['normalized_email'],
                        'phone' => $identity['raw_phone'],
                        'normalized_phone' => $identity['normalized_phone'],
                        'source_channels' => $identity['source_channels'],
                    ]);
                    $profile->save();
                    $profileCreated = 1;
                } elseif ($this->applyConservativeProfileUpdates($profile, $identity)) {
                    $profileUpdated = 1;
                }

                foreach ($identity['source_links'] as $linkData) {
                    $existing = MarketingProfileLink::query()
                        ->where('source_type', $linkData['source_type'])
                        ->where('source_id', $linkData['source_id'])
                        ->first();

                    MarketingProfileLink::query()->updateOrCreate(
                        [
                            'source_type' => $linkData['source_type'],
                            'source_id' => $linkData['source_id'],
                        ],
                        [
                            'marketing_profile_id' => $profile->id,
                            'source_meta' => $linkData['source_meta'],
                            'match_method' => $matchMethod,
                            'confidence' => $confidence,
                        ]
                    );

                    if ($existing) {
                        $linksReused++;
                    } else {
                        $linksCreated++;
                    }
                }
            });
        } else {
            $profileCreated = $createProfile ? 1 : 0;
            $profileUpdated = $createProfile ? 0 : 1;
            foreach ($identity['source_links'] as $linkData) {
                $existing = MarketingProfileLink::query()
                    ->where('source_type', $linkData['source_type'])
                    ->where('source_id', $linkData['source_id'])
                    ->exists();

                if ($existing) {
                    $linksReused++;
                } else {
                    $linksCreated++;
                }
            }
        }

        return $this->result(
            status: $createProfile ? 'created' : 'updated',
            reason: $matchMethod,
            profileId: $dryRun ? ($profile->id ? (int) $profile->id : null) : (int) $profile->id,
            profilesCreated: $profileCreated,
            profilesUpdated: $profileUpdated,
            linksCreated: $linksCreated,
            linksReused: $linksReused,
            reviewsCreated: 0,
            recordsSkipped: 0
        );
    }

    /**
     * @param array<string,mixed> $identity
     */
    protected function applyConservativeProfileUpdates(MarketingProfile $profile, array $identity): bool
    {
        $changed = false;

        if (!$profile->first_name && !empty($identity['first_name'])) {
            $profile->first_name = $identity['first_name'];
            $changed = true;
        }
        if (!$profile->last_name && !empty($identity['last_name'])) {
            $profile->last_name = $identity['last_name'];
            $changed = true;
        }

        if (!$profile->normalized_email && !empty($identity['normalized_email'])) {
            $profile->email = $identity['raw_email'];
            $profile->normalized_email = $identity['normalized_email'];
            $changed = true;
        } elseif (
            $profile->normalized_email &&
            $identity['normalized_email'] &&
            $profile->normalized_email === $identity['normalized_email'] &&
            !$profile->email &&
            $identity['raw_email']
        ) {
            $profile->email = $identity['raw_email'];
            $changed = true;
        }

        if (!$profile->normalized_phone && !empty($identity['normalized_phone'])) {
            $profile->phone = $identity['raw_phone'];
            $profile->normalized_phone = $identity['normalized_phone'];
            $changed = true;
        } elseif (
            $profile->normalized_phone &&
            $identity['normalized_phone'] &&
            $profile->normalized_phone === $identity['normalized_phone'] &&
            !$profile->phone &&
            $identity['raw_phone']
        ) {
            $profile->phone = $identity['raw_phone'];
            $changed = true;
        }

        $incomingChannels = is_array($identity['source_channels']) ? $identity['source_channels'] : [];
        $existingChannels = is_array($profile->source_channels) ? $profile->source_channels : [];
        $mergedChannels = array_values(array_unique(array_filter(array_merge($existingChannels, $incomingChannels))));

        if ($mergedChannels !== $existingChannels) {
            $profile->source_channels = $mergedChannels;
            $changed = true;
        }

        if ($changed) {
            $profile->save();
        }

        return $changed;
    }

    protected function updateProfileFromReview(MarketingProfile $profile, MarketingIdentityReview $review): void
    {
        $updated = false;

        if (!$profile->first_name && $review->raw_first_name) {
            $profile->first_name = $review->raw_first_name;
            $updated = true;
        }
        if (!$profile->last_name && $review->raw_last_name) {
            $profile->last_name = $review->raw_last_name;
            $updated = true;
        }

        $normalizedEmail = $this->normalizer->normalizeEmail($review->raw_email);
        if ($normalizedEmail && !$profile->normalized_email) {
            $profile->email = $review->raw_email;
            $profile->normalized_email = $normalizedEmail;
            $updated = true;
        }

        $normalizedPhone = $this->normalizer->normalizePhone($review->raw_phone);
        if ($normalizedPhone && !$profile->normalized_phone) {
            $profile->phone = $review->raw_phone;
            $profile->normalized_phone = $normalizedPhone;
            $updated = true;
        }

        $channels = is_array($profile->source_channels) ? $profile->source_channels : [];
        if (!in_array('manual_review', $channels, true)) {
            $channels[] = 'manual_review';
            $profile->source_channels = array_values(array_unique($channels));
            $updated = true;
        }

        if ($updated) {
            $profile->save();
        }
    }

    /**
     * @param array<int,array{source_type:string,source_id:string,source_meta:array<string,mixed>}> $sourceLinks
     * @return \Illuminate\Support\Collection<int,MarketingProfile>
     */
    protected function profilesFromSourceLinks(array $sourceLinks): \Illuminate\Support\Collection
    {
        if ($sourceLinks === []) {
            return collect();
        }

        $query = MarketingProfileLink::query();
        $query->where(function ($sub) use ($sourceLinks): void {
            foreach ($sourceLinks as $index => $linkData) {
                if ($index === 0) {
                    $sub->where(function ($nested) use ($linkData): void {
                        $nested->where('source_type', $linkData['source_type'])
                            ->where('source_id', $linkData['source_id']);
                    });
                } else {
                    $sub->orWhere(function ($nested) use ($linkData): void {
                        $nested->where('source_type', $linkData['source_type'])
                            ->where('source_id', $linkData['source_id']);
                    });
                }
            }
        });

        $profileIds = $query->pluck('marketing_profile_id')->unique()->values();

        if ($profileIds->isEmpty()) {
            return collect();
        }

        return MarketingProfile::query()->whereIn('id', $profileIds)->get();
    }

    /**
     * @param array<int,array{source_type:string,source_id:string,source_meta:array<string,mixed>}> $sourceLinks
     */
    protected function firstConflictingSourceLink(MarketingProfile $profile, array $sourceLinks): ?MarketingProfileLink
    {
        foreach ($sourceLinks as $linkData) {
            $existing = MarketingProfileLink::query()
                ->where('source_type', $linkData['source_type'])
                ->where('source_id', $linkData['source_id'])
                ->first();

            if ($existing && (int) $existing->marketing_profile_id !== (int) $profile->id) {
                return $existing;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $identity
     * @return array{
     *   first_name:?string,
     *   last_name:?string,
     *   full_name:?string,
     *   raw_email:?string,
     *   normalized_email:?string,
     *   raw_phone:?string,
     *   normalized_phone:?string,
     *   source_channels:array<int,string>,
     *   source_links:array<int,array{source_type:string,source_id:string,source_meta:array<string,mixed>}>,
     *   primary_source:array{source_type:string,source_id:string},
     *   has_identity:bool
     * }
     */
    protected function normalizeIdentityPayload(array $identity): array
    {
        $rawEmail = $this->nullableString($identity['raw_email'] ?? $identity['email'] ?? null);
        $rawPhone = $this->nullableString($identity['raw_phone'] ?? $identity['phone'] ?? null);

        $normalizedEmail = $this->normalizer->normalizeEmail($this->nullableString($identity['normalized_email'] ?? null) ?: $rawEmail);
        $normalizedPhone = $this->normalizer->normalizePhone($this->nullableString($identity['normalized_phone'] ?? null) ?: $rawPhone);

        $sourceLinks = [];
        foreach ((array) ($identity['source_links'] ?? []) as $link) {
            if (! is_array($link)) {
                continue;
            }
            $sourceType = $this->nullableString($link['source_type'] ?? null);
            $sourceId = $this->nullableString($link['source_id'] ?? null);
            if (! $sourceType || ! $sourceId) {
                continue;
            }
            $sourceLinks[] = [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_meta' => is_array($link['source_meta'] ?? null) ? $link['source_meta'] : [],
            ];
        }

        if ($sourceLinks === []) {
            $sourceLinks[] = [
                'source_type' => 'external',
                'source_id' => 'external:' . sha1(json_encode($identity)),
                'source_meta' => [],
            ];
        }

        $channels = collect((array) ($identity['source_channels'] ?? []))
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'first_name' => $this->nullableString($identity['first_name'] ?? null),
            'last_name' => $this->nullableString($identity['last_name'] ?? null),
            'full_name' => $this->nullableString($identity['full_name'] ?? null),
            'raw_email' => $rawEmail,
            'normalized_email' => $normalizedEmail,
            'raw_phone' => $rawPhone,
            'normalized_phone' => $normalizedPhone,
            'source_channels' => $channels,
            'source_links' => $sourceLinks,
            'primary_source' => [
                'source_type' => $this->nullableString($identity['primary_source']['source_type'] ?? null) ?: $sourceLinks[0]['source_type'],
                'source_id' => $this->nullableString($identity['primary_source']['source_id'] ?? null) ?: $sourceLinks[0]['source_id'],
            ],
            'has_identity' => $normalizedEmail !== null || $normalizedPhone !== null,
        ];
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $extra
     */
    protected function createOrUpdateReview(
        array $identity,
        string $reason,
        array $extra,
        array $reviewContext,
        bool $dryRun
    ): bool {
        $sourceType = (string) ($identity['primary_source']['source_type'] ?? 'order');
        $sourceId = (string) ($identity['primary_source']['source_id'] ?? ($reviewContext['source_id'] ?? 'unknown'));

        if ($dryRun) {
            $exists = MarketingIdentityReview::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('status', 'pending')
                ->exists();

            return !$exists;
        }

        $review = MarketingIdentityReview::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', 'pending')
            ->first();

        $payload = [
            'source_context' => $reviewContext,
            'source_links' => $identity['source_links'],
            'normalized_email' => $identity['normalized_email'],
            'normalized_phone' => $identity['normalized_phone'],
        ] + $extra;

        if ($review) {
            $existingReasons = is_array($review->conflict_reasons) ? $review->conflict_reasons : [];
            $review->forceFill([
                'raw_email' => $identity['raw_email'],
                'raw_phone' => $identity['raw_phone'],
                'raw_first_name' => $identity['first_name'],
                'raw_last_name' => $identity['last_name'],
                'conflict_reasons' => array_values(array_unique(array_merge($existingReasons, [$reason]))),
                'payload' => $payload,
            ])->save();

            return false;
        }

        MarketingIdentityReview::query()->create([
            'status' => 'pending',
            'proposed_marketing_profile_id' => null,
            'raw_email' => $identity['raw_email'],
            'raw_phone' => $identity['raw_phone'],
            'raw_first_name' => $identity['first_name'],
            'raw_last_name' => $identity['last_name'],
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'conflict_reasons' => [$reason],
            'payload' => $payload,
        ]);

        return true;
    }

    /**
     * @return array{
     *   status:string,
     *   reason:string,
     *   profile_id:?int,
     *   profiles_created:int,
     *   profiles_updated:int,
     *   links_created:int,
     *   links_reused:int,
     *   reviews_created:int,
     *   records_skipped:int
     * }
     */
    protected function result(
        string $status,
        string $reason,
        ?int $profileId,
        int $profilesCreated,
        int $profilesUpdated,
        int $linksCreated,
        int $linksReused,
        int $reviewsCreated,
        int $recordsSkipped
    ): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'profile_id' => $profileId,
            'profiles_created' => $profilesCreated,
            'profiles_updated' => $profilesUpdated,
            'links_created' => $linksCreated,
            'links_reused' => $linksReused,
            'reviews_created' => $reviewsCreated,
            'records_skipped' => $recordsSkipped,
        ];
    }
}
