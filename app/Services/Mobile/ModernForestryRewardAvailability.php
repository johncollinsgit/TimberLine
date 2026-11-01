<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Services\Pos\PosRewardRedemptionSettings;

/** Keep customer redemption inaccessible until the complete payment lifecycle is verified. */
final class ModernForestryRewardAvailability
{
    private const LIFECYCLE_READY = false;

    public function __construct(private ModernForestryRewardIdentity $identity, private PosRewardRedemptionSettings $settings) {}

    public function available(ModernForestryMobileCustomerSession $session): bool
    {
        return self::LIFECYCLE_READY && $this->identity->verified($session)
            && $this->settings->availableForTenant((int) $session->profile->tenant_id);
    }
}
