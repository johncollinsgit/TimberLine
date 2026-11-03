<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_shop_pay_connections')) {
            Schema::create('website_shop_pay_connections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->boolean('enabled')->default(false);
                $table->string('environment', 8)->default('test');
                $table->unsignedBigInteger('shop_id');
                $table->string('client_id', 190);
                $table->string('shop_domain', 190)->unique('wsp_connections_shop_domain_uq');
                $table->text('storefront_access_token');
                $table->text('admin_access_token');
                $table->text('webhook_secret')->nullable();
                $table->string('api_version', 10);
                $table->string('connection_status', 24)->default('unverified');
                $table->string('verified_origin', 255)->nullable();
                $table->timestamp('last_verified_at')->nullable();
                $table->string('last_error', 255)->nullable();
                $table->text('setup')->nullable();
                $table->timestamps();
            });
        }
        // A committed table can survive interrupted index DDL. Never remove rows.
        $expected = [
            ['wsp_connections_tenant_uq', ['tenant_id'], true],
            ['wsp_connections_shop_domain_uq', ['shop_domain'], true],
        ];
        foreach ($expected as [$name, $columns, $unique]) {
            $index = collect(Schema::getIndexes('website_shop_pay_connections'))->firstWhere('name', $name);
            if ($index) {
                if ($index['columns'] !== $columns || $index['unique'] !== $unique) {
                    throw new RuntimeException('Unexpected Wallet index definition: '.$name);
                }
            } else {
                Schema::table('website_shop_pay_connections', function (Blueprint $table) use ($name, $columns, $unique): void {
                    $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        // Preserve merchant credentials and original payment evidence during rollback.
    }
};
