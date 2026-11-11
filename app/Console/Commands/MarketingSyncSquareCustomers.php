<?php

namespace App\Console\Commands;

use App\Services\Marketing\SquareMarketingSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarketingSyncSquareCustomers extends Command
{
    protected $signature = 'marketing:sync-square-customers
        {--limit= : Maximum records to process (omit for full sync)}
        {--since= : Sync records on/after this datetime}
        {--cursor= : Resume from a provider cursor}
        {--resume-run-id= : Resume from the checkpoint of a previous square_customers_sync run}
        {--checkpoint-every=100 : Persist run checkpoint every N processed rows}
        {--dry-run : Preview changes without writing source/profile rows}
        {--owner-id= : Authorize an owner-initiated history refresh}
        {--request-id= : Stable UUID for an owner-initiated refresh}
        {--connection-id= : Original verified Square connection to read}
        {--connection-generation= : Expected generation of the reviewed connection}
        {--create-unmatched : Create contact-only customers for genuinely unmatched original Square IDs}
        {--tenant-id= : Restrict execution to a tenant id (required)}';

    protected $description = 'Sync Square customers into marketing source tables and identity layer.';

    public function handle(SquareMarketingSyncService $syncService): int
    {
        $tenantId = $this->tenantIdOption();
        if ($tenantId === null) {
            $this->error('Missing required --tenant-id. Square customer sync is tenant-scoped in MT-2D.');

            return self::FAILURE;
        }
        $owner = $this->option('owner-id') ? \App\Models\User::findOrFail((int) $this->option('owner-id')) : null;
        if ($owner) {
            app(\App\Services\Marketing\SquareCustomerIdentityService::class)->authorize($tenantId, $owner);
        }
        if (($this->option('create-unmatched') && ! $owner) || ($owner && ! \Illuminate\Support\Str::isUuid((string) $this->option('request-id')))) {
            $this->error('Owner refresh requires --owner-id and a stable UUID --request-id.');

            return self::FAILURE;
        }

        $cursor = trim((string) $this->option('cursor')) ?: null;
        $resumeRunId = $this->optionalInt($this->option('resume-run-id'));
        if ($cursor === null && $resumeRunId !== null) {
            try {
                $cursor = $this->checkpointCursorFromRun($resumeRunId, 'square_customers_sync', $tenantId);
            } catch (\RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        $options = [
            'limit' => $this->optionalInt($this->option('limit')),
            'since' => $this->parseSince((string) $this->option('since')),
            'cursor' => $cursor,
            'resume_run_id' => $resumeRunId,
            'checkpoint_every' => max(1, (int) ($this->optionalInt($this->option('checkpoint-every')) ?? 100)),
            'dry_run' => (bool) $this->option('dry-run'),
            'tenant_id' => $tenantId,
            'manual_refresh' => $owner !== null, 'created_by' => $owner?->id, 'request_id' => $this->option('request-id'),
            'create_unmatched' => (bool) $this->option('create-unmatched'), 'historical_source_only' => true,
        ];
        if ($this->option('connection-id')) {
            $options['connection_id'] = $this->option('connection-id');
        }
        if ($this->option('connection-generation')) {
            $options['expected_connection_generation'] = $this->option('connection-generation');
        }
        $result = $syncService->syncCustomers($options);

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
     * @param  array<string,mixed>  $result
     */
    protected function renderResult(array $result): int
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $this->line('status='.(string) ($result['status'] ?? 'unknown'));
        $this->line('run_id='.(string) ($result['run_id'] ?? 'n/a'));
        if (isset($result['reason'])) {
            $this->line('reason='.(string) $result['reason']);
        }
        foreach (['processed', 'created', 'updated', 'profiles_created', 'profiles_updated', 'links_created', 'links_reused', 'reviews_created', 'records_skipped', 'errors'] as $key) {
            $this->line($key.'='.(int) ($summary[$key] ?? 0));
        }

        return in_array($result['status'] ?? '', ['completed', 'partial', 'skipped'], true) && (int) ($summary['errors'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function checkpointCursorFromRun(int $runId, string $type, int $tenantId): ?string
    {
        $run = \App\Models\MarketingImportRun::tenantScopedRun($runId, $type, $tenantId);

        if (! $run) {
            throw new \RuntimeException("Run {$runId} is not accessible for tenant {$tenantId} ({$type}).");
        }

        if (! is_numeric($run->tenant_id) || (int) $run->tenant_id <= 0) {
            throw new \RuntimeException("Run {$runId} is missing tenant ownership and cannot be resumed safely.");
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

    protected function tenantIdOption(): ?int
    {
        $value = $this->option('tenant-id');
        if ($value === null || $value === '') {
            return null;
        }

        if (! ctype_digit((string) $value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }
}
