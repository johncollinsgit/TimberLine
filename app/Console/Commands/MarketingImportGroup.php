<?php

namespace App\Console\Commands;

use App\Models\MarketingGroup;
use App\Services\Marketing\MarketingGroupImportService;
use Illuminate\Console\Command;

class MarketingImportGroup extends Command
{
    protected $signature = 'marketing:import-group
        {group_id : Marketing group ID}
        {file : Absolute or relative CSV path}
        {--dry-run : Validate and simulate import without persisting membership}
        {--created-by= : User ID to attribute run + membership additions}';

    protected $description = 'Import a CSV contact file into a marketing group.';

    public function handle(MarketingGroupImportService $importService): int
    {
        $groupId = max(1, (int) $this->argument('group_id'));
        $file = (string) $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');
        $createdBy = $this->option('created-by');
        $createdBy = is_numeric($createdBy) ? (int) $createdBy : null;

        $group = MarketingGroup::query()->find($groupId);
        if (! $group) {
            $this->error("Group {$groupId} not found.");

            return self::FAILURE;
        }

        $absolutePath = realpath($file) ?: $file;
        if (! is_file($absolutePath)) {
            $this->error("CSV file not found: {$file}");

            return self::FAILURE;
        }

        $result = $importService->importFromCsv(
            group: $group,
            filePath: $absolutePath,
            createdBy: $createdBy,
            dryRun: $dryRun
        );

        $summary = (array) ($result['summary'] ?? []);
        $this->line($dryRun ? 'mode=dry-run' : 'mode=live');
        $this->line('group_id=' . $group->id);
        foreach ([
            'rows',
            'profiles_created',
            'profiles_updated',
            'links_created',
            'links_reused',
            'reviews_created',
            'records_skipped',
            'members_added',
            'members_existing',
            'errors',
        ] as $key) {
            $this->line($key . '=' . (int) ($summary[$key] ?? 0));
        }

        return self::SUCCESS;
    }
}

