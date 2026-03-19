<?php

namespace App\Console\Commands;

use App\Models\MarketingImportRun;
use App\Services\Marketing\LegacyCandleCashCorrectionService;
use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Console\Command;

class MarketingRebaseCandleCashBalances extends Command
{
    protected $signature = 'marketing:rebase-candle-cash-balances
        {--factor=0.003 : Deprecated legacy option. Must match the corrected legacy points conversion rate.}
        {--run-key= : Idempotency key for the correction run}
        {--dry-run : Preview the correction without mutating balances or transactions}';

    protected $description = 'Preview or apply the one-time correction for legacy points-origin Candle Cash values.';

    public function __construct(
        protected LegacyCandleCashCorrectionService $correctionService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $factor = $this->parseFactor($this->option('factor'));
        $expectedFactor = CandleCashMeasurement::LEGACY_STARTING_CANDLE_CASH_PER_POINT;
        if (abs($factor - $expectedFactor) >= 0.0000005) {
            $this->error('Legacy Candle Cash correction now uses a fixed conversion rate of '.number_format($expectedFactor, 3, '.', '').'. Custom factors have been retired.');

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
                ->where('type', 'candle_cash_legacy_points_correction')
                ->where('source_label', $runKey)
                ->where('status', 'completed')
                ->exists();

            if ($completed) {
                $this->warn('This correction run key has already been completed.');

                return self::SUCCESS;
            }
        }

        $summary = [
            'dry_run' => $dryRun,
            'legacy_points_rate' => $expectedFactor,
        ];

        $run = MarketingImportRun::query()->create([
            'type' => 'candle_cash_legacy_points_correction',
            'status' => 'running',
            'source_label' => $runKey,
            'started_at' => now(),
            'summary' => $summary,
            'notes' => $dryRun ? 'dry_run' : 'legacy_points_origin_correction',
        ]);

        try {
            $summary = array_merge(
                $summary,
                $dryRun ? $this->correctionService->preview() : $this->correctionService->apply()
            );

            $run->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
                'summary' => $summary,
            ])->save();

            foreach ([
                'profiles',
                'legacy_transactions',
                'legacy_rebases',
                'legacy_points_total',
                'corrected_candle_cash_total',
                'legacy_rows_needing_correction',
                'legacy_rebases_needing_neutralization',
                'balances_requiring_recompute',
            ] as $key) {
                $this->line($key . '=' . (string) ($summary[$key] ?? 0));
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

    protected function parseFactor(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return (float) $value;
    }
}
