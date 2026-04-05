<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_consent_events')) {
            return;
        }

        Schema::table('marketing_consent_events', function (Blueprint $table): void {
            if ($this->hasColumns([
                'tenant_id',
                'channel',
                'marketing_profile_id',
                'occurred_at',
                'id',
            ])) {
                try {
                    $table->index(
                        ['tenant_id', 'channel', 'marketing_profile_id', 'occurred_at', 'id'],
                        'mce_tenant_channel_profile_occurred_id_idx'
                    );
                } catch (\Throwable) {
                }
            }

            if ($this->hasColumns([
                'tenant_id',
                'channel',
                'event_type',
                'source_type',
                'marketing_profile_id',
            ])) {
                try {
                    $table->index(
                        ['tenant_id', 'channel', 'event_type', 'source_type', 'marketing_profile_id'],
                        'mce_tenant_channel_event_source_profile_idx'
                    );
                } catch (\Throwable) {
                }
            }
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
};
