<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_fleet_tracking_settings')) {
            return;
        }

        if (! Schema::hasColumn('tenant_fleet_tracking_settings', 'approval_basis')) {
            Schema::table('tenant_fleet_tracking_settings', function (Blueprint $table): void {
                $table->string('approval_basis', 24)->nullable()->after('policy_sha256');
            });
        }

        if (! Schema::hasColumn('tenant_fleet_tracking_settings', 'approval_reference')) {
            Schema::table('tenant_fleet_tracking_settings', function (Blueprint $table): void {
                $table->string('approval_reference', 500)->nullable()->after('approval_basis');
            });
        }

        if (! Schema::hasColumn('tenant_fleet_tracking_settings', 'approved_at')) {
            Schema::table('tenant_fleet_tracking_settings', function (Blueprint $table): void {
                $table->timestamp('approved_at')->nullable()->after('approval_reference');
            });
        }

        if (! Schema::hasColumn('tenant_fleet_tracking_settings', 'approved_by_user_id')) {
            Schema::table('tenant_fleet_tracking_settings', function (Blueprint $table): void {
                $table->unsignedBigInteger('approved_by_user_id')->nullable()->after('approved_at');
            });
        }

        if ($this->approvalForeignKeyName() === null) {
            Schema::table('tenant_fleet_tracking_settings', function (Blueprint $table): void {
                $table->foreign('approved_by_user_id', 'tfts_approved_by_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        DB::table('tenant_fleet_tracking_settings')->whereNull('approval_basis')->whereNotNull('legal_reviewed_at')->update([
            'approval_basis' => 'counsel',
            'approval_reference' => DB::raw('counsel_review_reference'),
            'approved_at' => DB::raw('legal_reviewed_at'),
            'approved_by_user_id' => DB::raw('legal_reviewed_by_user_id'),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_fleet_tracking_settings')) {
            return;
        }

        $foreignKeyName = $this->approvalForeignKeyName();
        if ($foreignKeyName !== null) {
            Schema::table('tenant_fleet_tracking_settings', function (Blueprint $table) use ($foreignKeyName): void {
                $table->dropForeign($foreignKeyName);
            });
        }

        $columns = collect(['approval_basis', 'approval_reference', 'approved_at', 'approved_by_user_id'])
            ->filter(fn (string $column): bool => Schema::hasColumn('tenant_fleet_tracking_settings', $column))
            ->values()->all();
        if ($columns !== []) {
            Schema::table('tenant_fleet_tracking_settings', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function approvalForeignKeyName(): ?string
    {
        $foreign = collect(Schema::getForeignKeys('tenant_fleet_tracking_settings'))->first(
            fn (array $foreign): bool => in_array('approved_by_user_id', (array) ($foreign['columns'] ?? []), true)
        );

        return is_array($foreign) && is_string($foreign['name'] ?? null) ? $foreign['name'] : null;
    }
};
