<?php

namespace App\Console\Commands;

use App\Models\OrderLine;
use App\Models\Size;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillOrderLineSizes extends Command
{
    protected $signature = 'catalog:backfill-orderline-sizes {--dry-run}';
    protected $description = 'Backfill size_id on order_lines from raw_variant/size_code text.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $sizeIndex = $this->buildSizeIndex();

        $lines = OrderLine::query()
            ->whereNull('size_id')
            ->where(function ($q) {
                $q->whereNotNull('raw_variant')->orWhereNotNull('size_code');
            })
            ->get(['id', 'raw_variant', 'size_code']);

        $updated = 0;

        DB::transaction(function () use ($lines, $sizeIndex, $dryRun, &$updated) {
            foreach ($lines as $line) {
                $candidate = $line->raw_variant ?? $line->size_code ?? '';
                $normalized = $this->normalizeSize((string) $candidate);
                if ($normalized === '') {
                    continue;
                }

                $sizeId = $sizeIndex[$normalized] ?? null;
                if (!$sizeId) {
                    continue;
                }

                $updated++;
                if (!$dryRun) {
                    $line->size_id = $sizeId;
                    $line->save();
                }
            }
        });

        $this->info($dryRun
            ? "Dry run: would update {$updated} lines."
            : "Updated {$updated} order lines."
        );

        return self::SUCCESS;
    }

    private function buildSizeIndex(): array
    {
        return Size::query()
            ->select(['id', 'code', 'label'])
            ->get()
            ->mapWithKeys(function (Size $size) {
                $keys = [];
                if (!empty($size->code)) {
                    $keys[$this->normalizeSize($size->code)] = $size->id;
                }
                if (!empty($size->label)) {
                    $keys[$this->normalizeSize($size->label)] = $size->id;
                }
                return $keys;
            })
            ->all();
    }

    private function normalizeSize(string $value): string
    {
        $lower = strtolower($value);
        $lower = str_replace([' ', '-', '_'], '', $lower);
        $lower = str_replace(['ounces', 'ounce'], 'oz', $lower);
        $lower = str_replace('o z', 'oz', $lower);
        $lower = preg_replace('/[^a-z0-9]+/i', '', $lower) ?? '';
        if ($lower === 'waxmelt') {
            $lower = 'waxmelts';
        }
        if ($lower === 'roomspray') {
            $lower = 'roomsprays';
        }
        return $lower;
    }
}
