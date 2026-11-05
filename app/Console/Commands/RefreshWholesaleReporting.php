<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Wholesale\WholesaleReportingSnapshot;
use App\Services\Wholesale\WholesaleSuggestionGenerator;
use Illuminate\Console\Command;

class RefreshWholesaleReporting extends Command
{
    protected $signature = 'wholesale:refresh-reporting {--tenant= : Required workspace slug}';

    protected $description = 'Refresh private wholesale history and payment facts without importing production orders.';

    public function handle(WholesaleReportingSnapshot $reporting, WholesaleSuggestionGenerator $suggestions): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();
        if (! $tenant) {
            $this->error('A valid wholesale workspace is required.');

            return self::FAILURE;
        }
        try {
            $snapshot = $reporting->refresh((int) $tenant->id);
            $suggestions->refresh((int) $tenant->id);
            $this->info('Verified '.$snapshot['source_order_count'].' source orders and refreshed wholesale suggestions.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            report($error);
            $this->error('Wholesale history could not be verified. The previous snapshot was retained.');

            return self::FAILURE;
        }
    }
}
