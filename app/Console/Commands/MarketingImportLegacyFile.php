<?php

namespace App\Console\Commands;

use App\Services\Marketing\MarketingLegacyImportService;
use Illuminate\Console\Command;

class MarketingImportLegacyFile extends Command
{
    protected $signature = 'marketing:import-legacy-file
        {type : yotpo_contacts_import|square_marketing_import}
        {file : Absolute or relative CSV path}
        {--dry-run : Validate and simulate import without persisting data}
        {--created-by= : User ID to attribute the import run}';

    protected $description = 'Import a legacy marketing contacts CSV from disk into the canonical marketing pipeline.';

    public function handle(MarketingLegacyImportService $importService): int
    {
        $type = (string) $this->argument('type');
        $file = (string) $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');
        $createdBy = $this->option('created-by');
        $createdBy = is_numeric($createdBy) ? (int) $createdBy : null;

        try {
            $result = $importService->importPath(
                path: $file,
                type: $type,
                createdBy: $createdBy,
                dryRun: $dryRun,
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $summary = (array) ($result['summary'] ?? []);

        $this->line($dryRun ? 'mode=dry-run' : 'mode=live');
        $this->line('run_id=' . (int) ($result['run_id'] ?? 0));
        $this->line('status=' . (string) ($result['status'] ?? 'unknown'));

        foreach ([
            'processed',
            'imported',
            'reviewed',
            'skipped',
            'failed',
            'matched_existing',
            'profiles_created',
            'profiles_updated',
            'links_created',
            'links_reused',
            'reviews_created',
            'records_skipped',
            'sms_marketable',
            'email_marketable',
            'sms_suppressed',
            'email_suppressed',
        ] as $key) {
            $this->line($key . '=' . (int) ($summary[$key] ?? 0));
        }

        return self::SUCCESS;
    }
}
