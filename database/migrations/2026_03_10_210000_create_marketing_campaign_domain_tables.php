<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_segments', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('channel_scope')->nullable();
            $table->json('rules_json')->nullable();
            $table->boolean('is_system')->default(false)->index();
            $table->timestamp('last_previewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('marketing_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('channel')->default('sms')->index();
            $table->foreignId('segment_id')->nullable()->constrained('marketing_segments')->nullOnDelete();
            $table->string('objective')->nullable()->index();
            $table->unsignedInteger('attribution_window_days')->default(7);
            $table->string('coupon_code')->nullable();
            $table->json('send_window_json')->nullable();
            $table->json('quiet_hours_override_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_message_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('channel')->index();
            $table->string('objective')->nullable()->index();
            $table->string('tone')->nullable();
            $table->text('template_text');
            $table->json('variables_json')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('marketing_campaign_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('marketing_message_templates')->nullOnDelete();
            $table->string('name');
            $table->string('variant_key')->nullable();
            $table->text('message_text');
            $table->unsignedInteger('weight')->default(100);
            $table->boolean('is_control')->default(false);
            $table->string('status')->default('draft')->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status'], 'mcv_campaign_status_idx');
        });

        Schema::create('marketing_campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->foreignId('marketing_profile_id')->constrained('marketing_profiles')->cascadeOnDelete();
            $table->json('segment_snapshot')->nullable();
            $table->json('recommendation_snapshot')->nullable();
            $table->foreignId('variant_id')->nullable()->constrained('marketing_campaign_variants')->nullOnDelete();
            $table->string('channel')->index();
            $table->string('status')->default('pending')->index();
            $table->json('reason_codes')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('last_status_note')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'marketing_profile_id'], 'mcr_campaign_profile_unique');
        });

        Schema::create('marketing_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();
            $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
            $table->foreignId('related_variant_id')->nullable()->constrained('marketing_campaign_variants')->nullOnDelete();
            $table->string('title');
            $table->text('summary');
            $table->json('details_json')->nullable();
            $table->string('status')->default('pending')->index();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->boolean('created_by_system')->default(true)->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_send_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained('marketing_campaign_recipients')->nullOnDelete();
            $table->foreignId('recommendation_id')->nullable()->constrained('marketing_recommendations')->nullOnDelete();
            $table->string('approval_type')->index();
            $table->string('status')->default('pending')->index();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_profile_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_profile_id')->constrained('marketing_profiles')->cascadeOnDelete();
            $table->string('score_type')->index();
            $table->integer('score');
            $table->json('reasons_json')->nullable();
            $table->timestamp('calculated_at')->index();
            $table->timestamps();

            $table->index(['marketing_profile_id', 'score_type'], 'mps_profile_type_idx');
        });

        Schema::create('marketing_campaign_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->foreignId('marketing_profile_id')->constrained('marketing_profiles')->cascadeOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained('marketing_campaign_recipients')->nullOnDelete();
            $table->string('attribution_type')->index();
            $table->string('source_type')->nullable()->index();
            $table->string('source_id')->nullable()->index();
            $table->timestamp('converted_at')->index();
            $table->decimal('order_total', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $now = now();
        $segments = [
            [
                'name' => 'High Value Customers',
                'channel_scope' => 'any',
                'description' => 'Profiles with higher total spend or order depth.',
                'rules_json' => [
                    'logic' => 'or',
                    'conditions' => [
                        ['field' => 'total_spent', 'operator' => 'gt', 'value' => 300],
                        ['field' => 'total_orders', 'operator' => 'gt', 'value' => 5],
                    ],
                    'groups' => [],
                ],
            ],
            [
                'name' => 'Repeat Buyers',
                'channel_scope' => 'any',
                'description' => 'Profiles with at least two linked orders.',
                'rules_json' => [
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'total_orders', 'operator' => 'gt', 'value' => 1],
                    ],
                    'groups' => [],
                ],
            ],
            [
                'name' => 'Lapsed Customers',
                'channel_scope' => 'sms',
                'description' => 'Profiles with no recent order activity.',
                'rules_json' => [
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'days_since_last_order', 'operator' => 'gt', 'value' => 60],
                    ],
                    'groups' => [],
                ],
            ],
            [
                'name' => 'Event Buyers',
                'channel_scope' => 'any',
                'description' => 'Profiles linked to event purchase signals.',
                'rules_json' => [
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'purchased_at_event', 'operator' => 'eq', 'value' => true],
                    ],
                    'groups' => [],
                ],
            ],
            [
                'name' => 'Online Buyers',
                'channel_scope' => 'any',
                'description' => 'Profiles with Shopify/online channel signals.',
                'rules_json' => [
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'source_channel', 'operator' => 'contains', 'value' => 'online'],
                    ],
                    'groups' => [],
                ],
            ],
            [
                'name' => 'SMS Consented',
                'channel_scope' => 'sms',
                'description' => 'Profiles with explicit SMS consent.',
                'rules_json' => [
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'has_sms_consent', 'operator' => 'eq', 'value' => true],
                    ],
                    'groups' => [],
                ],
            ],
            [
                'name' => 'Square Buyers',
                'channel_scope' => 'any',
                'description' => 'Profiles with Square-linked records.',
                'rules_json' => [
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'has_square_link', 'operator' => 'eq', 'value' => true],
                    ],
                    'groups' => [],
                ],
            ],
            [
                'name' => 'Shopify Buyers',
                'channel_scope' => 'any',
                'description' => 'Profiles with Shopify-linked records.',
                'rules_json' => [
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'has_shopify_link', 'operator' => 'eq', 'value' => true],
                    ],
                    'groups' => [],
                ],
            ],
        ];

        foreach ($segments as $segment) {
            DB::table('marketing_segments')->insert([
                'name' => $segment['name'],
                'slug' => Str::slug($segment['name']),
                'description' => $segment['description'],
                'status' => 'active',
                'channel_scope' => $segment['channel_scope'],
                'rules_json' => json_encode($segment['rules_json']),
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaign_conversions');
        Schema::dropIfExists('marketing_profile_scores');
        Schema::dropIfExists('marketing_send_approvals');
        Schema::dropIfExists('marketing_recommendations');
        Schema::dropIfExists('marketing_campaign_recipients');
        Schema::dropIfExists('marketing_campaign_variants');
        Schema::dropIfExists('marketing_message_templates');
        Schema::dropIfExists('marketing_campaigns');
        Schema::dropIfExists('marketing_segments');
    }
};
