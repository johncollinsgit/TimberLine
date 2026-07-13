<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\FieldService\FieldServiceJobLifecycleService;
use Illuminate\Console\Command;

class FieldServiceReconcileLifecycle extends Command
{
    protected $signature = 'field-service:reconcile-lifecycle {--tenant= : Tenant slug} {--tenant-id= : Tenant ID} {--dry-run : Preview without writing}';

    protected $description = 'Derive tenant-scoped operational job states from workspace and QuickBooks evidence.';

    public function handle(FieldServiceJobLifecycleService $lifecycle): int
    {
        $tenant = is_numeric($this->option('tenant-id'))
            ? Tenant::query()->find((int) $this->option('tenant-id'))
            : Tenant::query()->where('slug', strtolower(trim((string) $this->option('tenant'))))->first();
        if (! $tenant) {
            $this->error('Pass a valid --tenant or --tenant-id.');

            return self::FAILURE;
        }

        $summary = $lifecycle->reconcileTenant($tenant, (bool) $this->option('dry-run'));
        $this->line('mode='.($this->option('dry-run') ? 'dry-run' : 'live'));
        $this->line('tenant='.$tenant->slug);
        foreach ($summary as $key => $value) {
            $this->line($key.'='.$value);
        }

        return self::SUCCESS;
    }
}
