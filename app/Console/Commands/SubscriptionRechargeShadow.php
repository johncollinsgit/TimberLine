<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Subscriptions\SubscriptionAccessService;
use App\Services\Subscriptions\SubscriptionRechargeReadService;
use Illuminate\Console\Command;

class SubscriptionRechargeShadow extends Command
{
    protected $signature = 'subscriptions:recharge-shadow {action : readiness, verify, capture, or refresh-customer}
        {--tenant=} {--actor=} {--connection=} {--source=}
        {--store-id=} {--identifier=} {--platform=} {--request=} {--customer=}
        {--page-size=50} {--restart : Explicitly restart the incomplete shadow generation}';

    protected $description = 'Inspect Recharge shadow readiness or capture one explicitly authorized read slice; never enables servicing or cutover.';

    public function handle(SubscriptionRechargeReadService $reads, SubscriptionAccessService $access): int
    {
        try {
            $tenantId = $this->positive('tenant');
            $actor = User::query()->findOrFail($this->positive('actor'));
            $access->assertManager($tenantId, $actor);
            $result = match ($this->argument('action')) {
                'readiness' => $reads->readiness($tenantId, $this->positive('connection')),
                'verify' => $reads->verifyConnection($tenantId, $actor, $this->positive('source'), [
                    'provider_store_id' => (string) $this->option('store-id'), 'identifier' => (string) $this->option('identifier'), 'platform' => (string) $this->option('platform'),
                ], (string) $this->option('request')),
                'capture' => $reads->sync($tenantId, $actor, $this->positive('connection'), (string) $this->option('request'), ['limit' => $this->positive('page-size'), 'restart' => (bool) $this->option('restart')]),
                'refresh-customer' => $reads->refreshCustomer($tenantId, $actor, $this->positive('connection'), (string) $this->option('customer'), (string) $this->option('request')),
                default => throw new \InvalidArgumentException,
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return isset($result['status']) && $result['status'] !== 'completed' ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Recharge shadow read unavailable. Check explicit IDs, manager access, rollout gates and safe operation status.');

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
