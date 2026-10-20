<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->create('marketplace_settings', function (Blueprint $t): void {
            $t->unsignedBigInteger('connection_id')->nullable();
            $t->boolean('checkout_enabled')->default(false);
            $t->json('readiness')->nullable();
            $t->text('shipping_policy')->nullable();
            $t->text('return_policy')->nullable();
            $t->text('privacy_policy')->nullable();
            $t->string('support_email')->nullable();
        });
        $this->create('marketplace_connections', function (Blueprint $t): void {
            $t->uuid('uuid');
            $t->string('shop_domain', 120);
            $t->string('shop_gid', 100);
            $t->string('publication_gid', 100);
            $t->longText('credentials');
            $t->boolean('test_mode')->default(true);
            $t->timestamp('verified_at')->nullable();
            $t->unsignedBigInteger('created_by');
        });
        $this->create('marketplace_identities', function (Blueprint $t): void {
            $t->string('email', 254);
            $t->string('name')->nullable();
            $t->boolean('active')->default(true);
        });
        $this->create('marketplace_login_links', function (Blueprint $t): void {
            $t->string('token_hash', 64);
            $t->string('email', 254);
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
        });
        $this->create('marketplace_workspace_links', function (Blueprint $t): void {
            $t->string('token_hash', 64);
            $t->unsignedBigInteger('maker_id');
            $t->unsignedBigInteger('identity_id');
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
        });
        $this->create('marketplace_makers', function (Blueprint $t): void {
            $t->string('slug', 160);
            $t->string('name', 160);
            $t->string('city', 100);
            $t->text('story');
            $t->string('image_url', 1000)->nullable();
            $t->string('status', 30)->default('pending');
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('connected_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
        });
        $this->create('marketplace_memberships', function (Blueprint $t): void {
            $t->unsignedBigInteger('maker_id');
            $t->unsignedBigInteger('identity_id');
            $t->string('role', 20)->default('owner');
            $t->boolean('active')->default(true);
        });
        $this->create('marketplace_pickup_locations', function (Blueprint $t): void {
            $t->string('name', 160);
            $t->text('address');
            $t->text('instructions');
            $t->string('shopify_location_gid', 100);
            $t->boolean('active')->default(false);
        });
        $this->create('marketplace_listings', function (Blueprint $t): void {
            $t->unsignedBigInteger('maker_id');
            $t->string('slug', 160);
            $t->string('title', 160);
            $t->string('variant_label', 160)->nullable();
            $t->string('category', 80);
            $t->text('description');
            $t->json('images');
            $t->unsignedInteger('price_cents');
            $t->unsignedInteger('stock_quantity')->default(0);
            $t->boolean('shipping')->default(false);
            $t->json('pickup_location_ids')->nullable();
            $t->string('lead_time', 160)->nullable();
            $t->string('status', 30)->default('pending');
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedInteger('published_revision')->nullable();
            $t->unsignedBigInteger('source_variant_id')->nullable();
            $t->string('shopify_product_gid', 100)->nullable();
            $t->string('shopify_variant_gid', 100)->nullable();
            $t->string('shopify_inventory_gid', 100)->nullable();
            $t->string('shipping_location_gid', 100)->nullable();
            $t->unsignedBigInteger('connection_id')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
        });
        $this->create('marketplace_inventory_allocations', function (Blueprint $t): void {
            $t->unsignedBigInteger('listing_id');
            $t->uuid('request_uuid');
            $t->integer('quantity');
            $t->unsignedBigInteger('source_variant_id')->nullable();
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->unsignedBigInteger('identity_id')->nullable();
            $t->string('reason', 100);
            $t->string('shopify_location_gid', 100);
            $t->timestamp('applied_at')->nullable();
        });
        $this->create('marketplace_inventory_levels', function (Blueprint $t): void {
            $t->unsignedBigInteger('listing_id');
            $t->string('shopify_location_gid', 100);
            $t->unsignedInteger('quantity')->default(0);
        });
        $this->create('marketplace_rate_agreements', function (Blueprint $t): void {
            $t->string('scope_key', 80);
            $t->unsignedInteger('version');
            $t->string('kind', 20);
            $t->unsignedInteger('value');
            $t->text('terms');
            $t->unsignedBigInteger('created_by')->nullable();
        });
        $this->create('marketplace_rate_acceptances', function (Blueprint $t): void {
            $t->unsignedBigInteger('maker_id');
            $t->unsignedBigInteger('rate_id');
            $t->unsignedBigInteger('identity_id');
        });
        $this->create('marketplace_checkouts', function (Blueprint $t): void {
            $t->uuid('uuid');
            $t->string('session_hash', 64);
            $t->unsignedBigInteger('identity_id')->nullable();
            $t->unsignedBigInteger('connection_id');
            $t->string('group_key', 80);
            $t->string('status', 30)->default('creating');
            $t->longText('snapshot');
            $t->longText('provider_cart')->nullable();
            $t->string('provider_order_gid', 100)->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('paid_at')->nullable();
            $t->string('failure_code', 80)->nullable();
        });
        $this->create('marketplace_orders', function (Blueprint $t): void {
            $t->unsignedBigInteger('checkout_id');
            $t->unsignedBigInteger('connection_id');
            $t->string('provider_order_gid', 100);
            $t->string('number', 100);
            $t->string('currency', 3)->default('USD');
            $t->unsignedBigInteger('identity_id')->nullable();
            $t->longText('customer');
            $t->string('email_hash', 64);
            $t->string('group_key', 80);
            $t->unsignedInteger('merchandise_cents');
            $t->unsignedInteger('discount_cents')->default(0);
            $t->unsignedInteger('shipping_cents')->default(0);
            $t->unsignedInteger('tax_cents')->default(0);
            $t->unsignedInteger('total_cents');
            $t->string('status', 30)->default('paid');
            $t->boolean('test_mode')->default(true);
            $t->timestamp('shipping_allocated_at')->nullable();
            $t->unsignedInteger('shipping_subsidy_cents')->default(0);
            $t->unsignedInteger('processing_cost_cents')->nullable();
            $t->json('shipping_refunds')->nullable();
            $t->boolean('review_required')->default(false);
            $t->text('review_reason')->nullable();
        });
        $this->create('marketplace_order_lines', function (Blueprint $t): void {
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('maker_id');
            $t->unsignedBigInteger('listing_id');
            $t->string('provider_line_gid', 100);
            $t->string('title');
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('refunded_quantity')->default(0);
            $t->unsignedInteger('unit_price_cents');
            $t->unsignedInteger('maker_unit_cents');
            $t->unsignedBigInteger('rate_id');
            $t->unsignedInteger('discount_cents')->default(0);
            $t->unsignedInteger('shipping_credit_cents')->default(0);
            $t->string('fulfillment_status', 30)->default('unfulfilled');
            $t->string('tracking_number', 160)->nullable();
            $t->string('tracking_url', 1000)->nullable();
            $t->timestamp('fulfilled_at')->nullable();
        });
        $this->create('marketplace_ledger_entries', function (Blueprint $t): void {
            $t->unsignedBigInteger('maker_id');
            $t->unsignedBigInteger('order_line_id');
            $t->string('source_key', 160);
            $t->string('kind', 30);
            $t->integer('amount_cents');
            $t->unsignedBigInteger('statement_id')->nullable();
            $t->timestamp('eligible_at')->nullable();
        });
        $this->create('marketplace_statements', function (Blueprint $t): void {
            $t->uuid('uuid');
            $t->unsignedBigInteger('maker_id');
            $t->integer('amount_cents');
            $t->string('status', 30)->default('review');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('bank_reference', 160)->nullable();
        });
        $this->create('marketplace_webhook_events', function (Blueprint $t): void {
            $t->unsignedBigInteger('connection_id');
            $t->string('event_id', 120);
            $t->string('topic', 80);
            $t->string('payload_hash', 64);
            $t->longText('payload');
            $t->string('status', 30)->default('pending');
            $t->string('failure_code', 100)->nullable();
            $t->timestamp('processed_at')->nullable();
        });
        $this->create('marketplace_operations', function (Blueprint $t): void {
            $t->uuid('uuid');
            $t->unsignedBigInteger('connection_id');
            $t->string('kind', 50);
            $t->unsignedBigInteger('subject_id');
            $t->string('status', 30)->default('prepared');
            $t->longText('payload');
            $t->longText('result')->nullable();
            $t->unsignedBigInteger('actor_user_id')->nullable();
        });
        $this->create('marketplace_audits', function (Blueprint $t): void {
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->unsignedBigInteger('identity_id')->nullable();
            $t->string('action', 100);
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('details')->nullable();
        });

        // MySQL commits DDL independently. Repair named keys after any interrupted run.
        foreach ($this->keys() as [$table, $columns, $name, $unique]) {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($columns, $name) : $t->index($columns, $name));
            }
        }
    }

    private function create(string $name, Closure $columns): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, function (Blueprint $t) use ($columns): void {
                $t->id();
                $columns($t);
                $t->timestamps();
            });
        }
    }

    private function keys(): array
    {
        return [
            ['marketplace_connections', ['uuid'], 'mm_connection_uuid', true],
            ['marketplace_identities', ['email'], 'mm_identity_email', true],
            ['marketplace_login_links', ['token_hash'], 'mm_login_hash', true],
            ['marketplace_workspace_links', ['token_hash'], 'mm_workspace_link_hash', true],
            ['marketplace_makers', ['slug'], 'mm_maker_slug', true],
            ['marketplace_memberships', ['maker_id', 'identity_id'], 'mm_membership_pair', true],
            ['marketplace_memberships', ['identity_id', 'active'], 'mm_membership_identity', false],
            ['marketplace_listings', ['slug'], 'mm_listing_slug', true],
            ['marketplace_listings', ['maker_id', 'status'], 'mm_listing_maker', false],
            ['marketplace_inventory_allocations', ['request_uuid'], 'mm_allocation_request', true],
            ['marketplace_inventory_levels', ['listing_id', 'shopify_location_gid'], 'mm_inventory_level', true],
            ['marketplace_rate_agreements', ['scope_key', 'version'], 'mm_rate_version', true],
            ['marketplace_rate_acceptances', ['maker_id', 'rate_id'], 'mm_rate_acceptance', true],
            ['marketplace_checkouts', ['uuid'], 'mm_checkout_uuid', true],
            ['marketplace_checkouts', ['provider_order_gid'], 'mm_checkout_provider_order', true],
            ['marketplace_orders', ['checkout_id'], 'mm_order_checkout', true],
            ['marketplace_orders', ['connection_id', 'provider_order_gid'], 'mm_order_provider', true],
            ['marketplace_orders', ['email_hash'], 'mm_order_email', false],
            ['marketplace_order_lines', ['order_id', 'provider_line_gid'], 'mm_order_line_provider', true],
            ['marketplace_order_lines', ['maker_id', 'fulfillment_status'], 'mm_order_line_maker', false],
            ['marketplace_ledger_entries', ['source_key'], 'mm_ledger_source', true],
            ['marketplace_ledger_entries', ['maker_id', 'statement_id', 'eligible_at'], 'mm_ledger_unpaid', false],
            ['marketplace_statements', ['uuid'], 'mm_statement_uuid', true],
            ['marketplace_webhook_events', ['connection_id', 'event_id'], 'mm_webhook_event', true],
            ['marketplace_operations', ['uuid'], 'mm_operation_uuid', true],
        ];
    }

    public function down(): void
    {
        foreach (array_reverse(['settings', 'connections', 'identities', 'login_links', 'workspace_links', 'makers', 'memberships', 'pickup_locations', 'listings', 'inventory_allocations', 'inventory_levels', 'rate_agreements', 'rate_acceptances', 'checkouts', 'orders', 'order_lines', 'ledger_entries', 'statements', 'webhook_events', 'operations', 'audits']) as $table) {
            Schema::dropIfExists('marketplace_'.$table);
        }
    }
};
