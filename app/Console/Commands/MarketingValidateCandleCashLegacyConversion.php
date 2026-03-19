<?php

namespace App\Console\Commands;

use App\Services\Marketing\CandleCashLegacyConversionValidationService;
use Illuminate\Console\Command;

class MarketingValidateCandleCashLegacyConversion extends Command
{
    protected $signature = 'marketing:validate-candle-cash-legacy-conversion
        {--limit=5 : Number of sample rows per section}
        {--json : Output the validation summary as JSON}';

    protected $description = 'Summarize post-migration validation signals for the corrected Candle Cash legacy-points conversion.';

    public function handle(CandleCashLegacyConversionValidationService $service): int
    {
        $summary = $service->summary((int) $this->option('limit'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ((string) data_get($summary, 'status', 'ready') !== 'ready') {
            $this->warn((string) data_get($summary, 'message', 'Candle Cash validation cannot run yet.'));

            return self::SUCCESS;
        }

        $this->info('Candle Cash legacy conversion validation');
        $this->line('legacy_candidate_rows=' . (int) data_get($summary, 'legacy.candidate_rows', 0));
        $this->line('legacy_tagged_rows=' . (int) data_get($summary, 'legacy.tagged_rows', 0));
        $this->line('untagged_legacy_candidate_rows=' . (int) data_get($summary, 'legacy.untagged_candidate_rows', 0));
        $this->line('legacy_rows_needing_correction=' . (int) data_get($summary, 'legacy.preview.legacy_rows_needing_correction', 0));
        $this->line('corrected_legacy_candle_cash_total=' . (float) data_get($summary, 'legacy.expected_candle_cash_total', 0));
        $this->line('balance_mismatches=' . (int) data_get($summary, 'balances.mismatch_count', 0));
        $this->line('modern_fractional_rows=' . (int) data_get($summary, 'modern.fractional_row_count', 0));

        $this->table(
            ['Category', 'Count'],
            [
                ['legacy_only_profiles', count((array) data_get($summary, 'profiles.legacy_only', []))],
                ['mixed_profiles', count((array) data_get($summary, 'profiles.mixed', []))],
                ['modern_only_profiles', count((array) data_get($summary, 'profiles.modern_only', []))],
            ]
        );

        $this->renderTable('Balance mismatch samples', ['Profile', 'Email', 'Stored', 'Ledger'], collect((array) data_get($summary, 'balances.sample_mismatches', []))
            ->map(fn (array $row): array => [
                (int) ($row['marketing_profile_id'] ?? 0),
                (string) ($row['email'] ?? ''),
                number_format((float) ($row['stored_balance'] ?? 0), 3, '.', ''),
                number_format((float) ($row['ledger_balance'] ?? 0), 3, '.', ''),
            ])->all());

        $this->renderTable('Legacy-only sample profiles', ['Profile', 'Email', 'Ledger', 'Stored'], collect((array) data_get($summary, 'profiles.legacy_only', []))
            ->map(fn (array $row): array => [
                (int) ($row['marketing_profile_id'] ?? 0),
                (string) ($row['email'] ?? ''),
                number_format((float) ($row['ledger_balance'] ?? 0), 3, '.', ''),
                number_format((float) ($row['stored_balance'] ?? 0), 3, '.', ''),
            ])->all());

        $this->renderTable('Mixed sample profiles', ['Profile', 'Email', 'Legacy Rows', 'Modern Rows', 'Ledger', 'Stored'], collect((array) data_get($summary, 'profiles.mixed', []))
            ->map(fn (array $row): array => [
                (int) ($row['marketing_profile_id'] ?? 0),
                (string) ($row['email'] ?? ''),
                (int) ($row['legacy_row_count'] ?? 0),
                (int) ($row['modern_row_count'] ?? 0),
                number_format((float) ($row['ledger_balance'] ?? 0), 3, '.', ''),
                number_format((float) ($row['stored_balance'] ?? 0), 3, '.', ''),
            ])->all());

        $this->renderTable('Modern fractional row samples', ['Transaction', 'Profile', 'Email', 'Source', 'Delta'], collect((array) data_get($summary, 'modern.sample_fractional_rows', []))
            ->map(fn (array $row): array => [
                (int) ($row['transaction_id'] ?? 0),
                (int) ($row['marketing_profile_id'] ?? 0),
                (string) ($row['email'] ?? ''),
                (string) ($row['source'] ?? ''),
                number_format((float) ($row['candle_cash_delta'] ?? 0), 3, '.', ''),
            ])->all());

        return self::SUCCESS;
    }

    /**
     * @param  array<int,array<int|string>>  $rows
     */
    protected function renderTable(string $title, array $headers, array $rows): void
    {
        $this->line('');
        $this->info($title);

        if ($rows === []) {
            $this->line('No rows.');

            return;
        }

        $this->table($headers, $rows);
    }
}
