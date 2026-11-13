<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Marketing\SquareCustomerReferenceReconciliation;
use App\Services\Marketing\SquareSourceRefresh;
use Illuminate\Console\Command;

class ReconcileSquareCustomerReferences extends Command
{
    protected $signature = 'customer-sources:reconcile-square-references {tenant} {--actor=} {--policy-version=} {--request-id=} {--after=} {--limit=500} {--apply}';

    protected $description = 'Reconcile original Square customer redirects and retain linked historical purchases without customer merges or permission grants.';

    public function handle(SquareCustomerReferenceReconciliation $service): int
    {
        $tenant = (int) $this->argument('tenant');
        $actor = User::findOrFail((int) $this->option('actor'));
        $version = $this->option('policy-version') ?: app(SquareSourceRefresh::class)->status($tenant)['version'];
        $result = $service->batch($tenant, $actor, $version, (string) $this->option('request-id'),
            $this->option('after') ?: null, (int) $this->option('limit'), (bool) $this->option('apply'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
