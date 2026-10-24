<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately exclude legacy role/admin and all-user/all-tenant backfills.
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->string('role', 80)->default('member');
                $table->boolean('is_active')->default(false);
                $table->rememberToken();
                $table->text('two_factor_secret')->nullable();
                $table->text('two_factor_recovery_codes')->nullable();
                $table->timestamp('two_factor_confirmed_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('users', 'ee_users_email', 'unique')) {
            Schema::table('users', fn (Blueprint $table) => $table->unique('email', 'ee_users_email'));
        }
        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug');
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('tenants', 'ee_tenants_slug', 'unique')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->unique('slug', 'ee_tenants_slug'));
        }
        if (! Schema::hasTable('tenant_user')) {
            Schema::create('tenant_user', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('role', 80)->nullable();
                $table->boolean('membership_active')->default(false);
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('tenant_user', 'ee_tu_membership', 'unique')) {
            Schema::table('tenant_user', fn (Blueprint $table) => $table->unique(['tenant_id', 'user_id'], 'ee_tu_membership'));
        }
        if (! Schema::hasIndex('tenant_user', 'ee_tu_user_tenant')) {
            Schema::table('tenant_user', fn (Blueprint $table) => $table->index(['user_id', 'tenant_id'], 'ee_tu_user_tenant'));
        }
        $this->foreign('tenant_user', 'tenant_id', 'tenants', 'ee_tu_tenant_fk');
        $this->foreign('tenant_user', 'user_id', 'users', 'ee_tu_user_fk');
    }

    private function foreign(string $table, string $column, string $parent, string $name): void
    {
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if (($foreign['name'] ?? null) === $name || $foreign['columns'] === [$column]) {
                if ($foreign['columns'] !== [$column] || $foreign['foreign_table'] !== $parent || $foreign['foreign_columns'] !== ['id']
                    || strtolower($foreign['on_delete']) !== 'cascade') {
                    throw new RuntimeException('Enterprise identity constraint mismatch: '.$name);
                }

                return;
            }
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column, $name)->references('id')->on($parent)->cascadeOnDelete());
    }

    public function down(): void
    {
        // Application rollback never removes identity or membership evidence.
    }
};
