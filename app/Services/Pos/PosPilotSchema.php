<?php

declare(strict_types=1);

namespace App\Services\Pos;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive operational records; the existing events table remains the payment audit log. */
final class PosPilotSchema
{
    public static function install(): void
    {
        $definitions = [
            'operators' => function (Blueprint $t): void {
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('name', 100);
                $t->string('role', 20);
                $t->string('pin_hash')->nullable();
                $t->boolean('active')->default(true);
            },
            'devices' => function (Blueprint $t): void {
                $t->string('device_key', 64);
                $t->char('register_id', 32);
                $t->string('name', 100);
                $t->unsignedBigInteger('enrolled_by');
                $t->boolean('active')->default(true);
            },
            'register_sessions' => function (Blueprint $t): void {
                $t->char('device_id', 32);
                $t->char('token_hash', 64);
                $t->char('operator_id', 32)->nullable();
                $t->char('shift_id', 32)->nullable();
                $t->timestamp('last_used_at')->nullable();
                $t->timestamp('expires_at');
                $t->boolean('active')->default(true);
            },
            'trade_events' => function (Blueprint $t): void {
                $t->string('name', 100);
                $t->string('location_id', 64);
                $t->string('timezone', 80);
                $t->timestamp('starts_at');
                $t->timestamp('ends_at');
                $t->string('state', 20)->default('planned');
                $t->unsignedInteger('version')->default(1);
                $t->longText('configuration');
            },
            'event_staff' => function (Blueprint $t): void {
                $t->char('event_id', 32);
                $t->char('operator_id', 32);
                $t->boolean('active')->default(true);
            },
            'event_registers' => function (Blueprint $t): void {
                $t->char('event_id', 32);
                $t->char('register_id', 32);
                $t->boolean('active')->default(true);
            },
            'shifts' => function (Blueprint $t): void {
                $t->char('event_id', 32);
                $t->char('operator_id', 32);
                $t->char('device_id', 32);
                $t->char('register_id', 32);
                $t->string('state', 20)->default('open');
                $t->timestamp('closed_at')->nullable();
            },
            'baskets' => function (Blueprint $t): void {
                $t->char('event_id', 32);
                $t->char('operator_id', 32);
                $t->char('register_id', 32);
                $t->longText('payload');
                $t->char('payload_hash', 64);
                $t->string('state', 20)->default('saved');
                $t->char('sale_request_id', 36);
                $t->char('claimed_device_id', 32)->nullable();
                $t->timestamp('updated_at');
            },
            'favorites' => function (Blueprint $t): void {
                $t->char('operator_id', 32);
                $t->char('register_id', 32);
                $t->string('item_id', 100);
            },
            'stock_counts' => function (Blueprint $t): void {
                $t->char('event_id', 32);
                $t->char('operator_id', 32);
                $t->char('register_id', 32);
                $t->string('stage', 10);
                $t->unsignedInteger('sequence')->default(0);
                $t->char('context_hash', 64)->nullable();
                $t->longText('counts');
            },
            'refund_details' => function (Blueprint $t): void {
                $t->char('sale_id', 32);
                $t->char('request_id', 36);
                $t->char('operator_id', 32)->nullable();
                $t->unsignedBigInteger('amount_minor');
                $t->string('state', 20)->default('reserved');
                $t->longText('decision');
                $t->char('operation_id', 32)->nullable();
                $t->timestamp('confirmed_at')->nullable();
                $t->timestamp('restocked_at')->nullable();
            },
            'company_settings' => function (Blueprint $t): void {
                $t->string('reporting_timezone', 80);
            },
        ];
        foreach ($definitions as $suffix => $definition) {
            if (! Schema::hasTable('pos_live_'.$suffix)) {
                Schema::create('pos_live_'.$suffix, function (Blueprint $t) use ($definition): void {
                    $t->char('id', 32)->primary();
                    $t->string('runtime_id', 64);
                    $t->unsignedBigInteger('tenant_id');
                    $t->timestamp('created_at');
                    $definition($t);
                });
            }
        }
        if (! Schema::hasColumn('pos_live_refund_details', 'state')) {
            Schema::table('pos_live_refund_details', fn (Blueprint $t) => $t->string('state', 20)->default('reserved'));
        }
        if (! Schema::hasColumn('pos_live_stock_counts', 'sequence')) {
            Schema::table('pos_live_stock_counts', fn (Blueprint $t) => $t->unsignedInteger('sequence')->default(0));
        }
        if (! Schema::hasColumn('pos_live_stock_counts', 'context_hash')) {
            Schema::table('pos_live_stock_counts', fn (Blueprint $t) => $t->char('context_hash', 64)->nullable());
        }
        if (! Schema::hasColumn('pos_live_baskets', 'claimed_device_id')) {
            Schema::table('pos_live_baskets', fn (Blueprint $t) => $t->char('claimed_device_id', 32)->nullable());
        }
        foreach (['operator_id', 'event_id', 'shift_id', 'device_record_id'] as $column) {
            if (! Schema::hasColumn('pos_live_sales', $column)) {
                Schema::table('pos_live_sales', fn (Blueprint $t) => $t->char($column, 32)->nullable());
            }
        }
        if (! Schema::hasColumn('pos_live_sales', 'confirmed_at')) {
            Schema::table('pos_live_sales', fn (Blueprint $t) => $t->timestamp('confirmed_at')->nullable());
        }
        $indexes = [
            ['operators', 'posop_user_uq', ['runtime_id', 'tenant_id', 'user_id']],
            ['devices', 'posdev_key_uq', ['runtime_id', 'tenant_id', 'device_key']],
            ['register_sessions', 'possess_token_uq', ['runtime_id', 'token_hash']],
            ['event_staff', 'posev_staff_uq', ['runtime_id', 'tenant_id', 'event_id', 'operator_id']],
            ['event_registers', 'posev_register_uq', ['runtime_id', 'tenant_id', 'event_id', 'register_id']],
            ['favorites', 'posfav_item_uq', ['runtime_id', 'tenant_id', 'operator_id', 'register_id', 'item_id']],
            ['refund_details', 'posrefund_request_uq', ['runtime_id', 'tenant_id', 'request_id']],
            ['company_settings', 'poscompany_scope_uq', ['runtime_id', 'tenant_id']],
        ];
        foreach ($indexes as [$suffix, $name, $columns]) {
            $table = 'pos_live_'.$suffix;
            $found = collect(Schema::getIndexes($table))->firstWhere('name', $name);
            if ($found && ($found['columns'] !== $columns || ! $found['unique'])) {
                throw new \RuntimeException('Unexpected POS pilot index: '.$name);
            }
            if (! $found) {
                Schema::table($table, fn (Blueprint $t) => $t->unique($columns, $name));
            }
        }
    }
}
