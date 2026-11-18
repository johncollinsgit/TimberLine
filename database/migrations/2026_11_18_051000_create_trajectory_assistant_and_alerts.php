<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trajectory_assistant_turns')) {
            Schema::create('trajectory_assistant_turns', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('space_id');
                $t->unsignedBigInteger('user_id');
                $t->uuid('request_id');
                $t->string('request_hash', 64);
                $t->text('question');
                $t->longText('context');
                $t->longText('response')->nullable();
                $t->string('state', 24)->default('processing');
                $t->timestamps();
                $t->unique(['user_id', 'request_id'], 'traj_assistant_request');
                $t->index(['space_id', 'user_id', 'id'], 'traj_assistant_history');
            });
        }
        if (! Schema::hasTable('trajectory_alert_preferences')) {
            Schema::create('trajectory_alert_preferences', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('space_id');
                $t->unsignedBigInteger('user_id');
                $t->boolean('enabled')->default(false);
                $t->boolean('include_pending')->default(true);
                $t->boolean('show_details')->default(true);
                $t->timestamp('enabled_at')->nullable();
                $t->timestamps();
                $t->unique(['space_id', 'user_id'], 'traj_alert_pref_owner');
            });
        }
        if (! Schema::hasTable('trajectory_push_subscriptions')) {
            Schema::create('trajectory_push_subscriptions', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('space_id');
                $t->unsignedBigInteger('user_id');
                $t->string('endpoint_hash', 64);
                $t->text('endpoint');
                $t->text('keys');
                $t->string('content_encoding', 24)->default('aes128gcm');
                $t->timestamp('disabled_at')->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'endpoint_hash'], 'traj_push_endpoint');
                $t->index(['space_id', 'user_id'], 'traj_push_owner');
            });
        }
        if (! Schema::hasTable('trajectory_expense_notices')) {
            Schema::create('trajectory_expense_notices', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('space_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('transaction_id');
                $t->longText('payload');
                $t->string('delivery_status', 24)->default('pending');
                $t->timestamp('read_at')->nullable();
                $t->timestamp('delivered_at')->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'transaction_id'], 'traj_expense_notice_once');
                $t->index(['space_id', 'user_id', 'id'], 'traj_expense_inbox');
            });
        }
    }

    public function down(): void
    {
        // Retain private conversations, notification receipts and subscriptions on code rollback.
    }
};
