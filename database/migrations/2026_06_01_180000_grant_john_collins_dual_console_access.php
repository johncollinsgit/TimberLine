<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (! Schema::hasTable('users') || ! Schema::hasTable('tenants') || ! Schema::hasTable('tenant_user')) {
            return;
        }

        $tenantId = $this->resolveModernForestryTenantId();
        if ($tenantId === null) {
            return;
        }

        $email = 'johncollinsemail@gmail.com';
        $now = now();

        $userId = DB::table('users')->where('email', $email)->value('id');

        if (! is_numeric($userId)) {
            $payload = [
                'name' => 'John Collins',
                'email' => $email,
                'email_verified_at' => $now,
                'password' => Hash::make(Str::random(40)),
                'remember_token' => Str::random(10),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('users', 'role')) {
                $payload['role'] = 'platform_admin';
            }

            if (Schema::hasColumn('users', 'is_active')) {
                $payload['is_active'] = true;
            }

            if (Schema::hasColumn('users', 'requested_via')) {
                $payload['requested_via'] = 'landlord_console';
            }

            DB::table('users')->insert($payload);
            $userId = DB::table('users')->where('email', $email)->value('id');
        }

        if (! is_numeric($userId)) {
            return;
        }

        $updates = [
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('users', 'role')) {
            $updates['role'] = 'platform_admin';
        }

        if (Schema::hasColumn('users', 'is_active')) {
            $updates['is_active'] = true;
        }

        if (Schema::hasColumn('users', 'email_verified_at')) {
            $updates['email_verified_at'] = DB::raw('COALESCE(email_verified_at, CURRENT_TIMESTAMP)');
        }

        DB::table('users')
            ->where('id', (int) $userId)
            ->update($updates);

        DB::table('tenant_user')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'user_id' => (int) $userId,
            ],
            [
                'role' => 'admin',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        // Intentionally irreversible: we cannot safely infer whether the user or
        // membership predated this migration in production.
    }

    protected function resolveModernForestryTenantId(): ?int
    {
        $tenant = DB::table('tenants')
            ->where('id', 1)
            ->first(['id']);

        if ($tenant && is_numeric($tenant->id)) {
            return (int) $tenant->id;
        }

        $tenant = DB::table('tenants')
            ->where('slug', 'modern-forestry')
            ->first(['id']);

        return $tenant && is_numeric($tenant->id) ? (int) $tenant->id : null;
    }
};
