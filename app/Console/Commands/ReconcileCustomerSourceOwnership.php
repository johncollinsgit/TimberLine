<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Marketing\CustomerSourceOwnershipReconciliation;
use Illuminate\Console\Command;

class ReconcileCustomerSourceOwnership extends Command
{
    protected $signature = 'marketing:reconcile-customer-sources {tenant} {--actor=} {--after-id=0} {--limit=500} {--apply}';

    protected $description = 'Preview or restore proven existing customer source ownership without merging, consent changes or reward issuance.';

    public function handle(CustomerSourceOwnershipReconciliation $service): int
    {
        $actor = User::findOrFail((int) $this->option('actor'));
        $result = $service->batch((int) $this->argument('tenant'), $actor, (int) $this->option('after-id'), (int) $this->option('limit'), (bool) $this->option('apply'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
