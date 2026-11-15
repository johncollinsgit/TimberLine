<?php

namespace App\Console\Commands;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Studio\EventPerformance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RefreshEventPerformanceReports extends Command
{
    protected $signature = 'team:refresh-event-performance {--limit=50}';

    protected $description = 'Queue current issuer-scoped event reporting projections without importing sources';

    public function handle(EventPerformance $reports): int
    {
        if (! config('team_operations.enabled') || ! Schema::hasTable(EventPerformance::TABLE)) {
            return self::SUCCESS;
        }
        $count = 0;
        foreach (TeamOperationRecord::where('kind', 'performance_snapshot')->orderBy('updated_at')->limit(max(1, min(500, (int) $this->option('limit'))))->cursor() as $snapshot) {
            try {
                $tenant = Tenant::findOrFail($snapshot->tenant_id);
                $user = User::findOrFail($snapshot->assigned_user_id);
                $area = TeamArea::where('tenant_id', $tenant->id)->findOrFail($snapshot->area_id);
                $reports->authorize($tenant, $user, $area);
                $reports->requestRefresh($tenant, $user, $area, $snapshot);
                $count++;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                // Revocation retires access, not source transaction evidence.
            }
        }
        $this->info('Queued reporting refreshes: '.$count);

        return self::SUCCESS;
    }
}
