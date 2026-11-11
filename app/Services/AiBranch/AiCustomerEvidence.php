<?php

namespace App\Services\AiBranch;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;

/** Customer availability in Marketing does not authorize Square-derived AI context. */
class AiCustomerEvidence
{
    public function allowed(Tenant $tenant, int $profileId): bool
    {
        // Read current provenance each time, including retained aliases. An import may
        // add a Square link after this conversation (or service instance) was created.
        $profile = MarketingProfile::query()->whereKey($profileId)->first(['id', 'tenant_id', 'merged_into_profile_id', 'archived_at', 'source_channels']);
        if (! $profile || (int) $profile->tenant_id !== (int) $tenant->id || $profile->archived_at || $profile->merged_into_profile_id) {
            return false;
        }
        $ids = [];
        $frontier = collect([$profile]);
        for ($depth = 0; $frontier->isNotEmpty() && $depth < 32; $depth++) {
            foreach ($frontier as $row) {
                if ((int) $row->tenant_id !== (int) $tenant->id || isset($ids[$row->id]) || count($ids) >= 1000) {
                    return false;
                }
                $channels = $row->source_channels ?? [];
                if (! is_array($channels) || collect($channels)->contains(fn ($channel) => ! is_string($channel)
                    || in_array(strtolower(trim($channel)), ['square', 'legacy_square_marketing'], true) || str_starts_with(strtolower(trim($channel)), 'square_'))) {
                    return false;
                }
                $ids[$row->id] = true;
            }
            // Include malformed cross-tenant alias edges only to reject ambiguous
            // scope, never to read their names or admit them as tenant evidence.
            $frontier = MarketingProfile::query()->whereIn('merged_into_profile_id', $frontier->pluck('id'))
                ->limit(1001)->get(['id', 'tenant_id', 'merged_into_profile_id', 'source_channels']);
        }
        if ($frontier->isNotEmpty()) {
            return false;
        }

        return ! MarketingProfileLink::query()->whereIn('marketing_profile_id', array_keys($ids))
            ->whereIn('source_type', ['square', 'square_customer', 'square_order', 'square_payment', 'square_marketing_contact'])->exists();
    }
}
