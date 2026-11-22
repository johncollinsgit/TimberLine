<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('watershed_terms')) {
            Schema::create('watershed_terms', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('term_key', 40);
                $t->string('name', 100);
                $t->unsignedInteger('school_year');
                $t->boolean('registration_open')->default(false);
                $t->timestamps();
                $t->unique(['tenant_id', 'term_key'], 'ws_term_key');
            });
        }
        if (! Schema::hasTable('watershed_family_states')) {
            Schema::create('watershed_family_states', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('family_id');
                $t->boolean('preapproved')->default(false);
                $t->timestamps();
                $t->unique(['tenant_id', 'family_id'], 'ws_family_state');
            });
        }
        if (! Schema::hasTable('watershed_applications')) {
            Schema::create('watershed_applications', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('term_id');
                $t->uuid('family_id');
                $t->string('status', 32);
                $t->unsignedInteger('version')->default(1);
                $t->uuid('request_id');
                $t->longText('payload');
                $t->unsignedInteger('amount_cents')->default(0);
                $t->timestamps();
                $t->unique(['tenant_id', 'term_id', 'family_id'], 'ws_app_family_term');
                $t->unique(['tenant_id', 'request_id'], 'ws_app_request');
            });
        }
        if (! Schema::hasTable('watershed_releases')) {
            Schema::create('watershed_releases', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('application_id');
                $t->uuid('player_id');
                $t->unsignedInteger('application_version');
                $t->longText('answers');
                $t->string('sha256', 64);
                $t->string('form_version', 60);
                $t->timestamp('signed_at');
                $t->timestamps();
                $t->unique(['application_id', 'application_version', 'player_id'], 'ws_release_version');
            });
        }
        if (! Schema::hasTable('watershed_adjustments')) {
            Schema::create('watershed_adjustments', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('player_id');
                $t->unsignedInteger('school_year');
                $t->longText('payload');
                $t->timestamps();
                $t->unique(['tenant_id', 'player_id', 'school_year'], 'ws_adjustment_year');
            });
        }
        if (! Schema::hasTable('watershed_challenges')) {
            Schema::create('watershed_challenges', function (Blueprint $t) {
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
        if (! Schema::hasTable('watershed_sessions')) {
            Schema::create('watershed_sessions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('family_id');
                $t->string('token_hash', 64)->unique('ws_session_token');
                $t->timestamp('expires_at');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('watershed_invoices')) {
            Schema::create('watershed_invoices', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->uuid('application_id')->unique('ws_invoice_app');
                $t->unsignedInteger('amount_cents');
                $t->string('status', 24)->default('unpaid');
                $t->string('account_id', 190)->nullable();
                $t->uuid('attempt_id')->nullable();
                $t->longText('checkout_request')->nullable();
                $t->string('checkout_id', 190)->nullable();
                $t->string('payment_intent_id', 190)->nullable();
                $t->timestamp('paid_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('watershed_events')) {
            Schema::create('watershed_events', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('event_key', 190);
                $t->string('action', 60);
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->longText('payload');
                $t->timestamps();
                $t->unique(['tenant_id', 'event_key'], 'ws_event_key');
            });
        }
        if (! Schema::hasTable('watershed_deliveries')) {
            Schema::create('watershed_deliveries', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('delivery_key', 190);
                $t->longText('payload');
                $t->string('status', 24)->default('pending');
                $t->string('provider_message_id', 190)->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'delivery_key'], 'ws_delivery_key');
            });
        }
        if (! Schema::hasTable('watershed_imports')) {
            Schema::create('watershed_imports', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('fingerprint', 64);
                $t->uuid('family_id')->nullable();
                $t->longText('payload');
                $t->timestamps();
                $t->unique(['tenant_id', 'fingerprint'], 'ws_import_source');
            });
        }
    }

    public function down(): void
    { /* Retain signed releases, approvals and financial evidence on code rollback. */
    }
};
