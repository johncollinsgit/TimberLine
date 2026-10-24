<?php

declare(strict_types=1);

namespace App\Services\Pos;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive provider identification; original Square snapshots remain authoritative. */
final class PosStripeSchema
{
    public static function install(): void
    {
        foreach (['connections', 'registers'] as $name) {
            if (! Schema::hasColumn('pos_live_'.$name, 'provider')) {
                Schema::table('pos_live_'.$name, fn (Blueprint $t) => $t->string('provider', 24)->default('square_native'));
            }
        }
        if (! Schema::hasTable('pos_live_readers')) {
            Schema::create('pos_live_readers', function (Blueprint $t): void {
                $t->char('id', 32)->primary();
                $t->string('runtime_id', 64);
                $t->unsignedBigInteger('tenant_id');
                $t->char('register_id', 32);
                $t->char('connection_id', 32);
                $t->string('reader_id', 100);
                $t->string('location_id', 100);
                $t->char('identity_hash', 64);
                $t->string('state', 20);
                $t->timestamp('verified_at')->nullable();
                $t->timestamp('acceptance_at')->nullable();
                $t->string('acceptance_build', 100)->nullable();
            });
        }
        if (! Schema::hasTable('pos_live_stripe_operations')) {
            Schema::create('pos_live_stripe_operations', function (Blueprint $t): void {
                $t->char('id', 32)->primary(); // original sale ID
                $t->string('runtime_id', 64);
                $t->unsignedBigInteger('tenant_id');
                $t->char('reader_binding_id', 32);
                $t->char('connection_id', 32);
                $t->string('reader_id', 100);
                $t->char('request_id', 36);
                $t->longText('payload');
                $t->char('payload_hash', 64);
                $t->string('payment_id', 100)->nullable();
                $t->char('active_reader_claim', 64)->nullable();
                $t->string('reader_state', 24)->default('reserved');
                $t->unsignedInteger('dispatch_generation')->default(0);
                $t->timestamp('created_at');
                $t->timestamp('verified_at')->nullable();
                $t->longText('receipt')->nullable();
            });
        }
        foreach ([
            ['readers', 'posreader_register_uq', ['runtime_id', 'tenant_id', 'register_id'], true],
            ['readers', 'posreader_identity_uq', ['runtime_id', 'identity_hash'], true],
            ['stripe_operations', 'posstripe_request_uq', ['runtime_id', 'tenant_id', 'request_id'], true],
            ['stripe_operations', 'posstripe_active_uq', ['runtime_id', 'active_reader_claim'], true],
            ['stripe_operations', 'posstripe_payment_idx', ['runtime_id', 'payment_id'], false],
        ] as [$suffix, $name, $columns, $unique]) {
            $table = 'pos_live_'.$suffix;
            $existing = collect(Schema::getIndexes($table))->firstWhere('name', $name);
            if ($existing && ($existing['columns'] !== $columns || (bool) $existing['unique'] !== $unique)) {
                throw new \RuntimeException('Unexpected POS Stripe index: '.$name);
            }
            if (! $existing) {
                Schema::table($table, function (Blueprint $t) use ($name, $columns, $unique): void {
                    $unique ? $t->unique($columns, $name) : $t->index($columns, $name);
                });
            }
        }
    }
}
