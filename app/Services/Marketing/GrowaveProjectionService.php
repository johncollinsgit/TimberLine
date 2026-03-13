<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingReviewSummary;

class GrowaveProjectionService
{
    /**
     * @param iterable<int,CustomerExternalProfile> $externals
     * @return array<int,CustomerExternalProfile>
     */
    public function preferredExternalMap(iterable $externals): array
    {
        $preferred = [];

        foreach ($externals as $external) {
            $profileId = (int) $external->marketing_profile_id;
            if ($profileId <= 0) {
                continue;
            }

            $current = $preferred[$profileId] ?? null;
            if ($current === null || $this->compareExternal($external, $current) > 0) {
                $preferred[$profileId] = $external;
            }
        }

        return $preferred;
    }

    /**
     * @param iterable<int,CustomerExternalProfile> $externals
     */
    public function preferredExternal(iterable $externals): ?CustomerExternalProfile
    {
        $preferred = null;

        foreach ($externals as $external) {
            if ($preferred === null || $this->compareExternal($external, $preferred) > 0) {
                $preferred = $external;
            }
        }

        return $preferred;
    }

    /**
     * @param iterable<int,MarketingReviewSummary> $summaries
     * @param array<int,CustomerExternalProfile> $preferredExternalByProfile
     * @return array<int,MarketingReviewSummary>
     */
    public function preferredReviewSummaryMap(iterable $summaries, array $preferredExternalByProfile = []): array
    {
        $preferred = [];

        foreach ($summaries as $summary) {
            $profileId = (int) $summary->marketing_profile_id;
            if ($profileId <= 0) {
                continue;
            }

            $external = $preferredExternalByProfile[$profileId] ?? null;
            $current = $preferred[$profileId] ?? null;
            if ($current === null || $this->compareSummary($summary, $current, $external) > 0) {
                $preferred[$profileId] = $summary;
            }
        }

        return $preferred;
    }

    /**
     * @param iterable<int,MarketingReviewSummary> $summaries
     */
    public function preferredReviewSummary(
        iterable $summaries,
        ?CustomerExternalProfile $preferredExternal = null
    ): ?MarketingReviewSummary {
        $preferred = null;

        foreach ($summaries as $summary) {
            if ($preferred === null || $this->compareSummary($summary, $preferred, $preferredExternal) > 0) {
                $preferred = $summary;
            }
        }

        return $preferred;
    }

    protected function compareExternal(CustomerExternalProfile $candidate, CustomerExternalProfile $current): int
    {
        return $this->compareTuple($this->externalTuple($candidate), $this->externalTuple($current));
    }

    protected function compareSummary(
        MarketingReviewSummary $candidate,
        MarketingReviewSummary $current,
        ?CustomerExternalProfile $preferredExternal = null
    ): int {
        return $this->compareTuple(
            $this->summaryTuple($candidate, $preferredExternal),
            $this->summaryTuple($current, $preferredExternal)
        );
    }

    /**
     * @return array<int,int|float|string>
     */
    protected function externalTuple(CustomerExternalProfile $external): array
    {
        $reviewCount = $this->metafieldInt($external, 'review_count');
        $publishedCount = $this->metafieldInt($external, 'published_review_count');
        $activityTotal = $this->metafieldInt($external, 'activity_total');
        $referralCount = $this->metafieldInt($external, 'referral_count');
        $points = (int) ($external->points_balance ?? 0);
        $syncedAt = $external->synced_at?->getTimestamp() ?? 0;

        return [
            $reviewCount > 0 ? 1 : 0,
            $reviewCount,
            $publishedCount,
            $activityTotal > 0 ? 1 : 0,
            $activityTotal,
            $points > 0 ? 1 : 0,
            $points,
            $referralCount > 0 ? 1 : 0,
            $external->referral_link ? 1 : 0,
            $external->vip_tier ? 1 : 0,
            $syncedAt,
            (int) $external->id,
        ];
    }

    /**
     * @return array<int,int|float|string>
     */
    protected function summaryTuple(
        MarketingReviewSummary $summary,
        ?CustomerExternalProfile $preferredExternal = null
    ): array {
        $reviewCount = (int) ($summary->review_count ?? 0);
        $publishedCount = (int) ($summary->published_review_count ?? 0);
        $averageRating = $summary->average_rating !== null ? (float) $summary->average_rating : 0.0;
        $lastReviewedAt = $summary->last_reviewed_at?->getTimestamp() ?? 0;
        $sourceSyncedAt = $summary->source_synced_at?->getTimestamp() ?? 0;
        $matchesPreferredExternal = $preferredExternal !== null
            && (string) $preferredExternal->external_customer_id === (string) $summary->external_customer_id
            && (string) ($preferredExternal->store_key ?? '') === (string) ($summary->store_key ?? '');

        return [
            $matchesPreferredExternal ? 1 : 0,
            $reviewCount > 0 ? 1 : 0,
            $reviewCount,
            $publishedCount,
            $averageRating > 0 ? 1 : 0,
            $averageRating,
            $lastReviewedAt,
            $sourceSyncedAt,
            (int) $summary->id,
        ];
    }

    protected function metafieldInt(CustomerExternalProfile $external, string $key): int
    {
        foreach ((array) ($external->raw_metafields ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ((string) ($row['namespace'] ?? '') !== 'growave') {
                continue;
            }

            if ((string) ($row['key'] ?? '') !== $key) {
                continue;
            }

            if (! is_numeric($row['value'] ?? null)) {
                return 0;
            }

            return (int) round((float) $row['value']);
        }

        return 0;
    }

    /**
     * @param array<int,int|float|string> $left
     * @param array<int,int|float|string> $right
     */
    protected function compareTuple(array $left, array $right): int
    {
        $count = max(count($left), count($right));

        for ($index = 0; $index < $count; $index++) {
            $leftValue = $left[$index] ?? 0;
            $rightValue = $right[$index] ?? 0;

            if ($leftValue === $rightValue) {
                continue;
            }

            return $leftValue > $rightValue ? 1 : -1;
        }

        return 0;
    }
}
