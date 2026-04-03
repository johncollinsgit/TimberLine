<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_message_groups')) {
            return;
        }

        Schema::table('marketing_message_groups', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_message_groups', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('marketing_message_groups', 'is_system')) {
                $table->boolean('is_system')->default(false)->after('is_reusable');
            }
            if (! Schema::hasColumn('marketing_message_groups', 'system_key')) {
                $table->string('system_key', 80)->nullable()->after('is_system');
            }
        });

        Schema::table('marketing_message_groups', function (Blueprint $table): void {
            try {
                $table->index('tenant_id', 'mmg_tenant_id_idx');
            } catch (\Throwable) {
            }
            try {
                $table->index('is_system', 'mmg_is_system_idx');
            } catch (\Throwable) {
            }
            try {
                $table->index('system_key', 'mmg_system_key_idx');
            } catch (\Throwable) {
            }
            try {
                $table->unique(['tenant_id', 'system_key'], 'mmg_tenant_system_unique');
            } catch (\Throwable) {
            }
        });

        if (Schema::hasTable('tenants') && Schema::hasColumn('marketing_message_groups', 'tenant_id')) {
            Schema::table('marketing_message_groups', function (Blueprint $table): void {
                try {
                    $table->foreign('tenant_id', 'mmg_tenant_fk')
                        ->references('id')
                        ->on('tenants')
                        ->nullOnDelete();
                } catch (\Throwable) {
                }
            });
        }

        $this->backfillTenantOwnership();
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketing_message_groups')) {
            return;
        }

        try {
            Schema::table('marketing_message_groups', function (Blueprint $table): void {
                $table->dropForeign('mmg_tenant_fk');
            });
        } catch (\Throwable) {
        }

        Schema::table('marketing_message_groups', function (Blueprint $table): void {
            foreach ([
                'mmg_tenant_system_unique',
                'mmg_system_key_idx',
                'mmg_is_system_idx',
                'mmg_tenant_id_idx',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable) {
                }
            }

            foreach (['system_key', 'is_system', 'tenant_id'] as $column) {
                if (Schema::hasColumn('marketing_message_groups', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    protected function backfillTenantOwnership(): void
    {
        if (! Schema::hasTable('marketing_message_groups')
            || ! Schema::hasColumn('marketing_message_groups', 'tenant_id')
            || ! Schema::hasTable('marketing_message_group_members')
            || ! Schema::hasTable('marketing_profiles')) {
            return;
        }

        $resolvedTenantRows = DB::table('marketing_message_group_members as mmgm')
            ->join('marketing_profiles as mp', 'mp.id', '=', 'mmgm.marketing_profile_id')
            ->whereNotNull('mp.tenant_id')
            ->selectRaw('mmgm.marketing_message_group_id as group_id, min(mp.tenant_id) as tenant_id')
            ->groupBy('mmgm.marketing_message_group_id')
            ->havingRaw('count(distinct mp.tenant_id) = 1')
            ->get();

        if ($resolvedTenantRows->isEmpty()) {
            return;
        }

        $now = now();
        foreach ($resolvedTenantRows as $row) {
            $groupId = isset($row->group_id) ? (int) $row->group_id : 0;
            $tenantId = isset($row->tenant_id) ? (int) $row->tenant_id : 0;
            if ($groupId <= 0 || $tenantId <= 0) {
                continue;
            }

            DB::table('marketing_message_groups')
                ->where('id', $groupId)
                ->whereNull('tenant_id')
                ->update([
                    'tenant_id' => $tenantId,
                    'updated_at' => $now,
                ]);
        }
    }
};
