<?php

namespace App\Console\Commands;

use App\Models\Scent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MergeDuplicateScents extends Command
{
    protected $signature = 'catalog:merge-duplicate-scents {--dry-run}';
    protected $description = 'Merge duplicate scents by normalized name and rewire references.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $scents = Scent::query()->get();
        $groups = $scents->groupBy(fn ($s) => Scent::normalizeName($s->name));

        $tables = [
            ['table' => 'order_lines', 'column' => 'scent_id'],
            ['table' => 'retail_plan_items', 'column' => 'scent_id'],
            ['table' => 'pour_batch_lines', 'column' => 'scent_id'],
            ['table' => 'wholesale_custom_scents', 'column' => 'canonical_scent_id'],
            ['table' => 'candle_club_scents', 'column' => 'scent_id'],
        ];

        $merged = 0;
        $skipped = 0;

        DB::transaction(function () use ($groups, $tables, $dryRun, &$merged, &$skipped) {
            foreach ($groups as $normalized => $items) {
                if ($normalized === '') {
                    $skipped += $items->count();
                    continue;
                }

                if ($items->count() <= 1) {
                    continue;
                }

                $keep = $items->sortBy('id')->first();
                $dupes = $items->where('id', '!=', $keep->id);

                $bestName = $this->bestName($items->pluck('name')->all());
                $bestDisplay = $this->bestName($items->pluck('display_name')->all());
                $bestAbbr = $this->bestNonEmpty($items->pluck('abbreviation')->all());
                $bestOil = $this->bestNonEmpty($items->pluck('oil_reference_name')->all());

                if (!$dryRun) {
                    if ($bestName && $this->isNameAvailable($bestName, $keep->id)) {
                        $keep->name = $bestName;
                    }
                    if ($bestDisplay) {
                        $keep->display_name = $bestDisplay;
                    }
                    if ($bestAbbr) {
                        $keep->abbreviation = $bestAbbr;
                    }
                    if ($bestOil) {
                        $keep->oil_reference_name = $bestOil;
                    }
                    $keep->save();
                }

                foreach ($dupes as $dupe) {
                    foreach ($tables as $ref) {
                        if (Schema::hasTable($ref['table']) && Schema::hasColumn($ref['table'], $ref['column'])) {
                            if (!$dryRun) {
                                DB::table($ref['table'])
                                    ->where($ref['column'], $dupe->id)
                                    ->update([$ref['column'] => $keep->id]);
                            }
                        }
                    }

                    if (!$dryRun) {
                        $dupe->delete();
                    }
                    $merged++;
                }
            }
        });

        $this->info($dryRun
            ? "Dry run complete. Duplicates detected: {$merged}. Skipped: {$skipped}."
            : "Merge complete. Duplicates merged: {$merged}. Skipped: {$skipped}."
        );

        return self::SUCCESS;
    }

    private function bestName(array $names): string
    {
        $candidates = array_filter(array_map(function ($name) {
            $name = is_string($name) ? trim($name) : '';
            if ($name === '') {
                return null;
            }
            // Strip "wholesale" while preserving casing/spacing.
            $name = preg_replace('/\bwholesale\b/i', '', $name);
            $name = preg_replace('/\s+/', ' ', $name);
            return trim($name);
        }, $names));
        usort($candidates, function ($a, $b) {
            return strlen($a) <=> strlen($b);
        });

        return $candidates[0] ?? '';
    }

    private function bestNonEmpty(array $values): ?string
    {
        $candidates = array_values(array_filter(array_map(function ($v) {
            $v = is_string($v) ? trim($v) : '';
            return $v !== '' ? $v : null;
        }, $values)));

        return $candidates[0] ?? null;
    }

    private function isNameAvailable(string $name, int $keepId): bool
    {
        return !Scent::query()
            ->where('name', $name)
            ->where('id', '!=', $keepId)
            ->exists();
    }
}
