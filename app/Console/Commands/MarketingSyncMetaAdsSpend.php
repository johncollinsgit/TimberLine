<?php

namespace App\Console\Commands;

use App\Services\Marketing\MetaAdsSpendSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarketingSyncMetaAdsSpend extends Command
{
    protected $signature = 'marketing:sync-meta-ads-spend
        {--tenant-id= : Restrict execution to a tenant id (required)}
        {--store-key=retail : Store key context used for analytics joins}
        {--account-id= : Meta ad account id (without act_ prefix)}
        {--since= : Sync records on/after this date (YYYY-MM-DD)}
        {--until= : Sync records on/before this date (YYYY-MM-DD)}
        {--dry-run : Preview sync without writing spend rows}';

    protected $description = 'Sync Meta Ads daily spend/performance rows into canonical marketing spend storage.';

    public function handle(MetaAdsSpendSyncService $syncService): int
    {
        $tenantId = $this->tenantIdOption();
        if ($tenantId === null) {
            $this->error('Missing required --tenant-id. Meta spend sync is tenant-scoped.');

            return self::FAILURE;
        }

        $result = $syncService->sync([
            'tenant_id' => $tenantId,
            'store_key' => trim((string) $this->option('store-key')) ?: 'retail',
            'account_id' => $this->nullableString($this->option('account-id')),
            'since' => $this->parseDate($this->option('since')),
            'until' => $this->parseDate($this->option('until')),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        $this->line('status='.(string) ($result['status'] ?? 'unknown'));
        if (isset($result['reason'])) {
            $this->line('reason='.(string) $result['reason']);
        }
        $this->line('run_id='.(string) ($result['run_id'] ?? 'n/a'));

        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        foreach (['processed', 'created', 'updated', 'unchanged', 'errors'] as $key) {
            $this->line($key.'='.(int) ($summary[$key] ?? 0));
        }
        if (isset($summary['date_from'])) {
            $this->line('date_from='.(string) $summary['date_from']);
        }
        if (isset($summary['date_to'])) {
            $this->line('date_to='.(string) $summary['date_to']);
        }
        if (isset($result['message'])) {
            $this->line('message='.(string) $result['message']);
        }

        return (string) ($result['status'] ?? '') === 'ok'
            ? self::SUCCESS
            : self::FAILURE;
    }

    protected function tenantIdOption(): ?int
    {
        $value = $this->option('tenant-id');
        if (! is_numeric($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $resolved = trim((string) $value);

        return $resolved !== '' ? $resolved : null;
    }

    protected function parseDate(mixed $value): ?CarbonImmutable
    {
        $resolved = $this->nullableString($value);
        if ($resolved === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($resolved);
        } catch (\Throwable) {
            $this->warn("Invalid date '{$resolved}', ignoring.");

            return null;
        }
    }
}
