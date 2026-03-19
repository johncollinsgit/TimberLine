<?php

namespace App\Console\Commands;

use App\Services\Marketing\CandleCashLegacyCompatibilityService;
use Illuminate\Console\Command;

class MarketingCandleCashCompatibilityReadiness extends Command
{
    protected $signature = 'marketing:candle-cash-compatibility-readiness
        {--json : Output the readiness summary as JSON}
        {--reset : Clear recorded runtime compatibility observations before reporting}';

    protected $description = 'Summarize runtime and static Candle Cash legacy compatibility usage before dropping old points_* columns.';

    public function handle(CandleCashLegacyCompatibilityService $service): int
    {
        if ((bool) $this->option('reset')) {
            $cleared = $service->reset();
            $this->info('Cleared '.$cleared.' recorded legacy compatibility observation(s).');
        }

        $summary = $service->summary();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $observed = (array) data_get($summary, 'observed.rows', []);
        $this->info('Candle Cash legacy compatibility readiness');
        $this->line('ready_to_drop_old_columns=' . (data_get($summary, 'go_no_go.ready_to_drop_old_columns') ? 'yes' : 'no'));

        $this->table(
            ['Operation', 'Hits'],
            collect((array) data_get($summary, 'observed.by_operation', []))
                ->map(fn ($hits, $operation): array => [(string) $operation, (int) $hits])
                ->values()
                ->all()
        );

        if ($observed === []) {
            $this->info('No runtime legacy compatibility signals have been observed.');
        } else {
            $this->table(
                ['Path', 'Operation', 'Context', 'Hits', 'Last Seen'],
                collect($observed)
                    ->map(fn (array $row): array => [
                        (string) ($row['path'] ?? ''),
                        (string) ($row['operation'] ?? ''),
                        (string) ($row['context'] ?? ''),
                        (int) ($row['hits'] ?? 0),
                        (string) ($row['last_seen_at'] ?? 'n/a'),
                    ])->all()
            );
        }

        $this->line('legacy_only_rows=' . (int) data_get($summary, 'static_audit.columns.total_legacy_only_rows', 0));
        $this->line('diverged_rows=' . (int) data_get($summary, 'static_audit.columns.total_diverged_rows', 0));
        $this->line('active_legacy_env_count=' . (int) data_get($summary, 'static_audit.env.active_legacy_env_count', 0));

        $blockingReasons = (array) data_get($summary, 'go_no_go.blocking_reasons', []);
        if ($blockingReasons !== []) {
            $this->warn('Blocking reasons:');
            foreach ($blockingReasons as $reason) {
                $this->line('- ' . $reason);
            }
        }

        return self::SUCCESS;
    }
}
