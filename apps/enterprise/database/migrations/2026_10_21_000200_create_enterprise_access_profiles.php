<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Same canonical record shapes, without the retail profile bootstrap/upsert.
        if (! Schema::hasTable('tenant_access_profiles')) {
            Schema::create('tenant_access_profiles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('plan_key', 120)->default('base');
                $table->string('operating_mode', 80)->default('direct');
                $table->string('source', 80)->default('manual');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('tenant_access_profiles', 'ee_profile_tenant', 'unique')) {
            Schema::table('tenant_access_profiles', fn (Blueprint $table) => $table->unique('tenant_id', 'ee_profile_tenant'));
        }
        if (! Schema::hasTable('tenant_access_addons')) {
            Schema::create('tenant_access_addons', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('addon_key', 120);
                $table->boolean('enabled')->default(false);
                $table->string('source', 80)->default('manual');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('tenant_access_addons', 'ee_addon_tenant_key', 'unique')) {
            Schema::table('tenant_access_addons', fn (Blueprint $table) => $table->unique(['tenant_id', 'addon_key'], 'ee_addon_tenant_key'));
        }
        if (! Schema::hasTable('tenant_module_states')) {
            Schema::create('tenant_module_states', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('module_key', 120);
                $table->boolean('enabled_override')->nullable();
                $table->string('setup_status', 80)->default('not_started');
                $table->timestamp('setup_completed_at')->nullable();
                $table->boolean('coming_soon_override')->nullable();
                $table->boolean('upgrade_prompt_override')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('tenant_module_states', 'ee_state_tenant_key', 'unique')) {
            Schema::table('tenant_module_states', fn (Blueprint $table) => $table->unique(['tenant_id', 'module_key'], 'ee_state_tenant_key'));
        }
        foreach (['tenant_access_profiles' => 'ee_profile_tenant_fk', 'tenant_access_addons' => 'ee_addon_tenant_fk', 'tenant_module_states' => 'ee_state_tenant_fk'] as $table => $name) {
            $exists = false;
            foreach (Schema::getForeignKeys($table) as $foreign) {
                if (($foreign['name'] ?? null) === $name || $foreign['columns'] === ['tenant_id']) {
                    if ($foreign['columns'] !== ['tenant_id'] || $foreign['foreign_table'] !== 'tenants' || $foreign['foreign_columns'] !== ['id']
                        || strtolower($foreign['on_delete']) !== 'cascade') {
                        throw new RuntimeException('Enterprise access constraint mismatch: '.$name);
                    }
                    $exists = true;
                }
            }
            if (! $exists) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign('tenant_id', $name)->references('id')->on('tenants')->cascadeOnDelete());
            }
        }
    }

    public function down(): void
    {
        // Retain grants/history when application code is rolled back.
    }
};
