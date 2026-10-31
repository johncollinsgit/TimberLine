<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = 'subscription_member_votes';
        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('member_id');
                $t->unsignedBigInteger('agreement_id');
                $t->unsignedBigInteger('subscription_poll_id');
                $t->unsignedBigInteger('subscription_poll_option_id');
                $t->unsignedBigInteger('marketing_profile_id')->nullable();
                $t->char('public_id', 36);
                $t->char('request_uuid', 36);
                $t->unsignedInteger('agreement_revision');
                $t->longText('evidence');
                $t->timestamps(6);
            });
        }
        foreach (['smv_poll_member_uq' => ['tenant_id', 'subscription_poll_id', 'member_id'],
            'smv_public_uq' => ['public_id'], 'smv_request_uq' => ['tenant_id', 'agreement_id', 'request_uuid']] as $name => $columns) {
            $existing = collect(Schema::getIndexes($table))->firstWhere('name', $name);
            if ($existing && ($existing['columns'] !== $columns || ! $existing['unique'])) {
                throw new RuntimeException('Member voting index differs from the reviewed schema: '.$name);
            }
            if (! $existing) {
                Schema::table($table, fn (Blueprint $t) => $t->unique($columns, $name));
            }
        }
        foreach ([['smv_agreement_fk', ['agreement_id', 'tenant_id'], 'subscription_billing_agreements', ['id', 'tenant_id']],
            ['smv_member_fk', ['member_id', 'tenant_id'], 'subscription_pilot_members', ['id', 'tenant_id']],
            ['smv_poll_fk', ['subscription_poll_id'], 'subscription_polls', ['id']],
            ['smv_option_fk', ['subscription_poll_option_id'], 'subscription_poll_options', ['id']]] as [$name, $columns, $parent, $references]) {
            $existing = collect(Schema::getForeignKeys($table))->firstWhere('name', $name);
            if ($existing && ($existing['columns'] !== $columns || $existing['foreign_table'] !== $parent
                || $existing['foreign_columns'] !== $references || strtolower($existing['on_delete']) !== 'restrict')) {
                throw new RuntimeException('Member voting ownership differs from the reviewed schema: '.$name);
            }
            if (! $existing) {
                Schema::table($table, fn (Blueprint $t) => $t->foreign($columns, $name)->references($references)->on($parent)->restrictOnDelete());
            }
        }
    }

    public function down(): void
    {
        // Retain accepted ballots and their original membership evidence during code rollback.
    }
};
