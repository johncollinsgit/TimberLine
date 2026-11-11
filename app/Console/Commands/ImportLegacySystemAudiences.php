<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Marketing\LegacySystemAudienceTemplates;
use Illuminate\Console\Command;

class ImportLegacySystemAudiences extends Command
{
    protected $signature = 'marketing:import-system-audiences {tenant} {--actor=} {--apply}';

    protected $description = 'Preview or copy unchanged legacy system audience templates as tenant-owned drafts, preserving originals.';

    public function handle(LegacySystemAudienceTemplates $service): int
    {
        $tenant = (int) $this->argument('tenant');
        $actor = User::findOrFail((int) $this->option('actor'));
        app(\App\Services\Marketing\SquareCustomerIdentityService::class)->authorize($tenant, $actor);
        $rows = $service->available();
        foreach ($rows as &$row) {
            if ($this->option('apply')) {
                $row['copied_segment_id'] = $service->copy($tenant, $actor, $row['id'], $row['fingerprint'])->id;
            }
        }
        unset($row);
        $this->line(json_encode(['mode' => $this->option('apply') ? 'apply' : 'preview', 'templates' => $rows, 'campaigns_created' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
