<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendReviewHistories();
        $this->createWishlistLists();
        $this->extendWishlistItems();
        $this->createWishlistOutreachQueue();
        $this->seedModernForestryAlphaDefaults();
        $this->backfillModernForestryTenantOwnership();
        $this->normalizeProductReviewTaskPresentation();
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_wishlist_outreach_queue');

        if (Schema::hasTable('marketing_profile_wishlist_items')) {
            Schema::table('marketing_profile_wishlist_items', function (Blueprint $table): void {
                foreach ([
                    'mpwi_list_store_product_unique',
                    'mpwi_guest_token_idx',
                    'mpwi_wishlist_list_id_idx',
                ] as $index) {
                    try {
                        $table->dropIndex($index);
                    } catch (\Throwable) {
                    }
                }

                try {
                    $table->dropForeign(['wishlist_list_id']);
                } catch (\Throwable) {
                }

                foreach (['wishlist_list_id', 'guest_token'] as $column) {
                    if (Schema::hasColumn('marketing_profile_wishlist_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });

            Schema::table('marketing_profile_wishlist_items', function (Blueprint $table): void {
                $table->foreignId('marketing_profile_id')->nullable(false)->change();
            });

            Schema::table('marketing_profile_wishlist_items', function (Blueprint $table): void {
                try {
                    $table->unique(
                        ['marketing_profile_id', 'store_key', 'product_id'],
                        'mpwi_profile_store_product_unique'
                    );
                } catch (\Throwable) {
                }
            });
        }

        Schema::dropIfExists('marketing_wishlist_lists');

        if (Schema::hasTable('marketing_review_histories')) {
            Schema::table('marketing_review_histories', function (Blueprint $table): void {
                foreach ([
                    'mrh_tenant_product_idx',
                    'mrh_order_id_idx',
                    'mrh_order_line_id_idx',
                    'mrh_variant_id_idx',
                    'mrh_published_at_idx',
                    'mrh_reward_eligibility_idx',
                    'mrh_reward_award_idx',
                    'mrh_tenant_id_idx',
                ] as $index) {
                    try {
                        $table->dropIndex($index);
                    } catch (\Throwable) {
                    }
                }

                foreach (['tenant_id'] as $foreignColumn) {
                    try {
                        $table->dropForeign([$foreignColumn]);
                    } catch (\Throwable) {
                    }
                }

                foreach ([
                    'tenant_id',
                    'order_id',
                    'order_line_id',
                    'variant_id',
                    'published_at',
                    'reward_eligibility_status',
                    'reward_award_status',
                    'reward_amount_cents',
                    'reward_rule_key',
                    'media_assets',
                ] as $column) {
                    if (Schema::hasColumn('marketing_review_histories', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    protected function extendReviewHistories(): void
    {
        if (! Schema::hasTable('marketing_review_histories')) {
            return;
        }

        Schema::table('marketing_review_histories', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_review_histories', 'tenant_id')) {
                $table->foreignId('tenant_id')->nullable()->after('marketing_profile_id')->constrained('tenants')->nullOnDelete();
                $table->index(['tenant_id', 'store_key', 'product_id'], 'mrh_tenant_product_idx');
                $table->index('tenant_id', 'mrh_tenant_id_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'order_id')) {
                $table->unsignedBigInteger('order_id')->nullable()->after('product_id');
                $table->index('order_id', 'mrh_order_id_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'order_line_id')) {
                $table->unsignedBigInteger('order_line_id')->nullable()->after('order_id');
                $table->index('order_line_id', 'mrh_order_line_id_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'variant_id')) {
                $table->string('variant_id', 120)->nullable()->after('product_handle');
                $table->index('variant_id', 'mrh_variant_id_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('approved_at');
                $table->index('published_at', 'mrh_published_at_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'reward_eligibility_status')) {
                $table->string('reward_eligibility_status', 40)->nullable()->after('notification_sent_at');
                $table->index('reward_eligibility_status', 'mrh_reward_eligibility_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'reward_award_status')) {
                $table->string('reward_award_status', 40)->nullable()->after('reward_eligibility_status');
                $table->index('reward_award_status', 'mrh_reward_award_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'reward_amount_cents')) {
                $table->integer('reward_amount_cents')->nullable()->after('reward_award_status');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'reward_rule_key')) {
                $table->string('reward_rule_key', 120)->nullable()->after('reward_amount_cents');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'media_assets')) {
                $table->json('media_assets')->nullable()->after('media_count');
            }
        });
    }

    protected function createWishlistLists(): void
    {
        if (Schema::hasTable('marketing_wishlist_lists')) {
            return;
        }

        Schema::create('marketing_wishlist_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
            $table->string('guest_token', 120)->nullable()->index();
            $table->string('store_key', 80)->nullable()->index();
            $table->string('name', 160);
            $table->boolean('is_default')->default(false)->index();
            $table->string('status', 40)->default('active')->index();
            $table->string('source', 120)->nullable()->index();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'marketing_profile_id', 'is_default'], 'mwl_profile_default_idx');
            $table->index(['tenant_id', 'guest_token', 'is_default'], 'mwl_guest_default_idx');
        });
    }

    protected function extendWishlistItems(): void
    {
        if (! Schema::hasTable('marketing_profile_wishlist_items')) {
            return;
        }

        Schema::table('marketing_profile_wishlist_items', function (Blueprint $table): void {
            $table->foreignId('marketing_profile_id')->nullable()->change();
        });

        Schema::table('marketing_profile_wishlist_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_profile_wishlist_items', 'wishlist_list_id')) {
                $table->foreignId('wishlist_list_id')->nullable()->after('marketing_profile_id')->constrained('marketing_wishlist_lists')->nullOnDelete();
                $table->index('wishlist_list_id', 'mpwi_wishlist_list_id_idx');
            }
            if (! Schema::hasColumn('marketing_profile_wishlist_items', 'guest_token')) {
                $table->string('guest_token', 120)->nullable()->after('wishlist_list_id');
                $table->index('guest_token', 'mpwi_guest_token_idx');
            }
        });

        Schema::table('marketing_profile_wishlist_items', function (Blueprint $table): void {
            try {
                $table->dropUnique('mpwi_profile_store_product_unique');
            } catch (\Throwable) {
            }

            try {
                $table->unique(
                    ['wishlist_list_id', 'store_key', 'product_id'],
                    'mpwi_list_store_product_unique'
                );
            } catch (\Throwable) {
            }
        });
    }

    protected function createWishlistOutreachQueue(): void
    {
        if (Schema::hasTable('marketing_wishlist_outreach_queue')) {
            return;
        }

        Schema::create('marketing_wishlist_outreach_queue', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
            $table->foreignId('wishlist_list_id')->nullable()->constrained('marketing_wishlist_lists')->nullOnDelete();
            $table->foreignId('wishlist_item_id')->nullable()->constrained('marketing_profile_wishlist_items')->nullOnDelete();
            $table->string('store_key', 80)->nullable()->index();
            $table->string('product_id', 120)->nullable()->index();
            $table->string('product_variant_id', 120)->nullable()->index();
            $table->string('product_handle', 160)->nullable()->index();
            $table->string('product_title')->nullable();
            $table->string('channel', 40)->default('sms')->index();
            $table->string('queue_status', 40)->default('queued')->index();
            $table->string('offer_type', 40)->nullable()->index();
            $table->decimal('offer_value', 10, 2)->nullable();
            $table->string('offer_code', 80)->nullable()->index();
            $table->string('provider', 80)->nullable()->index();
            $table->string('provider_message_id', 160)->nullable()->index();
            $table->text('message_body')->nullable();
            $table->text('delivery_error')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('last_updated_by')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('redeemed_at')->nullable()->index();
            $table->timestamp('last_attempt_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'queue_status'], 'mwq_tenant_status_idx');
            $table->index(['tenant_id', 'marketing_profile_id', 'product_id'], 'mwq_tenant_profile_product_idx');
        });
    }

    protected function seedModernForestryAlphaDefaults(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        $tenant = DB::table('tenants')
            ->where('slug', 'modern-forestry')
            ->first(['id']);

        if (! $tenant) {
            return;
        }

        $tenantId = (int) $tenant->id;
        $now = now();

        if (Schema::hasTable('tenant_marketing_settings')) {
            $existing = DB::table('tenant_marketing_settings')
                ->where('tenant_id', $tenantId)
                ->where('key', 'candle_cash_integration_config')
                ->value('value');

            $decoded = is_string($existing) ? json_decode($existing, true) : [];
            $decoded = is_array($decoded) ? $decoded : [];

            $payload = array_merge($decoded, [
                'reviews_enabled' => true,
                'wishlist_enabled' => true,
                'wishlist_guest_enabled' => true,
                'wishlist_discount_outreach_enabled' => true,
                'rewards_incentivized_reviews_enabled' => true,
                'product_review_enabled' => true,
                'product_review_platform' => 'backstage_native',
                'product_review_matching_strategy' => 'order_line_match',
                'product_review_moderation_enabled' => true,
                'product_review_allow_guest' => true,
                'product_review_min_length' => 24,
                'product_review_reward_amount' => 1.00,
                'product_review_notification_email' => 'info@theforestrystudio.com',
                'product_review_reward_amount_cents' => 100,
                'product_review_require_order_match' => true,
                'product_review_reward_dedupe_mode' => 'order_line',
                'review_auto_publish_enabled' => false,
                'sms_provider' => 'twilio',
                'sms_provider_enabled' => true,
            ]);

            DB::table('tenant_marketing_settings')->updateOrInsert(
                ['tenant_id' => $tenantId, 'key' => 'candle_cash_integration_config'],
                [
                    'value' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                    'description' => 'Alpha retention widgets and review incentive settings for Modern Forestry.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        if (Schema::hasTable('tenant_module_states')) {
            foreach (['reviews', 'wishlist'] as $moduleKey) {
                DB::table('tenant_module_states')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'module_key' => $moduleKey],
                    [
                        'enabled_override' => true,
                        'setup_status' => 'live',
                        'coming_soon_override' => false,
                        'upgrade_prompt_override' => false,
                        'metadata' => json_encode([
                            'source' => 'modern_forestry_alpha_default',
                        ], JSON_UNESCAPED_SLASHES),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }

    protected function backfillModernForestryTenantOwnership(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        $tenant = DB::table('tenants')
            ->where('slug', 'modern-forestry')
            ->first(['id']);

        if (! $tenant) {
            return;
        }

        $tenantId = (int) $tenant->id;
        $now = now();

        if (Schema::hasTable('shopify_stores')) {
            DB::table('shopify_stores')
                ->where(function ($query): void {
                    $query->where('shop_domain', 'modernforestry.myshopify.com')
                        ->orWhere(function ($builder): void {
                            $builder->where('store_key', 'retail')
                                ->whereNull('shop_domain');
                        });
                })
                ->update([
                    'tenant_id' => $tenantId,
                ]);
        }

        if (Schema::hasTable('marketing_review_histories')) {
            DB::table('marketing_review_histories')
                ->whereNull('tenant_id')
                ->where('store_key', 'retail')
                ->update([
                    'tenant_id' => $tenantId,
                ]);

            DB::table('marketing_review_histories')
                ->where('store_key', 'retail')
                ->where('status', 'approved')
                ->where('is_published', true)
                ->whereNull('published_at')
                ->update([
                    'published_at' => DB::raw('coalesce(approved_at, reviewed_at, submitted_at, created_at)'),
                ]);
        }

        if (Schema::hasTable('marketing_profile_wishlist_items')) {
            DB::table('marketing_profile_wishlist_items')
                ->whereNull('tenant_id')
                ->where('store_key', 'retail')
                ->update([
                    'tenant_id' => $tenantId,
                ]);
        }

        if (Schema::hasTable('marketing_wishlist_lists')) {
            $listIds = DB::table('marketing_profile_wishlist_items')
                ->where('store_key', 'retail')
                ->whereNotNull('wishlist_list_id')
                ->pluck('wishlist_list_id')
                ->filter()
                ->unique()
                ->all();

            DB::table('marketing_wishlist_lists')
                ->whereNull('tenant_id')
                ->where(function ($query) use ($listIds): void {
                    $query->where('store_key', 'retail');

                    if ($listIds !== []) {
                        $query->orWhereIn('id', $listIds);
                    }
                })
                ->update([
                    'tenant_id' => $tenantId,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('marketing_wishlist_outreach_queue')) {
            DB::table('marketing_wishlist_outreach_queue')
                ->whereNull('tenant_id')
                ->where('store_key', 'retail')
                ->update([
                    'tenant_id' => $tenantId,
                    'updated_at' => $now,
                ]);
        }
    }

    protected function normalizeProductReviewTaskPresentation(): void
    {
        if (! Schema::hasTable('candle_cash_tasks')) {
            return;
        }

        $task = DB::table('candle_cash_tasks')
            ->where('handle', 'product-review')
            ->first(['id', 'metadata']);

        if (! $task) {
            return;
        }

        $metadata = is_string($task->metadata) ? json_decode($task->metadata, true) : [];
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['customer_visible'] = true;
        $metadata['storefront_contract'] = 'native_product_reviews_v1';

        DB::table('candle_cash_tasks')
            ->where('id', $task->id)
            ->update([
                'button_text' => 'Write a review',
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
