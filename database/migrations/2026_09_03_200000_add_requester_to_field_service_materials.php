<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('field_service_materials')) {
            return;
        }

        if (! Schema::hasColumn('field_service_materials', 'requested_by_user_id')) {
            Schema::table('field_service_materials', function (Blueprint $table): void {
                $table->unsignedBigInteger('requested_by_user_id')->nullable()->after('field_service_job_id');
            });
        }

        if (! Schema::hasIndex('field_service_materials', 'fs_material_requester_idx')) {
            Schema::table('field_service_materials', function (Blueprint $table): void {
                $table->index('requested_by_user_id', 'fs_material_requester_idx');
            });
        }

        if ($this->requesterForeignKeyName() === null) {
            Schema::table('field_service_materials', function (Blueprint $table): void {
                $table->foreign('requested_by_user_id', 'fs_material_requester_fk')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('field_service_materials') || ! Schema::hasColumn('field_service_materials', 'requested_by_user_id')) {
            return;
        }

        $foreignKeyName = $this->requesterForeignKeyName();
        if ($foreignKeyName !== null) {
            Schema::table('field_service_materials', function (Blueprint $table) use ($foreignKeyName): void {
                $table->dropForeign($foreignKeyName);
            });
        }

        if (Schema::hasIndex('field_service_materials', 'fs_material_requester_idx')) {
            Schema::table('field_service_materials', function (Blueprint $table): void {
                $table->dropIndex('fs_material_requester_idx');
            });
        }

        Schema::table('field_service_materials', function (Blueprint $table): void {
            $table->dropColumn('requested_by_user_id');
        });
    }

    private function requesterForeignKeyName(): ?string
    {
        $foreign = collect(Schema::getForeignKeys('field_service_materials'))->first(
            fn (array $foreign): bool => in_array('requested_by_user_id', (array) ($foreign['columns'] ?? []), true)
        );

        return is_array($foreign) && is_string($foreign['name'] ?? null) ? $foreign['name'] : null;
    }
};
