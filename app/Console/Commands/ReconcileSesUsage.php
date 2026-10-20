<?php

namespace App\Console\Commands;

use App\Models\MarketingEmailDelivery;
use App\Models\TenantMessagingLedgerEntry;
use App\Services\Marketing\Email\SesSendSafety;
use Illuminate\Console\Command;

class ReconcileSesUsage extends Command
{
    protected $signature = 'email:ses-reconcile-usage {tenant : Exact tenant ID} {--apply : Settle only existing accepted SES reservations at their original recorded rates}';

    protected $description = 'Preview or reconcile accepted SES usage reservations; never sends email or creates an invoice';

    public function handle(SesSendSafety $safety): int
    {
        $tenantId = filter_var($this->argument('tenant'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $tenantId) {
            $this->error('An exact positive tenant ID is required.');

            return self::FAILURE;
        }
        $pending = 0;
        $settled = 0;
        MarketingEmailDelivery::query()->where('tenant_id', $tenantId)->where('provider', 'ses_tenant')
            ->where('metadata->ses_attempt->result->success', true)->chunkById(100, function ($deliveries) use ($tenantId, $safety, &$pending, &$settled): void {
                foreach ($deliveries as $delivery) {
                    $key = data_get($delivery->metadata, 'ses_attempt.usage_reservation.key');
                    if (! is_string($key) || $key === '' || $this->settled($tenantId, $key)) {
                        continue;
                    }
                    $pending++;
                    if ($this->option('apply')) {
                        $safety->complete($delivery->id, $tenantId, data_get($delivery->metadata, 'ses_attempt.result'));
                        $settled += (int) $this->settled($tenantId, $key);
                    }
                }
            });
        $this->line(json_encode(['tenant_id' => $tenantId, 'mode' => $this->option('apply') ? 'apply' : 'preview', 'pending' => $pending, 'settled' => $settled], JSON_THROW_ON_ERROR));

        return $this->option('apply') && $pending !== $settled ? self::FAILURE : self::SUCCESS;
    }

    protected function settled(int $tenantId, string $key): bool
    {
        return TenantMessagingLedgerEntry::query()->forAllTenants()->where('tenant_id', $tenantId)->where('idempotency_key', 'settle:'.$key)->exists();
    }
}
