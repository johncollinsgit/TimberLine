<?php

namespace App\Console\Commands;

use App\Models\MappingException;
use App\Models\OrderLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeWholesaleRawNames extends Command
{
    protected $signature = 'catalog:normalize-wholesale-raw {--dry-run}';
    protected $description = 'Strip "wholesale" from raw titles/variant/scent names in order_lines and mapping_exceptions.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $lines = OrderLine::query()
            ->where('raw_title', 'like', '%wholesale%')
            ->orWhere('raw_variant', 'like', '%wholesale%')
            ->get(['id', 'raw_title', 'raw_variant']);

        $exceptions = MappingException::query()
            ->where('raw_title', 'like', '%wholesale%')
            ->orWhere('raw_scent_name', 'like', '%wholesale%')
            ->get(['id', 'raw_title', 'raw_scent_name']);

        $lineUpdates = 0;
        $exceptionUpdates = 0;

        DB::transaction(function () use ($lines, $exceptions, $dryRun, &$lineUpdates, &$exceptionUpdates) {
            foreach ($lines as $line) {
                $rawTitle = $this->stripWholesale($line->raw_title);
                $rawVariant = $this->stripWholesale($line->raw_variant);

                if ($rawTitle !== $line->raw_title || $rawVariant !== $line->raw_variant) {
                    $lineUpdates++;
                    if (!$dryRun) {
                        $line->raw_title = $rawTitle;
                        $line->raw_variant = $rawVariant;
                        $line->save();
                    }
                }
            }

            foreach ($exceptions as $exception) {
                $rawTitle = $this->stripWholesale($exception->raw_title);
                $rawScent = $this->stripWholesale($exception->raw_scent_name);

                if ($rawTitle !== $exception->raw_title || $rawScent !== $exception->raw_scent_name) {
                    $exceptionUpdates++;
                    if (!$dryRun) {
                        $exception->raw_title = $rawTitle;
                        $exception->raw_scent_name = $rawScent;
                        $exception->save();
                    }
                }
            }
        });

        $this->info($dryRun
            ? "Dry run: would update {$lineUpdates} order_lines, {$exceptionUpdates} exceptions."
            : "Updated {$lineUpdates} order_lines, {$exceptionUpdates} exceptions."
        );

        return self::SUCCESS;
    }

    private function stripWholesale(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = preg_replace('/\bwholesale\b/i', '', $value) ?? $value;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        return trim($clean);
    }
}
