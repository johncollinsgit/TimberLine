<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one normalized per-tenant integration store (the "connections" table).
 *
 * Today OAuth/API credentials are scattered across bespoke tables (shopify_stores,
 * square_config JSON, google_business_profile_connections with no tenant_id,
 * tenant_marketing_settings). This is the single, tenant-owned, encrypted home
 * every provider connection plugs into, modeled on the already-correct
 * TenantEmailSetting / GoogleBusinessProfileConnection shapes.
 *
 * NOTE: additive only. Nothing reads from this yet — the existing bespoke flows
 * are untouched. Connectors migrate onto it one provider at a time.
 * See docs/architecture/module-standardization-and-readiness-2026-07-07.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('provider'); // shopify | square | google_business | quickbooks | etsy | meta ...

            // A provider can expose more than one account (e.g. two Shopify shops).
            // NOT NULL + '' default means "one unnamed connection per provider" until
            // an account id is known, and the unique index below stays enforceable.
            $table->string('external_account_id')->default('');
            $table->string('external_account_label')->nullable();

            $table->string('status')->default('pending'); // pending | connected | error | disconnected

            $table->text('access_token')->nullable();  // encrypted at rest (model cast)
            $table->text('refresh_token')->nullable();  // encrypted at rest (model cast)
            $table->string('token_type')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('metadata')->nullable();

            $table->unsignedBigInteger('connected_by_user_id')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('last_error_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'external_account_id'], 'integration_connections_tenant_provider_account_unique');
            $table->index(['tenant_id', 'provider']);
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_connections');
    }
};
