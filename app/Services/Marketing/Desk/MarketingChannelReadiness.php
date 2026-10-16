<?php

namespace App\Services\Marketing\Desk;

use App\Services\Marketing\MarketingEmailReadiness;
use App\Services\Marketing\Messaging\TenantMessagingAccountResolver;
use App\Services\Tenancy\TenantModuleAccessResolver;

class MarketingChannelReadiness
{
    public function ready(int $tenantId, string $channel): bool
    {
        if (! app(TenantModuleAccessResolver::class)->canAccess($tenantId, $channel === 'email' ? 'email' : 'messaging')) {
            return false;
        }
        if ($channel === 'email') {
            return (bool) (app(MarketingEmailReadiness::class)->getReadinessForTenant($tenantId)['can_send_live'] ?? false);
        }
        if (config('marketing.sms.dry_run')) {
            return false;
        }
        try {
            $resolver = app(TenantMessagingAccountResolver::class);
            $resolved = $resolver->resolve($tenantId, 'sms');
            if ($resolved['mode'] === 'tenant') {
                return $resolved['account']->isReady() && filled(data_get($resolved['account']->credentials, 'auth_token')) && filled($resolved['account']->provider_resource_id);
            }

            return $resolver->isLegacyTenant($tenantId) && config('marketing.sms.enabled') && config('marketing.twilio.enabled') && filled(config('marketing.twilio.account_sid')) && filled(config('marketing.twilio.auth_token')) && (filled(config('marketing.twilio.messaging_service_sid')) || filled(config('marketing.twilio.from_number')));
        } catch (\RuntimeException) {
            return false;
        }
    }
}
