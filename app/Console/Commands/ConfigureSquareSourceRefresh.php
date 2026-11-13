<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Marketing\SquareSourceRefresh;
use Illuminate\Console\Command;

class ConfigureSquareSourceRefresh extends Command
{
    protected $signature = 'customer-sources:configure-square-refresh {tenant} {--actor=} {--connection-id=} {--expected-version=} {--lookback-days=14} {--create-unmatched} {--all-locations} {--disable} {--apply}';

    protected $description = 'Preview or record owner-reviewed ongoing source reads for one original Square merchant and location set.';

    public function handle(SquareSourceRefresh $service): int
    {
        $tenant = (int) $this->argument('tenant');
        $actor = User::findOrFail((int) $this->option('actor'));
        app(\App\Services\Marketing\SquareCustomerIdentityService::class)->authorize($tenant, $actor);
        if (! $this->option('apply')) {
            $this->line(json_encode(['mode' => 'preview', 'current' => $service->status($tenant), 'original_connections' => app(\App\Services\Marketing\SquareConnectionContext::class)->available($tenant)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $value = $service->configure($tenant, $actor, ['enabled' => ! $this->option('disable'), 'expected_version' => $this->option('expected-version'),
            'connection_id' => $this->option('connection-id'), 'lookback_days' => (int) $this->option('lookback-days'), 'create_unmatched' => (bool) $this->option('create-unmatched'), 'all_locations' => (bool) $this->option('all-locations')]);
        $this->line(json_encode(['mode' => 'apply', 'policy' => $value], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
