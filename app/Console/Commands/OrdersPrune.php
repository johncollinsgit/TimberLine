<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delete orders placed before a cutoff (keyed on ordered_at), scoped to a tenant.
 *
 * Unlike orders:purge (which truncates everything and orphans rows in ~8 tables),
 * this command:
 *   - only touches orders with a non-null ordered_at strictly before --before,
 *   - DELETEs order-owned artifacts (lines, scent splits, mapping/import rows),
 *   - NULLs the order_id on value-bearing records (loyalty, reviews, attribution,
 *     pour/retail lines) so history/finance rows survive,
 *   - reports everything up front and supports --dry-run,
 *   - backs up the sqlite file before deleting.
 */
class OrdersPrune extends Command
{
    protected $signature = 'orders:prune
        {--before=2026-01-01 : Delete orders whose ordered_at is before this date (YYYY-MM-DD)}
        {--tenant=1 : Tenant id to scope the prune to}
        {--dry-run : Report what would be deleted without changing anything}
        {--force : Skip the interactive confirmation (required for non-interactive/prod runs)}
        {--skip-backup : Do not attempt a sqlite database file backup}';

    protected $description = 'Delete orders placed before a cutoff date for one tenant, cleaning up dependent rows safely.';

    /** Tables whose rows are meaningless without the order -> delete them. */
    private const DELETE_TABLES = [
        'mapping_exceptions' => 'order_id',
        'import_normalizations' => 'order_id',
    ];

    /** Tables that carry standalone value -> null the order reference, keep the row. */
    private const NULL_TABLES = [
        'pour_batch_lines' => 'order_id',
        'retail_plan_items' => 'order_id',
        'marketing_message_order_attributions' => 'order_id',
        'marketing_review_histories' => 'order_id',
        'birthday_reward_issuances' => 'order_id',
        'candle_cash_redemptions' => 'external_order_id',
        'candle_cash_referrals' => 'qualifying_order_id',
    ];

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');

        try {
            $cutoff = Carbon::parse((string) $this->option('before'))->startOfDay();
        } catch (\Throwable $e) {
            $this->error('Invalid --before date: '.$this->option('before'));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // The set of orders to remove: this exact WHERE is reused everywhere via subquery,
        // so we never materialise a huge id list (avoids SQLite's bound-parameter limit).
        $targets = fn () => DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('ordered_at')
            ->where('ordered_at', '<', $cutoff);

        $orderCount = (clone $targets())->count();
        $nullOrderedAt = DB::table('orders')->where('tenant_id', $tenantId)->whereNull('ordered_at')->count();

        $this->info(sprintf(
            'Prune plan — tenant %d, orders with ordered_at before %s',
            $tenantId,
            $cutoff->toDateString(),
        ));
        $this->line(sprintf('  Orders to delete: <comment>%d</comment>', $orderCount));
        if ($nullOrderedAt > 0) {
            $this->line(sprintf('  Orders with NULL ordered_at (left untouched): %d', $nullOrderedAt));
        }

        if ($orderCount === 0) {
            $this->info('Nothing to prune. Done.');

            return self::SUCCESS;
        }

        // Report dependent rows.
        $rows = [];

        $lineIdsSub = fn () => DB::table('order_lines')->select('id')->whereIn('order_id', $targets()->select('id'));

        if (Schema::hasTable('order_lines')) {
            $lineCount = DB::table('order_lines')->whereIn('order_id', $targets()->select('id'))->count();
            $rows[] = ['order_lines', 'order_id', 'DELETE', $lineCount];

            if (Schema::hasTable('order_line_scent_splits') && Schema::hasColumn('order_line_scent_splits', 'order_line_id')) {
                $splitCount = DB::table('order_line_scent_splits')->whereIn('order_line_id', $lineIdsSub()->select('id'))->count();
                $rows[] = ['order_line_scent_splits', 'order_line_id', 'DELETE', $splitCount];
            }
        }

        foreach (self::DELETE_TABLES as $table => $column) {
            if (! $this->tableRef($table, $column)) {
                continue;
            }
            $count = DB::table($table)->whereIn($column, $targets()->select('id'))->count();
            $rows[] = [$table, $column, 'DELETE', $count];
        }

        foreach (self::NULL_TABLES as $table => $column) {
            if (! $this->tableRef($table, $column)) {
                continue;
            }
            $count = DB::table($table)->whereIn($column, $targets()->select('id'))->count();
            $rows[] = [$table, $column, 'NULL', $count];
        }

        $this->newLine();
        $this->table(['Table', 'Column', 'Action', 'Rows'], $rows);

        if ($dryRun) {
            $this->info('Dry run — no changes made.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf('Delete %d orders (and clean up the rows above)? This cannot be undone.', $orderCount))) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $this->backupSqliteIfPossible();

        $deleted = DB::transaction(function () use ($targets, $lineIdsSub): array {
            $result = [];

            if (Schema::hasTable('order_lines')) {
                if (Schema::hasTable('order_line_scent_splits') && Schema::hasColumn('order_line_scent_splits', 'order_line_id')) {
                    // Snapshot the line ids first — order_lines are deleted below.
                    $lineIds = $lineIdsSub()->pluck('id')->all();
                    if ($lineIds !== []) {
                        $result['order_line_scent_splits'] = DB::table('order_line_scent_splits')
                            ->whereIn('order_line_id', $lineIds)->delete();
                    }
                }
                $result['order_lines'] = DB::table('order_lines')->whereIn('order_id', $targets()->select('id'))->delete();
            }

            foreach (self::DELETE_TABLES as $table => $column) {
                if (! $this->tableRef($table, $column)) {
                    continue;
                }
                $result[$table] = DB::table($table)->whereIn($column, $targets()->select('id'))->delete();
            }

            foreach (self::NULL_TABLES as $table => $column) {
                if (! $this->tableRef($table, $column)) {
                    continue;
                }
                $result[$table.' (nulled)'] = DB::table($table)
                    ->whereIn($column, $targets()->select('id'))
                    ->update([$column => null]);
            }

            $result['orders'] = $targets()->delete();

            return $result;
        });

        $this->newLine();
        foreach ($deleted as $label => $count) {
            $this->line(sprintf('  %-38s %d', $label, $count));
        }
        $this->info(sprintf('Pruned %d orders for tenant %d.', $deleted['orders'] ?? 0, $tenantId));

        return self::SUCCESS;
    }

    private function tableRef(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    private function backupSqliteIfPossible(): void
    {
        if ($this->option('skip-backup')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->warn('Non-sqlite connection: no automatic backup taken. Ensure you have a database backup before proceeding.');

            return;
        }

        $path = DB::connection()->getDatabaseName();
        if (! is_string($path) || $path === ':memory:' || ! is_file($path)) {
            return;
        }

        $backup = $path.'.prune-backup-'.now()->format('Ymd-His');
        if (@copy($path, $backup)) {
            $this->info('Backed up database to '.$backup);
        } else {
            $this->warn('Could not create a backup copy of '.$path);
        }
    }
}
