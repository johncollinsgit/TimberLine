<?php

namespace App\Console\Commands;

use App\Models\TenantMessagingAccount;
use App\Services\Marketing\Messaging\TenantMessagingProvisioningService;
use Illuminate\Console\Command;
use Throwable;

class EverbranchRefreshMessagingVerifications extends Command
{
    protected $signature = 'everbranch:refresh-messaging-verifications {--limit=100 : Maximum pending tenants to check}';

    protected $description = 'Refresh carrier approval for allowlisted tenant SMS registrations.';

    public function handle(TenantMessagingProvisioningService $provisioning): int
    {
        if (! (bool) config('features.tenant_messaging_auto_bootstrap')
            || ! (bool) config('features.tenant_messaging_provisioning')) {
            $this->line('Automatic tenant messaging is disabled; nothing to refresh.');

            return self::SUCCESS;
        }

        $allowed = array_map('intval', (array) config('marketing.messaging.platform.automatic_tenant_ids', []));
        $accounts = TenantMessagingAccount::query()->forAllTenants()
            ->whereIn('tenant_id', $allowed)
            ->where('channel', 'sms')
            ->whereIn('status', ['pending_verification', 'needs_changes'])
            ->orderBy('id')
            ->limit(max(1, min(500, (int) $this->option('limit'))))
            ->get();

        $failures = 0;
        foreach ($accounts as $account) {
            try {
                $refreshed = $provisioning->refreshSmsVerification((int) $account->tenant_id);
                $this->line('tenant='.$account->tenant_id.' status='.$refreshed->status);
            } catch (Throwable $exception) {
                report($exception);
                $failures++;
                $this->warn('tenant='.$account->tenant_id.' refresh_failed='.$exception->getMessage());
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
