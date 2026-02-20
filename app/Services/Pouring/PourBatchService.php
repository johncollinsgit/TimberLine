<?php

namespace App\Services\Pouring;

use App\Models\OrderLine;
use App\Models\PourBatch;
use App\Models\PourBatchLine;
use App\Models\PourBatchPitcher;
use Illuminate\Support\Facades\DB;

class PourBatchService
{
    public function __construct(protected PourBatchCalculator $calculator)
    {
    }

    /**
     * @param array<int, OrderLine> $lines
     */
    public function createBatch(array $lines, array $meta = []): PourBatch
    {
        $calc = $this->calculator->calculate($lines);

        return DB::transaction(function () use ($lines, $meta, $calc) {
            $batch = PourBatch::create([
                'name' => $meta['name'] ?? null,
                'status' => $meta['status'] ?? 'draft',
                'selection_mode' => $meta['selection_mode'] ?? null,
                'order_type' => $meta['order_type'] ?? null,
                'wax_total_grams' => $calc['totals']['wax_grams'],
                'oil_total_grams' => $calc['totals']['oil_grams'],
                'alcohol_total_grams' => $calc['totals']['alcohol_grams'] ?? 0,
                'water_total_grams' => $calc['totals']['water_grams'] ?? 0,
                'total_grams' => $calc['totals']['total_grams'],
                'pitcher_count' => count($calc['pitchers']),
                'created_by' => $meta['created_by'] ?? null,
                'notes' => $meta['notes'] ?? null,
            ]);

            foreach ($calc['lines'] as $row) {
                PourBatchLine::create([
                    'pour_batch_id' => $batch->id,
                    'order_id' => $row['order_id'],
                    'order_line_id' => $row['order_line_id'],
                    'scent_id' => $row['scent_id'],
                    'size_id' => $row['size_id'],
                    'sku' => $row['sku'],
                    'quantity' => $row['quantity'],
                    'wax_grams' => $row['wax_grams'],
                    'oil_grams' => $row['oil_grams'],
                    'alcohol_grams' => $row['alcohol_grams'] ?? 0,
                    'water_grams' => $row['water_grams'] ?? 0,
                    'total_grams' => $row['total_grams'],
                ]);
            }

            foreach ($calc['pitchers'] as $pitcher) {
                PourBatchPitcher::create([
                    'pour_batch_id' => $batch->id,
                    'pitcher_index' => $pitcher['pitcher_index'],
                    'wax_grams' => $pitcher['wax_grams'],
                    'oil_grams' => $pitcher['oil_grams'],
                    'total_grams' => $pitcher['total_grams'],
                ]);
            }

            return $batch;
        });
    }
}
