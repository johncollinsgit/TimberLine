<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['designs', 'revisions', 'requests', 'assignments', 'events'] as $kind) {
            $name = 'wholesale_label_'.$kind;
            if (Schema::hasTable($name)) {
                continue;
            }
            Schema::create($name, function (Blueprint $t) use ($kind): void {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id');
                $t->string('shop_domain', 120);
                $t->string('customer_id', 32);
                if ($kind === 'designs') {
                    $t->string('name', 120);
                    $t->uuid('approved_revision_id')->nullable();
                    $t->timestamp('archived_at')->nullable();
                } elseif ($kind === 'revisions') {
                    $t->uuid('design_id');
                    $t->string('source', 24);
                    $t->string('original_name', 200);
                    $t->string('mime', 80);
                    $t->string('original_path', 240);
                    $t->string('sha256', 64);
                    $t->unsignedBigInteger('byte_size');
                    $t->string('preview_path', 240)->nullable();
                    $t->string('vector_path', 240)->nullable();
                    $t->string('vector_preview_path', 240)->nullable();
                    $t->string('vector_sha256', 64)->nullable();
                    $t->string('status', 24)->default('queued');
                    $t->json('settings')->nullable();
                    $t->string('failure', 255)->nullable();
                    $t->unsignedBigInteger('created_by')->nullable();
                    $t->unsignedBigInteger('approved_by')->nullable();
                    $t->timestamp('approved_at')->nullable();
                } elseif ($kind === 'requests') {
                    $t->string('idempotency_key', 64);
                    $t->string('payload_sha256', 64);
                    $t->uuid('design_id')->nullable();
                    $t->uuid('revision_id')->nullable();
                    $t->string('mode', 16);
                    $t->string('status', 24)->default('prepared');
                    $t->json('groups');
                    $t->text('instructions')->nullable();
                    $t->unsignedInteger('fee_cents');
                    $t->string('shopify_order_id', 32)->nullable();
                    $t->timestamp('bound_at')->nullable();
                } elseif ($kind === 'assignments') {
                    $t->uuid('request_id')->nullable();
                    $t->uuid('revision_id')->nullable();
                    $t->uuid('production_revision_id')->nullable();
                    $t->unsignedBigInteger('order_id');
                    $t->string('shopify_order_id', 32);
                    $t->string('shopify_line_id', 32);
                    $t->string('status', 24)->default('needs_review');
                    $t->json('snapshot');
                } else {
                    $t->uuid('design_id')->nullable();
                    $t->uuid('request_id')->nullable();
                    $t->uuid('revision_id')->nullable();
                    $t->unsignedBigInteger('actor_id')->nullable();
                    $t->string('action', 32);
                    $t->json('details')->nullable();
                }
                $t->timestamps();
            });
        }
        foreach ([
            ['designs', ['tenant_id', 'shop_domain', 'customer_id'], 'wl_design_owner', false],
            ['revisions', ['tenant_id', 'design_id'], 'wl_revision_design', false],
            ['requests', ['tenant_id', 'shop_domain', 'customer_id', 'idempotency_key'], 'wl_request_once', true],
            ['requests', ['tenant_id', 'shopify_order_id'], 'wl_request_order', false],
            ['assignments', ['tenant_id', 'shop_domain', 'shopify_order_id', 'shopify_line_id'], 'wl_order_line_once', true],
            ['events', ['tenant_id', 'design_id', 'created_at'], 'wl_event_design', false],
        ] as [$kind, $columns, $name, $unique]) {
            $table = 'wholesale_label_'.$kind;
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($columns, $name) : $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (['events', 'assignments', 'requests', 'revisions', 'designs'] as $kind) {
            Schema::dropIfExists('wholesale_label_'.$kind);
        }
    }
};
