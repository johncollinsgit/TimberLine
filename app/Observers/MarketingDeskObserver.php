<?php

namespace App\Observers;

use App\Jobs\MarketingDesk\ProcessInboundMessage;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MessagingConversationMessage;
use App\Services\Marketing\Desk\MarketingDeskAccess;
use App\Services\Marketing\Desk\MarketingWorkflowDelivery;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class MarketingDeskObserver implements ShouldHandleEventsAfterCommit
{
    public function created(MessagingConversationMessage|MarketingProfile|CustomerExternalProfile $message): void
    {
        if (! $message instanceof MessagingConversationMessage) {
            return;
        }
        $tenantId = (int) $message->tenant_id;
        if ($message->direction !== 'inbound' || ! app(MarketingDeskAccess::class)->available($tenantId)) {
            return;
        }
        if ($message->marketing_profile_id) {
            app(MarketingWorkflowDelivery::class)->stop($tenantId, $message->marketing_profile_id, 'customer_replied');
        }
        if ($message->marketing_profile_id) {
            $profile = MarketingProfile::forTenantId($tenantId)->find($message->marketing_profile_id);
            if ($profile) {
                $permissions = \App\Models\MarketingAudiencePermission::forTenantId($tenantId)->where('marketing_profile_id', $profile->id)->where('store_key', $message->store_key ?? '')->whereNull('revoked_at')->get()->unique('audience');
                foreach ($permissions as $permission) {
                    app(\App\Services\Marketing\Desk\MarketingSignalService::class)->record($tenantId, $profile, $permission->audience, 'inbound', 'inbound:'.$message->id, $permission->store_key);
                }
            }
        }
        ProcessInboundMessage::dispatch($tenantId, $message->id)->afterCommit();
    }

    public function updated(MarketingProfile|CustomerExternalProfile|MessagingConversationMessage $record): void
    {
        if ($record instanceof MessagingConversationMessage) {
            return;
        }
        $tenantId = (int) $record->tenant_id;
        if (! app(MarketingDeskAccess::class)->available($tenantId)) {
            return;
        }
        $id = $record instanceof MarketingProfile ? $record->id : $record->marketing_profile_id;
        if (! $id) {
            return;
        }
        if ($record instanceof CustomerExternalProfile && $record->wasChanged('last_order_at')) {
            app(MarketingWorkflowDelivery::class)->stop($tenantId, $id, 'customer_purchased');
        }
        if ($record instanceof MarketingProfile) {
            foreach (['email', 'sms'] as $channel) {
                if (! $record->getAttribute('accepts_'.$channel.'_marketing') || $record->getAttribute($channel.'_opted_out_at')) {
                    \App\Models\MarketingAudiencePermission::forTenantId($tenantId)->where('marketing_profile_id', $id)->where('channel', $channel)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                }
            }
        }
        if ($record instanceof MarketingProfile && ($record->wasChanged(['accepts_email_marketing', 'accepts_sms_marketing', 'archived_at', 'merged_into_profile_id']))) {
            app(MarketingWorkflowDelivery::class)->stop($tenantId, $id, 'customer_permissions_changed');
        }
    }
}
