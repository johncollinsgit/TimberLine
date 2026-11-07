<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Services\Pos\PosRewardRedemptionSettings;

/** Keep customer redemption inaccessible until the complete payment lifecycle is verified. */
final class ModernForestryRewardAvailability
{
    public function __construct(private ModernForestryRewardIdentity $identity, private PosRewardRedemptionSettings $settings) {}

    public function available(ModernForestryMobileCustomerSession $session): bool
    {
        return config('pos_live.durable_start_claims_enabled', false) === true
            && \Illuminate\Support\Facades\Schema::hasTable('customer_account_fences') && $this->identity->verified($session)
            && $this->settings->availableForTenant((int) $session->profile->tenant_id);
    }
}
