<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\CustomerPurchaseGatewaySyncService;
use Illuminate\Console\Command;

class CustomerPurchaseGateways extends Command
{
    protected $signature = 'customers:payment-gateways {--tenant=} {--actor=} {--profile=} {--limit=10} {--apply : Save verified gateway labels only}';

    protected $description = 'Preview original Shopify payment gateways for one customer without changing orders or payments.';

    public function handle(CustomerPurchaseGatewaySyncService $service): int
    {
        try {
            $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->firstOrFail();
            $actor = User::findOrFail((int) $this->option('actor'));
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            abort_unless($limit !== false, 422, 'Use a limit from 1 to 20.');
            $this->line(json_encode($service->refresh($tenant->id, $actor, (int) $this->option('profile'), (bool) $this->option('apply'), $limit), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $error->getMessage() : 'Purchase gateway review needs an existing tenant, customer and authorized actor.');

            return self::FAILURE;
        }
    }
}
