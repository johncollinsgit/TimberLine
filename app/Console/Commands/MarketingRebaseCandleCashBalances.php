<?php

namespace App\Console\Commands;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingImportRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarketingRebaseCandleCashBalances extends Command
{
    protected $signature = 'marketing:rebase-candle-cash-balances
        {--factor=0.3333333333 : Multiplier applied to current positive balances}
        {--run-key= : Idempotency key for the rebase run}
        {--dry-run : Preview the rebase without writing balances or transactions}';

    protected $description = 'Apply a one-time proportional rebase to existing Candle Cash balances.';

    public function handle(): int
    {
        $factor = $this->parseFactor($this->option('factor'));
        if ($factor <= 0 || $factor >= 1) {
            $this->error('The rebase factor must be greater than 0 and less than 1.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $runKey = trim((string) $this->option('run-key'));

        if ($runKey === '' && ! $dryRun) {
            $this->error('A --run-key is required for non-dry-run execution.');

            return self::FAILURE;
        }

        if ($runKey === '') {
            $runKey = 'dry-run:' . now()->format('YmdHis');
        }

        if (! $dryRun) {
            $completed = MarketingImportRun::query()
                ->where('type', 'candle_cash_balance_rebase')
                ->where('source_label', $runKey)
                ->where('status', 'completed')
                ->exists();

            if ($completed) {
                $this->warn('This rebase run key has already been completed.');

                return self::SUCCESS;
            }
        }

        $summary = [
            'dry_run' => $dryRun,
            'factor' => $factor,
            'processed' => 0,
            'adjusted' => 0,
            'unchanged' => 0,
            'original_points' => 0,
            'target_points' => 0,
            'reduced_points' => 0,
        ];

        $run = MarketingImportRun::query()->create([
            'type' => 'candle_cash_balance_rebase',
            'status' => 'running',
            'source_label' => $runKey,
            'started_at' => now(),
            'summary' => $summary,
            'notes' => $dryRun ? 'dry_run' : 'legacy_balance_rebase',
        ]);

        try {
            CandleCashBalance::query()
                ->where('balance', '>', 0)
                ->orderBy('marketing_profile_id')
                ->chunkById(200, function ($balances) use (&$summary, $factor): void {
                    foreach ($balances as $balance) {
                        $current = max(0, (int) $balance->balance);
                        $target = max(0, (int) round($current * $factor));
                        $delta = $target - $current;

                        $summary['processed']++;
                        $summary['original_points'] += $current;
                        $summary['target_points'] += $target;

                        if ($delta === 0) {
                            $summary['unchanged']++;
                            continue;
                        }

                        $summary['adjusted']++;
                        $summary['reduced_points'] += abs($delta);
                    }
                }, 'marketing_profile_id');

            if (! $dryRun) {
                $this->applyRebase($factor, $runKey, $summary);
            }

            $run->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
                'summary' => $summary,
            ])->save();

            foreach (['processed', 'adjusted', 'unchanged', 'original_points', 'target_points', 'reduced_points'] as $key) {
                $this->line($key . '=' . $summary[$key]);
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'summary' => $summary,
                'notes' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }

    protected function applyRebase(float $factor, string $runKey, array &$summary): void
    {
        CandleCashBalance::query()
            ->where('balance', '>', 0)
            ->orderBy('marketing_profile_id')
            ->chunkById(200, function ($balances) use ($factor, $runKey): void {
                foreach ($balances as $balance) {
                    DB::transaction(function () use ($balance, $factor, $runKey): void {
                        $locked = CandleCashBalance::query()
                            ->lockForUpdate()
                            ->where('marketing_profile_id', $balance->marketing_profile_id)
                            ->firstOrFail();

                        $current = max(0, (int) $locked->balance);
                        if ($current <= 0) {
                            return;
                        }

                        $target = max(0, (int) round($current * $factor));
                        $delta = $target - $current;
                        if ($delta === 0) {
                            return;
                        }

                        $sourceId = $runKey . ':' . $locked->marketing_profile_id;
                        if (CandleCashTransaction::query()
                            ->where('marketing_profile_id', $locked->marketing_profile_id)
                            ->where('source', 'legacy_rebase')
                            ->where('source_id', $sourceId)
                            ->exists()) {
                            return;
                        }

                        $locked->forceFill(['balance' => $target])->save();

                        CandleCashTransaction::query()->create([
                            'marketing_profile_id' => $locked->marketing_profile_id,
                            'type' => 'adjustment',
                            'points' => $delta,
                            'source' => 'legacy_rebase',
                            'source_id' => $sourceId,
                            'description' => 'Legacy Candle Cash rebase (factor ' . rtrim(rtrim(number_format($factor, 10, '.', ''), '0'), '.') . ')',
                        ]);
                    });
                }
            }, 'marketing_profile_id');
    }

    protected function parseFactor(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return (float) $value;
    }
}
