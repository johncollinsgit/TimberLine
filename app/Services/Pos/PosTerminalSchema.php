<?php

namespace App\Services\Pos;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class PosTerminalSchema
{
    public static function install(): void
    {
        if (! Schema::hasTable('pos_live_terminals')) {
            Schema::create('pos_live_terminals', function (Blueprint $t): void {
                $t->char('id', 32)->primary();
                $t->string('runtime_id', 64);
                $t->unsignedBigInteger('tenant_id');
                $t->char('register_id', 32);
                $t->char('connection_id', 32);
                $t->string('merchant_id', 100);
                $t->string('location_id', 100);
                $t->string('environment', 10);
                $t->char('request_id', 36);
                $t->longText('request_payload');
                $t->char('request_hash', 64);
                $t->string('code_id', 100)->nullable();
                $t->longText('pairing')->nullable();
                $t->string('device_id', 100)->nullable();
                $t->char('device_claim', 64)->nullable();
                $t->string('state', 20)->default('reserved');
                $t->timestamp('verified_at')->nullable();
                $t->timestamp('created_at');
                $t->unsignedBigInteger('actor_id');
                $t->timestamp('acceptance_at')->nullable();
                $t->string('acceptance_build', 100)->nullable();
            });
        }
        if (! Schema::hasTable('pos_live_terminal_checkouts')) {
            Schema::create('pos_live_terminal_checkouts', function (Blueprint $t): void {
                $t->char('id', 32)->primary(); // Sale ID, never a second provider intent.
                $t->string('runtime_id', 64);
                $t->unsignedBigInteger('tenant_id');
                $t->char('register_id', 32);
                $t->char('connection_id', 32);
                $t->char('terminal_id', 32);
                $t->string('device_id', 100);
                $t->char('request_id', 36);
                $t->longText('payload');
                $t->char('payload_hash', 64);
                $t->string('checkout_id', 100)->nullable();
                $t->string('payment_id', 100)->nullable();
                $t->char('active_device_claim', 64)->nullable();
                $t->string('state', 20)->default('reserved');
                $t->timestamp('created_at');
                $t->timestamp('verified_at')->nullable();
            });
        }
        foreach ([
            ['terminals', 'poslt_request_uq', ['runtime_id', 'tenant_id', 'request_id'], true],
            ['terminals', 'poslt_device_uq', ['runtime_id', 'device_claim'], true],
            ['terminals', 'poslt_register_idx', ['runtime_id', 'tenant_id', 'register_id', 'state'], false],
            ['terminal_checkouts', 'posltc_request_uq', ['runtime_id', 'tenant_id', 'request_id'], true],
            ['terminal_checkouts', 'posltc_active_uq', ['runtime_id', 'active_device_claim'], true],
            ['terminal_checkouts', 'posltc_provider_idx', ['runtime_id', 'checkout_id'], false],
        ] as [$suffix, $name, $columns, $unique]) {
            $table = 'pos_live_'.$suffix;
            $existing = collect(Schema::getIndexes($table))->firstWhere('name', $name);
            if ($existing && ($existing['columns'] !== $columns || (bool) $existing['unique'] !== $unique)) {
                throw new \RuntimeException('POS Terminal index definition changed: '.$name);
            }
            if (! $existing) {
                Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($columns, $name) : $t->index($columns, $name));
            }
        }
    }
}
