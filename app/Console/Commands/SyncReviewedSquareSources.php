<?php

namespace App\Console\Commands;

use App\Models\TenantMarketingSetting;
use App\Services\Marketing\SquareSourceRefresh;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncReviewedSquareSources extends Command
{
    protected $signature = 'customer-sources:sync-square {--tenant-id=} {--limit=500}';

    protected $description = 'Refresh only explicitly enrolled original Square readers; source-only history, no campaigns or rewards.';

    public function handle(SquareSourceRefresh $service): int
    {
        $tenant = $this->option('tenant-id');
        if ($tenant !== null && (! ctype_digit((string) $tenant) || (int) $tenant < 1)) {
            $this->error('Use a positive --tenant-id.');

            return self::FAILURE;
        }
        $ids = TenantMarketingSetting::where('key', SquareSourceRefresh::KEY)->where('value->enabled', true)
            ->when($tenant, fn ($q) => $q->where('tenant_id', (int) $tenant))->orderBy('tenant_id')->pluck('tenant_id');
        $failed = false;
        foreach ($ids as $id) {
            try {
                $result = Cache::lock('square-reviewed-source-refresh:'.$id, 300)->get(fn () => $service->runTenant((int) $id, (int) $this->option('limit')));
                if ($result === false) {
                    continue;
                }
                $this->line(json_encode(['tenant_id' => (int) $id, 'runs' => array_map(fn ($run) => ['status' => $run['status'], 'run_id' => $run['run_id'] ?? null], $result)], JSON_THROW_ON_ERROR));
                $failed = $failed || collect($result)->contains(fn ($run) => ! in_array($run['status'] ?? '', ['completed', 'partial'], true));
            } catch (\Throwable $error) {
                $failed = true;
                $this->warn('tenant='.$id.' source_refresh_requires_review ('.class_basename($error).')');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
