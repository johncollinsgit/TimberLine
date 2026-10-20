<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantPaymentAccount;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Space;
use App\Models\WebsitePayment;
use App\Services\Marketing\IntegrationHealthEventRecorder;
use Illuminate\Console\Command;

/** Local evidence only. No provider requests, customer notifications or money changes. */
class NativePaymentsHealth extends Command
{
    protected $signature = 'payments:health';

    protected $description = 'Record scoped setup, delayed payment and business-feed exceptions in integration health';

    public function handle(IntegrationHealthEventRecorder $health): int
    {
        $open = 0;
        Tenant::query()->chunkById(100, function ($tenants) use ($health, &$open) {
            foreach ($tenants as $tenant) {
                $payments = WebsitePayment::forTenantId($tenant->id)->where('provider', 'stripe')->whereIn('metadata->checkout_version', [1, 2]);
                $counts = [
                    'payment_setup_blocked' => TenantPaymentAccount::forTenantId($tenant->id)->where(function ($query) {
                        $query->whereIn('status', ['restricted', 'disabled'])->orWhere(fn ($query) => $query->where('status', 'onboarding')->where('onboarding_started_at', '<', now()->subDays(7)));
                    })->count(),
                    'payment_confirmation_delayed' => (clone $payments)->where(function ($query) {
                        $query->where(fn ($q) => $q->whereIn('status', ['creating', 'pending'])->where('created_at', '<', now()->subHours(2)))
                            ->orWhere(fn ($q) => $q->where('status', 'processing')->where('created_at', '<', now()->subDays(7)));
                    })->count(),
                    'payment_reconciliation_review' => (clone $payments)->whereIn('status', ['payment_review', 'paid_review'])->count(),
                    // Deliberate disconnects are not failures. Household feeds stay private.
                    'business_bank_feed_stale' => Connection::whereIn('space_id', Space::where('tenant_id', $tenant->id)->where('kind', 'business')->where('enabled', true)->select('id'))
                        ->where('status', 'connected')->where(fn ($query) => $query->where('synced_at', '<', now()->subHours(48))->orWhere(fn ($query) => $query->whereNull('synced_at')->where('created_at', '<', now()->subHours(48))))->count(),
                ];
                foreach ($counts as $type => $count) {
                    $scope = ['provider' => 'everbranch_payments', 'tenant_id' => $tenant->id,
                        'event_type' => $type, 'dedupe_key' => 'native-payments:'.$tenant->id.':'.$type];
                    if ($count) {
                        $health->record($scope + ['severity' => 'warning', 'context' => ['count' => $count]], 60);
                        $open++;
                    } else {
                        $health->resolve($scope);
                    }
                }
            }
        });
        $this->info($open.' payment or business-feed health categories require review.');

        return self::SUCCESS;
    }
}
