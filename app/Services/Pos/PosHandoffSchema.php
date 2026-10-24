<?php

namespace App\Services\Pos;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class PosHandoffSchema
{
    public static function install(): void
    {
        if (! Schema::hasTable('pos_live_handoffs')) {
            Schema::create('pos_live_handoffs', function (Blueprint $t): void {
                $t->char('id', 32)->primary();
                $t->string('runtime_id', 64);
                $t->unsignedBigInteger('tenant_id');
                $t->char('register_id', 32);
                $t->unsignedBigInteger('actor_id');
                $t->string('device_id', 64);
                $t->char('request_id', 36);
                $t->longText('payload');
                $t->char('payload_hash', 64);
                $t->string('state', 20)->default('ready');
                $t->unsignedBigInteger('claimed_by')->nullable();
                $t->string('claimed_device', 64)->nullable();
                $t->char('sale_id', 32)->nullable();
                $t->timestamp('created_at');
                $t->timestamp('expires_at');
            });
        }
        foreach ([
            ['poslhf_request_uq', ['runtime_id', 'tenant_id', 'request_id'], true],
            ['poslhf_queue_idx', ['runtime_id', 'tenant_id', 'register_id', 'state', 'expires_at'], false],
        ] as [$name, $columns, $unique]) {
            $existing = collect(Schema::getIndexes('pos_live_handoffs'))->firstWhere('name', $name);
            if ($existing && ($existing['columns'] !== $columns || (bool) $existing['unique'] !== $unique)) {
                throw new \RuntimeException('POS handoff index definition changed: '.$name);
            }
            if (! $existing) {
                Schema::table('pos_live_handoffs', fn (Blueprint $t) => $unique ? $t->unique($columns, $name) : $t->index($columns, $name));
            }
        }
    }
}
