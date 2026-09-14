<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trajectory_spaces')) {
            Schema::create('trajectory_spaces', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index('traj_space_tenant');
                $table->unsignedBigInteger('owner_user_id');
                $table->string('kind', 20);
                $table->string('name', 160);
                $table->boolean('enabled')->default(false);
                $table->string('currency', 3)->default('USD');
                $table->string('timezone', 64)->default('America/New_York');
                $table->text('settings')->nullable();
                $table->unique(['tenant_id', 'kind'], 'traj_space_tenant_kind');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('trajectory_members')) {
            Schema::create('trajectory_members', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('space_id');
                $table->unsignedBigInteger('user_id');
                $table->unique(['space_id', 'user_id'], 'traj_member_unique');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('trajectory_links')) {
            Schema::create('trajectory_links', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('household_id');
                $table->unsignedBigInteger('business_id');
                $table->unsignedBigInteger('created_by');
                $table->unique(['household_id', 'business_id'], 'traj_link_unique');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('trajectory_connections')) {
            Schema::create('trajectory_connections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('space_id')->index('traj_connection_space');
                $table->string('provider', 30)->default('plaid');
                $table->string('external_id', 128)->unique('traj_connection_external');
                $table->text('access_token');
                $table->text('cursor')->nullable();
                $table->string('status', 40)->default('connected');
                $table->string('institution_name', 160)->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->text('coverage')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('trajectory_accounts')) {
            Schema::create('trajectory_accounts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('space_id')->index('traj_account_space');
                $table->unsignedBigInteger('connection_id')->nullable();
                $table->string('source_key', 160)->unique('traj_account_source');
                $table->string('name', 160);
                $table->string('kind', 30);
                $table->bigInteger('balance_cents')->nullable();
                $table->string('currency', 3)->default('USD');
                $table->date('history_start')->nullable();
                $table->timestamp('observed_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('trajectory_transactions')) {
            Schema::create('trajectory_transactions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('space_id');
                $table->unsignedBigInteger('account_id');
                $table->string('source_key', 190)->unique('traj_tx_source');
                $table->string('pending_source_key', 190)->nullable();
                $table->date('posted_on');
                $table->bigInteger('amount_cents');
                $table->text('merchant');
                $table->string('category', 40)->default('uncategorized');
                $table->string('flow', 30)->default('expense');
                $table->boolean('pending')->default(false);
                $table->boolean('removed')->default(false);
                $table->boolean('reviewed')->default(false);
                $table->boolean('face_punched')->default(false);
                $table->boolean('bullshit_spending')->default(false);
                $table->text('explanation')->nullable();
                $table->text('source')->nullable();
                $table->unsignedBigInteger('version')->default(1);
                $table->index(['space_id', 'posted_on'], 'traj_tx_space_date');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('trajectory_allocations')) {
            Schema::create('trajectory_allocations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('transaction_id');
                $table->unsignedBigInteger('space_id')->index('traj_allocation_space');
                $table->bigInteger('amount_cents');
                $table->unique(['transaction_id', 'space_id'], 'traj_allocation_unique');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('trajectory_records')) {
            Schema::create('trajectory_records', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('space_id');
                $table->string('kind', 30);
                $table->string('name', 160);
                $table->text('data');
                $table->unsignedBigInteger('version')->default(1);
                $table->boolean('active')->default(true);
                $table->index(['space_id', 'kind', 'active'], 'traj_record_lookup');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('trajectory_events')) {
            Schema::create('trajectory_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('space_id')->index('traj_event_space');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('action', 80);
                $table->unsignedBigInteger('record_id')->nullable();
                $table->text('before')->nullable();
                $table->text('after')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('trajectory_invites')) {
            Schema::create('trajectory_invites', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('space_id');
                $table->text('email');
                $table->string('token_hash', 64)->unique('traj_invite_token');
                $table->timestamp('expires_at');
                $table->timestamp('accepted_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('trajectory_notifications')) {
            Schema::create('trajectory_notifications', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('space_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->string('dedupe_key', 190)->unique('traj_notification_dedupe');
                $table->text('phone');
                $table->text('body');
                $table->string('reply_hash', 64)->nullable()->unique('traj_reply_hash');
                $table->string('status', 40)->default('pending');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('consumed_at')->nullable();
                $table->text('provider_id')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('trajectory_snapshots')) {
            Schema::create('trajectory_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('space_id');
                $table->date('observed_on');
                $table->text('data');
                $table->unique(['space_id', 'observed_on'], 'traj_snapshot_unique');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trajectory_snapshots');
        Schema::dropIfExists('trajectory_notifications');
        Schema::dropIfExists('trajectory_invites');
        Schema::dropIfExists('trajectory_events');
        Schema::dropIfExists('trajectory_records');
        Schema::dropIfExists('trajectory_allocations');
        Schema::dropIfExists('trajectory_transactions');
        Schema::dropIfExists('trajectory_accounts');
        Schema::dropIfExists('trajectory_connections');
        Schema::dropIfExists('trajectory_links');
        Schema::dropIfExists('trajectory_members');
        Schema::dropIfExists('trajectory_spaces');
    }
};
