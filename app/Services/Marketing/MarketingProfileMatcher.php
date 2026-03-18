<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Collection;

class MarketingProfileMatcher
{
    public function __construct(
        protected MarketingIdentityNormalizer $normalizer
    ) {
    }

    /**
     * @return array{
     *   outcome:string,
     *   profile:?MarketingProfile,
     *   reason:string,
     *   email_matches:Collection<int,MarketingProfile>,
     *   phone_matches:Collection<int,MarketingProfile>
     * }
     */
    public function match(?string $normalizedEmail, ?string $normalizedPhone, ?int $tenantId = null): array
    {
        /** @var Collection<int,MarketingProfile> $emailMatches */
        $emailMatches = $normalizedEmail
            ? MarketingProfile::query()
                ->forTenantId($tenantId)
                ->where('normalized_email', $normalizedEmail)
                ->get()
            : collect();

        $phoneCandidates = $this->normalizer->phoneMatchCandidates($normalizedPhone);

        /** @var Collection<int,MarketingProfile> $phoneMatches */
        $phoneMatches = $phoneCandidates !== []
            ? MarketingProfile::query()
                ->forTenantId($tenantId)
                ->whereIn('normalized_phone', $phoneCandidates)
                ->get()
            : collect();

        $emailCount = $emailMatches->count();
        $phoneCount = $phoneMatches->count();

        if ($normalizedEmail === null && $normalizedPhone === null) {
            return $this->result('skip', null, 'missing_identifiers', $emailMatches, $phoneMatches);
        }

        if ($emailCount > 1 || $phoneCount > 1) {
            return $this->result('review', null, 'ambiguous_exact_match', $emailMatches, $phoneMatches);
        }

        if ($emailCount === 1 && $phoneCount === 1) {
            $emailProfile = $emailMatches->first();
            $phoneProfile = $phoneMatches->first();

            if ($emailProfile && $phoneProfile && $emailProfile->id === $phoneProfile->id) {
                return $this->result('matched', $emailProfile, 'exact_email_phone', $emailMatches, $phoneMatches);
            }

            return $this->result('review', null, 'email_phone_conflict', $emailMatches, $phoneMatches);
        }

        if ($emailCount === 1) {
            return $this->result('matched', $emailMatches->first(), 'exact_email', $emailMatches, $phoneMatches);
        }

        if ($phoneCount === 1) {
            return $this->result('matched', $phoneMatches->first(), 'exact_phone', $emailMatches, $phoneMatches);
        }

        return $this->result('create', null, 'no_exact_match', $emailMatches, $phoneMatches);
    }

    /**
     * @param Collection<int,MarketingProfile> $emailMatches
     * @param Collection<int,MarketingProfile> $phoneMatches
     * @return array{
     *   outcome:string,
     *   profile:?MarketingProfile,
     *   reason:string,
     *   email_matches:Collection<int,MarketingProfile>,
     *   phone_matches:Collection<int,MarketingProfile>
     * }
     */
    protected function result(
        string $outcome,
        ?MarketingProfile $profile,
        string $reason,
        Collection $emailMatches,
        Collection $phoneMatches
    ): array {
        return [
            'outcome' => $outcome,
            'profile' => $profile,
            'reason' => $reason,
            'email_matches' => $emailMatches,
            'phone_matches' => $phoneMatches,
        ];
    }
}
