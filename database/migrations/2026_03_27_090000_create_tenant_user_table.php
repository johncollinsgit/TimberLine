<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_user')) {
            Schema::create('tenant_user', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('role', 80)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'user_id'], 'tenant_user_unique');
                $table->index(['user_id', 'tenant_id'], 'tenant_user_user_tenant_idx');

                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('tenants') || ! Schema::hasTable('users')) {
            return;
        }

        $tenantIds = DB::table('tenants')->pluck('id');
        $userRows = DB::table('users')->select('id', 'role')->get();

        if ($tenantIds->isEmpty() || $userRows->isEmpty()) {
            return;
        }

        $now = now();
        $records = [];

        foreach ($userRows as $user) {
            foreach ($tenantIds as $tenantId) {
                $records[] = [
                    'tenant_id' => (int) $tenantId,
                    'user_id' => (int) $user->id,
                    'role' => is_string($user->role) && trim($user->role) !== '' ? trim($user->role) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('tenant_user')->upsert(
                $chunk,
                ['tenant_id', 'user_id'],
                ['role', 'updated_at']
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user');
    }
};
