<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Subscriptions\SubscriptionAccessService;
use App\Services\Subscriptions\SubscriptionProviderReadService;
use Illuminate\Console\Command;

class SubscriptionProviderRead extends Command
{
    protected $signature = 'subscriptions:provider-read {action : readiness, verify, correct-setup, probe, or sync}
        {--tenant= : Explicit workspace ID} {--actor= : Active workspace manager user ID}
        {--installation= : Explicit subscription installation ID} {--store= : Explicit tenant-owned ShopifyStore ID}
        {--request= : Idempotent UUID for an explicit provider read}
        {--shop-gid=} {--app-gid=} {--installation-gid=} {--client-id=}
        {--cycle-start=1} {--cycle-end=12} {--restart : Explicitly discard incomplete sync cursors and begin a new generation}';

    protected $description = 'Inspect offline readiness or explicitly read a verified subscription installation; never changes Shopify or charges.';

    public function handle(SubscriptionProviderReadService $service, SubscriptionAccessService $access): int
    {
        try {
            $tenantId = $this->positive('tenant');
            $actor = User::query()->findOrFail($this->positive('actor'));
            $access->assertManager($tenantId, $actor);
            $action = (string) $this->argument('action');
            $result = match ($action) {
                'readiness' => $service->readiness($tenantId, $this->positive('installation')),
                'verify' => $service->verifyInstallation($tenantId, $actor, $this->positive('store'), [
                    'shop_gid' => (string) $this->option('shop-gid'), 'app_gid' => (string) $this->option('app-gid'),
                    'app_installation_gid' => (string) $this->option('installation-gid'), 'public_client_id' => (string) $this->option('client-id'),
                ], (string) $this->option('request')),
                'probe' => $service->probe($tenantId, $actor, $this->positive('installation'), (string) $this->option('request')),
                'correct-setup' => $service->correctPendingSetup($tenantId, $actor, $this->positive('installation'), [
                    'shop_gid' => (string) $this->option('shop-gid'), 'app_gid' => (string) $this->option('app-gid'),
                    'app_installation_gid' => (string) $this->option('installation-gid'), 'public_client_id' => (string) $this->option('client-id'),
                ], (string) $this->option('request')),
                'sync' => $service->syncOwnedContracts($tenantId, $actor, $this->positive('installation'), (string) $this->option('request'),
                    ['cycle_start' => $this->positive('cycle-start'), 'cycle_end' => $this->positive('cycle-end'), 'restart' => (bool) $this->option('restart')]),
                default => throw new \InvalidArgumentException,
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return isset($result['status']) && $result['status'] !== 'completed' ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            // Console output must not contain model/HTTP exceptions, URLs,
            // decrypted envelopes or credentials from a failed read.
            $this->error('Subscription read unavailable. Check explicit IDs, manager access, rollout gates and safe operation status.');

            return self::FAILURE;
        }
    }

    private function positive(string $option): int
    {
        $value = $this->option($option);
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]{0,9}$/D', $value)) {
            throw new \InvalidArgumentException;
        }

        return (int) $value;
    }
}
