<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_birthday_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_birthday_profiles', 'signup_source')) {
                $table->string('signup_source', 160)->nullable()->index()->after('source');
            }
            if (! Schema::hasColumn('customer_birthday_profiles', 'capture_date')) {
                $table->timestamp('capture_date')->nullable()->index()->after('signup_source');
            }
            if (! Schema::hasColumn('customer_birthday_profiles', 'email_subscribed')) {
                $table->boolean('email_subscribed')->nullable()->index()->after('capture_date');
            }
            if (! Schema::hasColumn('customer_birthday_profiles', 'sms_subscribed')) {
                $table->boolean('sms_subscribed')->nullable()->index()->after('email_subscribed');
            }
            if (! Schema::hasColumn('customer_birthday_profiles', 'unsubscribed')) {
                $table->boolean('unsubscribed')->nullable()->index()->after('sms_subscribed');
            }
            if (! Schema::hasColumn('customer_birthday_profiles', 'source_file')) {
                $table->string('source_file', 255)->nullable()->after('unsubscribed');
            }
        });

        Schema::table('birthday_reward_issuances', function (Blueprint $table): void {
            if (! Schema::hasColumn('birthday_reward_issuances', 'reward_name')) {
                $table->string('reward_name', 160)->nullable()->after('reward_type');
            }
            if (! Schema::hasColumn('birthday_reward_issuances', 'reward_value')) {
                $table->decimal('reward_value', 10, 2)->nullable()->after('points_awarded');
            }
            if (! Schema::hasColumn('birthday_reward_issuances', 'shopify_discount_id')) {
                $table->string('shopify_discount_id', 160)->nullable()->index()->after('reward_code');
            }
            if (! Schema::hasColumn('birthday_reward_issuances', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->index()->after('claimed_at');
            }
            if (! Schema::hasColumn('birthday_reward_issuances', 'redeemed_at')) {
                $table->timestamp('redeemed_at')->nullable()->index()->after('expires_at');
            }
            if (! Schema::hasColumn('birthday_reward_issuances', 'order_id')) {
                $table->unsignedBigInteger('order_id')->nullable()->index()->after('redeemed_at');
            }
            if (! Schema::hasColumn('birthday_reward_issuances', 'order_number')) {
                $table->string('order_number', 120)->nullable()->index()->after('redeemed_at');
            }
            if (! Schema::hasColumn('birthday_reward_issuances', 'order_total')) {
                $table->decimal('order_total', 10, 2)->nullable()->after('order_number');
            }
            if (! Schema::hasColumn('birthday_reward_issuances', 'attributed_revenue')) {
                $table->decimal('attributed_revenue', 10, 2)->nullable()->after('order_total');
            }
            if (! Schema::hasColumn('birthday_reward_issuances', 'campaign_type')) {
                $table->string('campaign_type', 60)->nullable()->index()->after('attributed_revenue');
            }
        });

        Schema::create('birthday_message_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_birthday_profile_id')
                ->constrained('customer_birthday_profiles')
                ->cascadeOnDelete();
            $table->foreignId('marketing_profile_id')
                ->constrained('marketing_profiles')
                ->cascadeOnDelete();
            $table->foreignId('birthday_reward_issuance_id')
                ->nullable()
                ->constrained('birthday_reward_issuances')
                ->nullOnDelete();
            $table->string('event_key', 190)->unique();
            $table->string('campaign_type', 60)->index();
            $table->string('channel', 20)->index();
            $table->string('provider', 60)->nullable()->index();
            $table->string('provider_message_id', 160)->nullable()->index();
            $table->string('status', 40)->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable()->index();
            $table->timestamp('opened_at')->nullable()->index();
            $table->timestamp('clicked_at')->nullable()->index();
            $table->timestamp('conversion_at')->nullable()->index();
            $table->string('utm_campaign', 120)->nullable()->index();
            $table->string('utm_source', 120)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_message_events');

        Schema::table('birthday_reward_issuances', function (Blueprint $table): void {
            foreach ([
                'campaign_type',
                'attributed_revenue',
                'order_total',
                'order_number',
                'order_id',
                'redeemed_at',
                'expires_at',
                'shopify_discount_id',
                'reward_value',
                'reward_name',
            ] as $column) {
                if (Schema::hasColumn('birthday_reward_issuances', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('customer_birthday_profiles', function (Blueprint $table): void {
            foreach ([
                'source_file',
                'unsubscribed',
                'sms_subscribed',
                'email_subscribed',
                'capture_date',
                'signup_source',
            ] as $column) {
                if (Schema::hasColumn('customer_birthday_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
