<?php

namespace App\Console\Commands;

use App\Services\FieldService\ServiceMembershipService;
use Illuminate\Console\Command;

class ServiceMembershipGenerateVisits extends Command
{
    protected $signature = 'field-service:generate-membership-visits
        {--tenant-id= : Limit generation to one tenant ID}
        {--days-ahead=30 : Generate due visits this many days ahead}';

    protected $description = 'Create idempotent field-service jobs for active customer service memberships.';

    public function handle(ServiceMembershipService $memberships): int
    {
        $tenantId = is_numeric($this->option('tenant-id')) ? (int) $this->option('tenant-id') : null;
        $daysAhead = min(365, max(0, (int) $this->option('days-ahead')));
        $result = $memberships->generateDueVisits($tenantId, $daysAhead);

        $this->info(sprintf('Created %d membership visits and %d jobs.', $result['visits_created'], $result['jobs_created']));

        return self::SUCCESS;
    }
}
