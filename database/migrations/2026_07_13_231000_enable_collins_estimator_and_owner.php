<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'collins-electric')->value('id');
        if (! $tenantId) {
            return;
        }

        DB::table('tenant_module_entitlements')->updateOrInsert(
            ['tenant_id' => $tenantId, 'module_key' => 'estimator'],
            [
                'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included',
                'entitlement_source' => 'guided_launch', 'price_source' => 'catalog', 'updated_at' => now(), 'created_at' => now(),
            ]
        );

        $email = 'collinselectric91@gmail.com';
        $userId = DB::table('users')->where('email', $email)->value('id');
        if (! $userId) {
            $userId = DB::table('users')->insertGetId([
                'name' => 'Nathan Collins', 'email' => $email, 'password' => Hash::make(str()->random(64)),
                'role' => 'admin', 'is_active' => true, 'email_verified_at' => now(), 'approved_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('tenant_user')->updateOrInsert(
            ['tenant_id' => $tenantId, 'user_id' => $userId],
            ['role' => 'owner', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down(): void
    {
        // Memberships and entitlements may predate this migration; rollback must not remove them.
    }
};
