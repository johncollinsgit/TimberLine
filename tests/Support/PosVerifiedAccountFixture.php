<?php

namespace Tests\Support;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Pos\PosVerifiedCustomerAccount;

final class PosVerifiedAccountFixture
{
    public static function create(MarketingProfile $profile, ?string $digits = null): int
    {
        $digits ??= (string) (100000 + $profile->id);
        $gid = 'gid://shopify/Customer/'.$digits;
        $meta = ['verification_method' => 'new_profile_authenticated_customer_account', 'shopify_store_key' => 'retail',
            'shopify_customer_gid' => $gid, 'verified_profile_id' => $profile->id, 'verified_at' => now()->toIso8601String(),
            'signature' => app(PosVerifiedCustomerAccount::class)->signature((int) $profile->tenant_id, (int) $profile->id, 'retail', $gid)];
        $accountId = 0;
        foreach ([PosVerifiedCustomerAccount::SOURCE, 'shopify_customer'] as $type) {
            $link = MarketingProfileLink::create(['tenant_id' => $profile->tenant_id, 'marketing_profile_id' => $profile->id,
                'source_type' => $type, 'source_id' => 'retail:'.$digits, 'source_meta' => $meta,
                'match_method' => 'customer_verified_account_link', 'confidence' => 1]);
            if ($type === PosVerifiedCustomerAccount::SOURCE) {
                $accountId = (int) $link->id;
            }
        }

        return $accountId;
    }
}
