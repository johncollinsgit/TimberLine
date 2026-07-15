<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Wholesale\WholesaleSuggestionGenerator;
use Illuminate\Console\Command;

class RefreshWholesaleSuggestions extends Command
{
    protected $signature = 'wholesale:suggestions:refresh {--tenant= : Required tenant ID or slug}';

    protected $description = 'Generate explainable wholesale timing suggestions from qualified wholesale orders only.';

    public function handle(WholesaleSuggestionGenerator $generator): int
    {
        $tenantOption = trim((string) $this->option('tenant'));
        if ($tenantOption === '') {
            $this->error('The --tenant option is required for manual runs.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()
            ->where(is_numeric($tenantOption) ? 'id' : 'slug', is_numeric($tenantOption) ? (int) $tenantOption : $tenantOption)
            ->first();
        if (! $tenant) {
            $this->error('Wholesale tenant not found.');

            return self::FAILURE;
        }

        $result = $generator->refresh((int) $tenant->id);
        $this->info("Evaluated {$result['evaluated']} accounts; created {$result['created']} suggestions.");

        return self::SUCCESS;
    }
}
