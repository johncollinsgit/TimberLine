<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaign_recipients', function (Blueprint $table): void {
            if (!Schema::hasColumn('marketing_campaign_recipients', 'send_attempt_count')) {
                $table->unsignedInteger('send_attempt_count')->default(0)->after('status');
            }
            if (!Schema::hasColumn('marketing_campaign_recipients', 'last_send_attempt_at')) {
                $table->timestamp('last_send_attempt_at')->nullable()->after('send_attempt_count');
            }
            if (!Schema::hasColumn('marketing_campaign_recipients', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('marketing_campaign_recipients', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('sent_at');
            }
            if (!Schema::hasColumn('marketing_campaign_recipients', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('delivered_at');
            }
        });

        Schema::create('marketing_message_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained('marketing_campaign_recipients')->nullOnDelete();
            $table->foreignId('marketing_profile_id')->constrained('marketing_profiles')->cascadeOnDelete();
            $table->string('channel')->index();
            $table->string('provider')->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('to_phone')->nullable();
            $table->string('from_identifier')->nullable();
            $table->foreignId('variant_id')->nullable()->constrained('marketing_campaign_variants')->nullOnDelete();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->text('rendered_message');
            $table->string('send_status')->default('queued')->index();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'provider_message_id'], 'mmd_provider_message_unique');
            $table->index(['campaign_id', 'send_status'], 'mmd_campaign_status_idx');
        });

        Schema::create('marketing_delivery_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_message_delivery_id')->nullable()->constrained('marketing_message_deliveries')->nullOnDelete();
            $table->string('provider')->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('event_type')->index();
            $table->string('event_status')->nullable()->index();
            $table->string('event_hash')->nullable()->unique();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_consent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_profile_id')->constrained('marketing_profiles')->cascadeOnDelete();
            $table->string('channel')->index();
            $table->string('event_type')->index();
            $table->string('source_type')->nullable()->index();
            $table->string('source_id')->nullable()->index();
            $table->json('details')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        $now = now();

        $segment = DB::table('marketing_segments')
            ->where('slug', 'email-consented-no-sms')
            ->first();
        if (!$segment) {
            $segmentId = DB::table('marketing_segments')->insertGetId([
                'name' => 'Email Consented / No SMS Consent',
                'slug' => 'email-consented-no-sms',
                'description' => 'Profiles with email consent but missing SMS consent, for consent-capture outreach.',
                'status' => 'active',
                'channel_scope' => 'sms',
                'rules_json' => json_encode([
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'has_email_consent', 'operator' => 'eq', 'value' => true],
                        ['field' => 'has_sms_consent', 'operator' => 'eq', 'value' => false],
                    ],
                    'groups' => [],
                ]),
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $segmentId = (int) $segment->id;
        }

        $templateId = (int) (DB::table('marketing_message_templates')
            ->where('name', 'SMS Consent Capture Starter')
            ->value('id') ?? 0);
        if ($templateId === 0) {
            $templateId = DB::table('marketing_message_templates')->insertGetId([
                'name' => 'SMS Consent Capture Starter',
                'channel' => 'sms',
                'objective' => 'consent_capture',
                'tone' => 'friendly',
                'template_text' => 'Hi {{first_name}}, want early access alerts and future rewards perks? Reply YES to opt into SMS updates.',
                'variables_json' => json_encode(['first_name']),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $campaign = DB::table('marketing_campaigns')->where('slug', 'sms-consent-capture-starter')->first();
        if (!$campaign) {
            $campaignId = DB::table('marketing_campaigns')->insertGetId([
                'name' => 'SMS Consent Capture Starter',
                'slug' => 'sms-consent-capture-starter',
                'description' => 'Starter campaign for inviting eligible customers to opt into SMS updates.',
                'status' => 'draft',
                'channel' => 'sms',
                'segment_id' => $segmentId,
                'objective' => 'consent_capture',
                'attribution_window_days' => 7,
                'send_window_json' => json_encode([
                    'start' => '10:00',
                    'end' => '18:00',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $campaignId = (int) $campaign->id;
        }

        $variantExists = DB::table('marketing_campaign_variants')
            ->where('campaign_id', $campaignId)
            ->where('name', 'Starter Consent Invite')
            ->exists();
        if (!$variantExists) {
            DB::table('marketing_campaign_variants')->insert([
                'campaign_id' => $campaignId,
                'template_id' => $templateId,
                'name' => 'Starter Consent Invite',
                'variant_key' => 'A',
                'message_text' => 'Hi {{first_name}}, want early access alerts and future rewards perks? Reply YES to opt into SMS updates.',
                'weight' => 100,
                'is_control' => true,
                'status' => 'active',
                'notes' => 'Consent-capture starter copy. Keep claims limited to currently available benefits.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('marketing_settings')->upsert(
            [[
                'key' => 'sms_consent_capture_enabled',
                'value' => json_encode(['enabled' => true]),
                'description' => 'Feature flag for consent-capture recommendation and campaign scaffolding.',
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['key'],
            ['value', 'description', 'updated_at']
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_consent_events');
        Schema::dropIfExists('marketing_delivery_events');
        Schema::dropIfExists('marketing_message_deliveries');

        Schema::table('marketing_campaign_recipients', function (Blueprint $table): void {
            foreach (['send_attempt_count', 'last_send_attempt_at', 'sent_at', 'delivered_at', 'failed_at'] as $column) {
                if (Schema::hasColumn('marketing_campaign_recipients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        DB::table('marketing_campaign_variants')
            ->where('name', 'Starter Consent Invite')
            ->delete();
        DB::table('marketing_campaigns')
            ->where('slug', 'sms-consent-capture-starter')
            ->delete();
        DB::table('marketing_message_templates')
            ->where('name', 'SMS Consent Capture Starter')
            ->delete();
        DB::table('marketing_segments')
            ->where('slug', 'email-consented-no-sms')
            ->delete();
        DB::table('marketing_settings')
            ->where('key', 'sms_consent_capture_enabled')
            ->delete();
    }
};
