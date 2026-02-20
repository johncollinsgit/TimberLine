<?php

namespace App\Console\Commands;

use App\Models\Scent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NormalizeWholesaleScentNames extends Command
{
    protected $signature = 'catalog:normalize-wholesale-scents {--dry-run}';
    protected $description = 'Remove "wholesale" prefix from scent names and merge duplicates safely.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $scents = Scent::query()
            ->where('name', 'like', '%wholesale%')
            ->orWhere('display_name', 'like', '%wholesale%')
            ->get();

        if ($scents->isEmpty()) {
            $this->info('No wholesale-prefixed scents found.');
            return self::SUCCESS;
        }

        $tables = [
            ['table' => 'order_lines', 'column' => 'scent_id'],
            ['table' => 'retail_plan_items', 'column' => 'scent_id'],
            ['table' => 'pour_batch_lines', 'column' => 'scent_id'],
            ['table' => 'wholesale_custom_scents', 'column' => 'canonical_scent_id'],
            ['table' => 'candle_club_scents', 'column' => 'scent_id'],
        ];

        $renamed = 0;
        $merged = 0;

        DB::transaction(function () use ($scents, $tables, $dryRun, &$renamed, &$merged) {
            foreach ($scents as $scent) {
                $clean = $this->cleanName($scent->display_name ?: $scent->name);
                if ($clean === '') {
                    continue;
                }

                $normalized = Scent::normalizeName($clean);

                $target = Scent::query()->get()->first(function (Scent $other) use ($normalized, $scent) {
                    return $other->id !== $scent->id && Scent::normalizeName($other->name) === $normalized;
                });

                if ($target) {
                    foreach ($tables as $ref) {
                        if (Schema::hasTable($ref['table']) && Schema::hasColumn($ref['table'], $ref['column'])) {
                            if (!$dryRun) {
                                DB::table($ref['table'])
                                    ->where($ref['column'], $scent->id)
                                    ->update([$ref['column'] => $target->id]);
                            }
                        }
                    }
                    if (!$dryRun) {
                        $scent->delete();
                    }
                    $merged++;
                    continue;
                }

                if (!$dryRun) {
                    $scent->name = $clean;
                    $scent->display_name = $clean;
                    $scent->save();
                }
                $renamed++;
            }
        });

        $this->info($dryRun
            ? "Dry run complete. Renamed: {$renamed}, merged: {$merged}."
            : "Normalization complete. Renamed: {$renamed}, merged: {$merged}."
        );

        return self::SUCCESS;
    }

    private function cleanName(string $value): string
    {
        $clean = trim($value);
        $clean = preg_replace('/\bwholesale\b/i', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        return trim($clean);
    }
}
