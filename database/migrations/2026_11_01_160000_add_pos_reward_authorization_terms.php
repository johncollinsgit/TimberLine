<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_reward_authorizations')) {
            throw new RuntimeException('POS reward authorization foundation is missing.');
        }
        if (! Schema::hasColumn('pos_reward_authorizations', 'cap_minor')) {
            Schema::table('pos_reward_authorizations', fn (Blueprint $table) => $table->unsignedInteger('cap_minor')->nullable());
        }
        if (! Schema::hasColumn('pos_reward_authorizations', 'policy_version')) {
            Schema::table('pos_reward_authorizations', fn (Blueprint $table) => $table->char('policy_version', 64)->nullable());
        }
        if (! Schema::hasColumn('pos_reward_authorizations', 'disclosure_version')) {
            Schema::table('pos_reward_authorizations', fn (Blueprint $table) => $table->string('disclosure_version', 40)->nullable());
        }
        if (! Schema::hasColumn('pos_reward_authorizations', 'account_link_id')) {
            Schema::table('pos_reward_authorizations', fn (Blueprint $table) => $table->unsignedBigInteger('account_link_id')->nullable());
        }
    }

    public function down(): void
    {
        // Retain the frozen authorization terms and existing evidence.
    }
};
