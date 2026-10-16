<?php

namespace App\Services\Marketing\Desk;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingAudiencePermission;
use App\Models\MarketingProfile;
use App\Models\WholesaleProspect;
use App\Services\Automation\V2\WorkflowDomainEventRecorder;

class MarketingSignalService
{
    public function record(int $tenantId, MarketingProfile $profile, string $audience, string $event, string $proof, string $storeKey = '', ?int $prospectId = null): void
    {
        abort_unless((int) $profile->tenant_id === $tenantId && app(MarketingDeskAccess::class)->available($tenantId), 403);
        abort_unless(in_array($audience, ['retail', 'wholesale', 'services'], true) && in_array($event, ['subscription', 'segment', 'inquiry', 'fulfilled', 'sample_delivered', 'completed', 'inactive_90', 'inactive_60', 'inbound'], true) && trim($proof) !== '', 422);
        if ($prospectId) {
            WholesaleProspect::forTenantId($tenantId)->findOrFail($prospectId);
        }
        $key = hash('sha256', implode('|', [$tenantId, $profile->id, $audience, $event, $proof]));
        app(WorkflowDomainEventRecorder::class)->record($tenantId, 'everbranch.marketing.'.$audience.'.'.$event, $profile, [
            'marketing_profile_id' => (string) $profile->id, 'store_key' => $storeKey, 'marketing_event_key' => $key,
            'wholesale_prospect_id' => $prospectId ? (string) $prospectId : '',
        ], $key);
    }

    public function scanInactive(int $tenantId): int
    {
        if (! app(MarketingDeskAccess::class)->available($tenantId)) {
            return 0;
        }
        $count = 0;
        foreach (['retail' => 90, 'wholesale' => 60] as $audience => $days) {
            CustomerExternalProfile::forTenantId($tenantId)->whereNotNull('marketing_profile_id')
                ->where('last_order_at', '<=', now()->subDays($days))->where('synced_at', '>=', now()->subDay())
                ->orderBy('id')->chunkById(200, function ($sources) use ($tenantId, $audience, $days, &$count): void {
                    foreach ($sources as $source) {
                        $profile = MarketingProfile::forTenantId($tenantId)->find($source->marketing_profile_id);
                        if (! $profile || ! MarketingAudiencePermission::forTenantId($tenantId)->where('marketing_profile_id', $profile->id)->where('audience', $audience)->where('store_key', $source->store_key ?? '')->whereNull('revoked_at')->exists()) {
                            continue;
                        }
                        $latest = CustomerExternalProfile::forTenantId($tenantId)->where('marketing_profile_id', $profile->id)->where('store_key', $source->store_key)->max('last_order_at');
                        if ((string) $latest !== $source->getRawOriginal('last_order_at')) {
                            continue;
                        }
                        $this->record($tenantId, $profile, $audience, 'inactive_'.$days, 'last-order:'.$source->id.':'.$latest, $source->store_key ?? '');
                        $count++;
                    }
                });
        }

        return $count;
    }
}
