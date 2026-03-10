<?php

namespace App\Console\Commands;

use App\Services\Marketing\SquareMarketingSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarketingSyncSquarePayments extends Command
{
    protected $signature = 'marketing:sync-square-payments
        {--limit=200 : Maximum records to process}
        {--since= : Sync records on/after this datetime}
        {--cursor= : Resume from a provider cursor}
        {--dry-run : Preview changes without writing source/profile rows}';

    protected $description = 'Sync Square payments into marketing source tables and identity layer.';

    public function handle(SquareMarketingSyncService $syncService): int
    {
        $result = $syncService->syncPayments([
            'limit' => max(1, (int) $this->option('limit')),
            'since' => $this->parseSince((string) $this->option('since')),
            'cursor' => $this->option('cursor'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        return $this->renderResult($result);
    }

    protected function parseSince(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable $e) {
            $this->warn("Invalid --since value '{$value}', ignoring.");
            return null;
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    protected function renderResult(array $result): int
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $this->line('status=' . (string) ($result['status'] ?? 'unknown'));
        $this->line('run_id=' . (string) ($result['run_id'] ?? 'n/a'));
        foreach (['processed', 'created', 'updated', 'profiles_created', 'profiles_updated', 'links_created', 'links_reused', 'reviews_created', 'records_skipped', 'errors'] as $key) {
            $this->line($key . '=' . (int) ($summary[$key] ?? 0));
        }

        return self::SUCCESS;
    }
}
