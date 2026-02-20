<?php

namespace App\Services\Pouring;

use App\Models\OrderLine;
use App\Models\PourBatch;
use App\Models\PourBatchLine;
use Illuminate\Support\Facades\DB;

class PourBatchCompletionService
{
    public function __construct(protected OilConsumptionService $oil)
    {
    }

    public function completeLine(PourBatchLine $line, ?int $userId = null): void
    {
        if ($line->completed_at) {
            return;
        }

        DB::transaction(function () use ($line, $userId) {
            $line->status = 'completed';
            $line->completed_at = now();
            $line->save();

            if ($line->order_line_id) {
                $orderLine = OrderLine::query()->find($line->order_line_id);
                if ($orderLine) {
                    $this->oil->consumeForLine($orderLine, (float) $line->oil_grams, 'pour_completed', $userId);
                }
            }
        });
    }

    public function completeBatch(PourBatch $batch, ?int $userId = null): void
    {
        DB::transaction(function () use ($batch, $userId) {
            foreach ($batch->lines as $line) {
                $this->completeLine($line, $userId);
            }

            $batch->status = 'completed';
            $batch->completed_at = now();
            $batch->save();
        });
    }
}
