<?php

namespace App\Console\Commands;

use App\Services\Marketing\SquareMarketingSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarketingSyncSquareOrders extends Command
{
    protected $signature = 'marketing:sync-square-orders
        {--limit= : Maximum records to process (omit for full sync)}
        {--since= : Sync records on/after this datetime}
        {--cursor= : Resume from a provider cursor}
        {--resume-run-id= : Resume from the checkpoint of a previous square_orders_sync run}
        {--checkpoint-every=100 : Persist run checkpoint every N processed rows}
        {--dry-run : Preview changes without writing source/profile rows}';

    protected $description = 'Sync Square orders into marketing source tables and identity layer.';

    public function handle(SquareMarketingSyncService $syncService): int
    {
        $cursor = trim((string) $this->option('cursor')) ?: null;
        $resumeRunId = $this->optionalInt($this->option('resume-run-id'));
        if ($cursor === null && $resumeRunId !== null) {
            $cursor = $this->checkpointCursorFromRun($resumeRunId, 'square_orders_sync');
        }

        $result = $syncService->syncOrders([
            'limit' => $this->optionalInt($this->option('limit')),
            'since' => $this->parseSince((string) $this->option('since')),
            'cursor' => $cursor,
            'checkpoint_every' => max(1, (int) ($this->optionalInt($this->option('checkpoint-every')) ?? 100)),
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
        if (isset($result['reason'])) {
            $this->line('reason=' . (string) $result['reason']);
        }
        foreach (['processed', 'created', 'updated', 'profiles_created', 'profiles_updated', 'links_created', 'links_reused', 'reviews_created', 'records_skipped', 'errors'] as $key) {
            $this->line($key . '=' . (int) ($summary[$key] ?? 0));
        }

        return self::SUCCESS;
    }

    protected function checkpointCursorFromRun(int $runId, string $type): ?string
    {
        $run = \App\Models\MarketingImportRun::query()
            ->where('id', $runId)
            ->where('type', $type)
            ->first();

        if (! $run) {
            return null;
        }

        $cursor = data_get($run->summary, 'checkpoint.cursor');
        return is_string($cursor) && trim($cursor) !== '' ? trim($cursor) : null;
    }

    protected function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max(1, (int) $value);
    }
}
