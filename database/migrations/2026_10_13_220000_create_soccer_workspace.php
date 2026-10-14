<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('soccer_settings')) {
            Schema::create('soccer_settings', function (Blueprint $t) {
                $t->unsignedBigInteger('tenant_id')->primary();
                $t->longText('data')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('soccer_seasons')) {
            Schema::create('soccer_seasons', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('season_key', 40);
                $t->string('name', 100);
                $t->date('starts_on')->nullable();
                $t->date('age_as_of')->nullable();
                $t->timestamp('registration_opens_at')->nullable();
                $t->boolean('registration_open')->default(false);
                $t->unsignedInteger('offer_hours')->default(48);
                $t->unsignedBigInteger('next_queue_sequence')->default(0);
                $t->json('teams');
                $t->timestamps();
                $t->unique(['tenant_id', 'season_key'], 'soc_season_key');
            });
        }
        if (! Schema::hasTable('soccer_families')) {
            Schema::create('soccer_families', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('email', 254)->nullable();
                $t->longText('profile');
                $t->timestamp('verified_at')->nullable();
                $t->timestamp('sms_consent_at')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'email'], 'soc_family_email');
            });
        }
        if (! Schema::hasTable('soccer_players')) {
            Schema::create('soccer_players', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('family_id');
                $t->string('name', 160);
                $t->date('birthday')->nullable();
                $t->longText('profile');
                $t->timestamps();
                $t->index(['tenant_id', 'family_id'], 'soc_player_family');
            });
        }
        if (! Schema::hasTable('soccer_coaches')) {
            Schema::create('soccer_coaches', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('family_id')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('name', 160);
                $t->string('role', 20)->default('assistant');
                $t->string('jersey_size', 30)->nullable();
                $t->json('team_keys');
                $t->timestamps();
                $t->unique(['tenant_id', 'user_id'], 'soc_coach_user');
            });
        }
        if (! Schema::hasTable('soccer_releases')) {
            Schema::create('soccer_releases', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('family_id')->nullable();
                $t->uuid('player_id')->nullable();
                $t->string('season_key', 40);
                $t->string('source_key', 190);
                $t->string('form_version', 80);
                $t->longText('answers');
                $t->string('sha256', 64);
                $t->timestamp('signed_at')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'source_key'], 'soc_release_source');
            });
        }
        if (! Schema::hasTable('soccer_registrations')) {
            Schema::create('soccer_registrations', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('season_id');
                $t->uuid('family_id');
                $t->uuid('player_id');
                $t->uuid('release_id')->nullable();
                $t->string('team_key', 40);
                $t->string('status', 24);
                $t->string('seat_kind', 12)->nullable();
                $t->uuid('offer_group')->nullable();
                $t->boolean('auto_eligible')->default(true);
                $t->boolean('needs_jersey');
                $t->string('jersey_size', 30)->nullable();
                $t->boolean('waived')->default(false);
                $t->unsignedInteger('amount_cents');
                $t->string('payment_status', 24)->default('unpaid');
                $t->timestamp('queued_at');
                $t->unsignedBigInteger('queue_sequence')->default(0);
                $t->timestamp('offer_expires_at')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'season_id', 'player_id'], 'soc_reg_player_season');
                $t->index(['tenant_id', 'season_id', 'team_key', 'status'], 'soc_reg_queue');
            });
        }
        if (! Schema::hasTable('soccer_invoices')) {
            Schema::create('soccer_invoices', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('season_id');
                $t->uuid('family_id');
                $t->uuid('offer_group')->unique('soc_invoice_offer');
                $t->json('registration_ids');
                $t->unsignedInteger('amount_cents');
                $t->unsignedInteger('refunded_cents')->default(0);
                $t->string('status', 24)->default('unpaid');
                $t->string('provider', 20)->nullable();
                $t->string('checkout_id', 190)->nullable();
                $t->string('payment_intent_id', 190)->nullable();
                $t->timestamp('expires_at');
                $t->timestamp('paid_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('soccer_payments')) {
            Schema::create('soccer_payments', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('invoice_id');
                $t->string('provider', 20);
                $t->string('provider_key', 190);
                $t->unsignedInteger('amount_cents');
                $t->longText('evidence');
                $t->timestamps();
                $t->unique(['tenant_id', 'provider', 'provider_key'], 'soc_payment_dedupe');
            });
        }
        if (! Schema::hasTable('soccer_refunds')) {
            Schema::create('soccer_refunds', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('invoice_id');
                $t->unsignedInteger('amount_cents');
                $t->string('status', 24);
                $t->string('provider_key', 190)->nullable();
                $t->longText('evidence');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('soccer_import_rows')) {
            Schema::create('soccer_import_rows', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('source', 80);
                $t->string('season_key', 40);
                $t->unsignedInteger('row_number');
                $t->string('fingerprint', 64);
                $t->string('status', 24);
                $t->uuid('family_id')->nullable();
                $t->uuid('player_id')->nullable();
                $t->longText('evidence');
                $t->timestamps();
                $t->unique(['tenant_id', 'fingerprint'], 'soc_import_dedupe');
            });
        }
        if (! Schema::hasTable('soccer_challenges')) {
            Schema::create('soccer_challenges', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('email', 254);
                $t->string('code_hash', 64);
                $t->unsignedInteger('attempts')->default(0);
                $t->timestamp('expires_at');
                $t->timestamp('consumed_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('soccer_sessions')) {
            Schema::create('soccer_sessions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('family_id');
                $t->string('token_hash', 64)->unique('soc_session_token');
                $t->timestamp('expires_at');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('soccer_events')) {
            Schema::create('soccer_events', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('season_id')->nullable();
                $t->uuid('family_id')->nullable();
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->string('action', 80);
                $t->string('event_key', 190);
                $t->longText('data');
                $t->timestamps();
                $t->unique(['tenant_id', 'event_key'], 'soc_event_key');
            });
        }
        if (! Schema::hasTable('soccer_deliveries')) {
            Schema::create('soccer_deliveries', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('event_id');
                $t->string('recipient_key', 64);
                $t->longText('payload');
                $t->string('status', 24)->default('pending');
                $t->string('provider_message_id', 190)->nullable();
                $t->unsignedInteger('attempts')->default(0);
                $t->timestamp('retry_at')->nullable();
                $t->longText('error')->nullable();
                $t->timestamps();
                $t->unique(['event_id', 'recipient_key'], 'soc_delivery_dedupe');
            });
        }
        if (! Schema::hasTable('soccer_groupme_connections')) {
            Schema::create('soccer_groupme_connections', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->unsignedBigInteger('user_id');
                $t->string('provider_user_id', 80);
                $t->longText('token');
                $t->timestamps();
                $t->unique(['tenant_id', 'user_id'], 'soc_groupme_user');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('soccer_groupme_connections');
        Schema::dropIfExists('soccer_deliveries');
        Schema::dropIfExists('soccer_events');
        Schema::dropIfExists('soccer_sessions');
        Schema::dropIfExists('soccer_challenges');
        Schema::dropIfExists('soccer_import_rows');
        Schema::dropIfExists('soccer_refunds');
        Schema::dropIfExists('soccer_payments');
        Schema::dropIfExists('soccer_invoices');
        Schema::dropIfExists('soccer_registrations');
        Schema::dropIfExists('soccer_releases');
        Schema::dropIfExists('soccer_coaches');
        Schema::dropIfExists('soccer_players');
        Schema::dropIfExists('soccer_families');
        Schema::dropIfExists('soccer_seasons');
        Schema::dropIfExists('soccer_settings');
    }
};
