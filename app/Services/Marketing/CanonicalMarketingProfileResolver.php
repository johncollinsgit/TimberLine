<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CanonicalMarketingProfileResolver
{
    public function canonical(?MarketingProfile $profile, int $tenantId): ?MarketingProfile
    {
        $seen = [];

        while ($profile instanceof MarketingProfile) {
            if ((int) $profile->tenant_id !== $tenantId || isset($seen[(int) $profile->id])) {
                return null;
            }

            $seen[(int) $profile->id] = true;
            $nextId = (int) ($profile->merged_into_profile_id ?? 0);
            if ($nextId <= 0) {
                return $profile;
            }

            $profile = MarketingProfile::query()
                ->where('tenant_id', $tenantId)
                ->find($nextId);
        }

        return null;
    }

    public function byId(int $tenantId, int $profileId): ?MarketingProfile
    {
        if ($profileId <= 0) {
            return null;
        }

        return $this->canonical(
            MarketingProfile::query()->where('tenant_id', $tenantId)->find($profileId),
            $tenantId
        );
    }

    /**
     * Resolve an email only when every matching alias points to one canonical
     * tenant profile. Ambiguous duplicate accounts intentionally fail closed.
     */
    public function byEmail(int $tenantId, string $email): ?MarketingProfile
    {
        $normalized = Str::lower(trim($email));
        if ($normalized === '') {
            return null;
        }

        return $this->oneCanonical(
            MarketingProfile::query()
                ->where('tenant_id', $tenantId)
                ->where('normalized_email', $normalized)
                ->get(),
            $tenantId
        );
    }

    /** @param Collection<int,MarketingProfile> $profiles */
    public function oneCanonical(Collection $profiles, int $tenantId): ?MarketingProfile
    {
        $canonical = $profiles
            ->map(fn (MarketingProfile $profile): ?MarketingProfile => $this->canonical($profile, $tenantId))
            ->filter()
            ->unique(fn (MarketingProfile $profile): int => (int) $profile->id)
            ->values();

        return $canonical->count() === 1 ? $canonical->first() : null;
    }
}
