<?php

namespace App\Console\Commands;

use App\Models\EventInstance;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MarketingBackfillEventSlugs extends Command
{
    protected $signature = 'marketing:backfill-event-slugs {--limit=500} {--dry-run}';

    protected $description = 'Backfill canonical public slugs for event instances.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $rows = EventInstance::query()
            ->where(function ($query): void {
                $query->whereNull('public_slug')->orWhere('public_slug', '');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'title', 'public_slug']);

        $used = EventInstance::query()
            ->whereNotNull('public_slug')
            ->pluck('public_slug')
            ->map(fn ($value): string => strtolower(trim((string) $value)))
            ->filter()
            ->flip()
            ->all();

        $updated = 0;
        foreach ($rows as $row) {
            $base = Str::slug((string) $row->title);
            if ($base === '') {
                $base = 'event-instance';
            }

            $candidate = $base;
            $suffix = 2;
            while (isset($used[$candidate])) {
                $candidate = $base . '-' . $suffix;
                $suffix++;
            }

            $used[$candidate] = true;
            $updated++;

            if (! $dryRun) {
                $row->forceFill(['public_slug' => $candidate])->save();
            }
        }

        $this->line('processed=' . $rows->count());
        $this->line('updated=' . $updated);
        $this->line('mode=' . ($dryRun ? 'dry-run' : 'live'));

        return self::SUCCESS;
    }
}

