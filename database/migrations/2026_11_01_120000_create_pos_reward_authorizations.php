<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_reward_authorizations')) {
            Schema::create('pos_reward_authorizations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('runtime_id', 64);
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('marketing_profile_id');
                $table->char('token_hash', 64);
                $table->char('sale_id', 32)->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('bound_at')->nullable();
                $table->timestamps();
            });
        }
        foreach ([
            ['pos_reward_auth_token_uq', ['token_hash'], true],
            ['pos_reward_auth_customer_idx', ['runtime_id', 'tenant_id', 'marketing_profile_id'], false],
        ] as [$name, $columns, $unique]) {
            $existing = collect(Schema::getIndexes('pos_reward_authorizations'))->firstWhere('name', $name);
            if ($existing && ($existing['columns'] !== $columns || (bool) $existing['unique'] !== $unique)) {
                throw new RuntimeException('POS reward authorization index definition changed: '.$name);
            }
            if (! $existing) {
                Schema::table('pos_reward_authorizations', fn (Blueprint $table) => $unique
                    ? $table->unique($columns, $name) : $table->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        // Retain authorization evidence; expired credentials cannot be reused.
    }
};
