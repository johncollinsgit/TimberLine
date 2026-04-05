<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_consent_events')) {
            return;
        }

        if ($this->hasColumns([
            'tenant_id',
            'channel',
            'marketing_profile_id',
            'occurred_at',
            'id',
        ]) && ! $this->indexExists('marketing_consent_events', 'mce_tenant_channel_profile_occurred_id_idx')) {
            Schema::table('marketing_consent_events', function (Blueprint $table): void {
                $table->index(
                    ['tenant_id', 'channel', 'marketing_profile_id', 'occurred_at', 'id'],
                    'mce_tenant_channel_profile_occurred_id_idx'
                );
            });
        }

        if (! $this->hasColumns([
            'tenant_id',
            'channel',
            'marketing_profile_id',
            'event_type',
            'source_type',
        ]) || $this->indexExists('marketing_consent_events', 'mce_tenant_channel_event_source_profile_idx')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement(
                'CREATE INDEX mce_tenant_channel_event_source_profile_idx'
                .' ON marketing_consent_events (tenant_id, channel(32), marketing_profile_id, event_type(32), source_type(64))'
            );

            return;
        }

        Schema::table('marketing_consent_events', function (Blueprint $table): void {
            $table->index(
                ['tenant_id', 'channel', 'marketing_profile_id', 'event_type', 'source_type'],
                'mce_tenant_channel_event_source_profile_idx'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketing_consent_events')) {
            return;
        }

        Schema::table('marketing_consent_events', function (Blueprint $table): void {
            foreach ([
                'mce_tenant_channel_event_source_profile_idx',
                'mce_tenant_channel_profile_occurred_id_idx',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable) {
                }
            }
        });
    }

    /**
     * @param  array<int,string>  $columns
     */
    protected function hasColumns(array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn('marketing_consent_events', $column)) {
                return false;
            }
        }

        return true;
    }

    protected function indexExists(string $table, string $index): bool
    {
        if ($this->isSqlite()) {
            return collect(DB::select('PRAGMA index_list("' . $table . '")'))
                ->contains(fn ($row) => ($row->name ?? null) === $index);
        }

        return collect(DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$index]))->isNotEmpty();
    }

    protected function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }
};
