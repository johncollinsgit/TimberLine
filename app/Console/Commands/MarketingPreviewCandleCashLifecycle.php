<?php

namespace App\Console\Commands;

use App\Services\Marketing\CandleCashLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class MarketingPreviewCandleCashLifecycle extends Command
{
    protected $signature = 'marketing:candle-cash-lifecycle-preview
        {--tenant-id= : Restrict to a tenant id}
        {--store= : Restrict to a Shopify store_key}
        {--trigger=candle_cash_reminder : Trigger key (candle_cash_earned_not_used|candle_cash_reminder|candle_cash_lapsed_with_value)}
        {--channel= : Channel eligibility filter (email|sms)}
        {--limit=100 : Max qualifying customers to display (1-500)}
        {--record-intents : Persist queued lifecycle intent events for the qualifying rows}
        {--dry-run : Show preview only even if --record-intents is passed}';

    protected $description = 'Preview tenant-safe Candle Cash lifecycle cohorts and optionally record queued reminder intents.';

    public function handle(CandleCashLifecycleService $service): int
    {
        $tenantId = is_numeric($this->option('tenant-id')) ? (int) $this->option('tenant-id') : null;
        $storeKey = $this->stringOption('store');
        $trigger = $this->stringOption('trigger');
        $channel = $this->stringOption('channel');
        $limit = is_numeric($this->option('limit')) ? (int) $this->option('limit') : 100;
        $recordIntents = (bool) $this->option('record-intents');
        $dryRun = (bool) $this->option('dry-run');

        $preview = $service->preview([
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'trigger' => $trigger,
            'channel' => $channel,
            'limit' => $limit,
        ]);

        $summary = (array) ($preview['summary'] ?? []);
        $rows = collect((array) ($preview['rows'] ?? []));

        $this->line('trigger=' . ($summary['trigger'] ?? 'unknown'));
        $this->line('channel=' . ($summary['channel'] ?? 'unknown'));
        $this->line('tenant_id=' . ($summary['tenant_id'] ?? 'all'));
        $this->line('store_key=' . ($summary['store_key'] ?? 'all'));
        $this->line('evaluated_count=' . (int) ($summary['evaluated_count'] ?? 0));
        $this->line('qualified_count=' . (int) ($summary['qualified_count'] ?? 0));

        $excludedReasons = collect((array) ($summary['excluded_reasons'] ?? []))
            ->sortDesc()
            ->map(fn ($count, $reason): string => $reason . ':' . (int) $count)
            ->implode(', ');
        $this->line('excluded_reasons=' . ($excludedReasons !== '' ? $excludedReasons : 'none'));

        $this->renderRowsTable($rows);

        if ($recordIntents && ! $dryRun && $rows->isNotEmpty()) {
            $result = $service->recordQueuedIntents($rows, [
                'store_key' => $storeKey,
            ]);
            $this->line('intents_recorded=' . (int) ($result['recorded'] ?? 0));
            $this->line('intents_skipped=' . (int) ($result['skipped'] ?? 0));
        } elseif ($recordIntents && $dryRun) {
            $this->line('intents_recorded=0');
            $this->line('intents_skipped=0');
            $this->warn('Dry run enabled, no lifecycle intent events were written.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    protected function renderRowsTable(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            $this->info('No qualifying customers matched the selected lifecycle trigger.');

            return;
        }

        $this->table(
            ['Profile', 'Name', 'Email', 'Outstanding', 'Latest Earned', 'Last Order', 'Last Redeemed', 'Reason'],
            $rows->map(fn (array $row): array => [
                (int) ($row['marketing_profile_id'] ?? 0),
                trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))),
                (string) ($row['email'] ?? 'n/a'),
                (string) ($row['formatted_outstanding_amount'] ?? '$0.00'),
                (string) ($row['latest_earned_date'] ?? 'n/a'),
                (string) ($row['last_order_at'] ?? 'n/a'),
                (string) ($row['last_redeemed_at'] ?? 'n/a'),
                (string) ($row['qualification_reason'] ?? 'n/a'),
            ])->all()
        );
    }

    protected function stringOption(string $key): ?string
    {
        $value = trim((string) $this->option($key));

        return $value !== '' ? $value : null;
    }
}

