<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enterprise_client_silos')) {
            Schema::create('enterprise_client_silos', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->uuid('client_id');
                $table->uuid('silo_id');
                $table->string('environment', 16);
                $table->unsignedBigInteger('tenant_id');
                $table->char('manifest_hash', 64);
                $table->char('plan_hash', 64);
                $table->char('binding_hash', 64);
                $table->longText('manifest');
                $table->timestamp('applied_at');
            });
        }
        $this->unique('silo_id', 'ecs_silo');
        $this->unique('tenant_id', 'ecs_tenant');
    }

    private function unique(string $column, string $name): void
    {
        foreach (Schema::getIndexes('enterprise_client_silos') as $index) {
            if ($index['name'] === $name) {
                if ($index['columns'] !== [$column] || ! $index['unique']) {
                    throw new RuntimeException('Enterprise client index mismatch: '.$name);
                }

                return;
            }
        }
        Schema::table('enterprise_client_silos', fn (Blueprint $table) => $table->unique($column, $name));
    }

    public function down(): void
    {
        // A code rollback must not erase client identity or provisioning evidence.
    }
};
