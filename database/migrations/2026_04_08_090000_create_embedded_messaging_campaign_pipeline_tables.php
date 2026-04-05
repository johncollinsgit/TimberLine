<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createTemplateDefinitionsTable();
        $this->createTemplateInstancesTable();
        $this->createMessageJobsTable();
        $this->extendMarketingCampaignsTable();
        $this->seedTemplateDefinitions();
    }

    public function down(): void
    {
        $this->dropCampaignExtensions();
        Schema::dropIfExists('marketing_message_jobs');
        Schema::dropIfExists('marketing_template_instances');
        Schema::dropIfExists('marketing_template_definitions');
    }

    protected function createTemplateDefinitionsTable(): void
    {
        if (Schema::hasTable('marketing_template_definitions')) {
            return;
        }

        Schema::create('marketing_template_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('template_key', 80)->unique();
            $table->string('channel', 20)->index();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->text('thumbnail_svg')->nullable();
            $table->string('default_subject', 200)->nullable();
            $table->json('default_sections')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    protected function createTemplateInstancesTable(): void
    {
        if (Schema::hasTable('marketing_template_instances')) {
            return;
        }

        Schema::create('marketing_template_instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_definition_id')->nullable()
                ->constrained('marketing_template_definitions')
                ->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()
                ->constrained('marketing_campaigns')
                ->nullOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('store_key', 80)->nullable()->index();
            $table->string('channel', 20)->index();
            $table->string('name', 120)->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('body')->nullable();
            $table->json('sections')->nullable();
            $table->longText('advanced_html')->nullable();
            $table->longText('rendered_html')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            if (Schema::hasTable('tenants')) {
                $table->foreign('tenant_id', 'mti_tenant_fk')
                    ->references('id')
                    ->on('tenants')
                    ->nullOnDelete();
            }
        });
    }

    protected function createMessageJobsTable(): void
    {
        if (Schema::hasTable('marketing_message_jobs')) {
            return;
        }

        Schema::create('marketing_message_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained('marketing_campaign_recipients')->nullOnDelete();
            $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('store_key', 80)->nullable()->index();
            $table->string('channel', 20)->index();
            $table->string('job_type', 40)->default('send')->index();
            $table->string('status', 40)->default('queued')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->unsignedTinyInteger('priority')->default(5)->index();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->foreignId('delivery_id')->nullable()->constrained('marketing_message_deliveries')->nullOnDelete();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('last_error_code', 80)->nullable();
            $table->text('last_error_message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            if (Schema::hasTable('tenants')) {
                $table->foreign('tenant_id', 'mmj_tenant_fk')
                    ->references('id')
                    ->on('tenants')
                    ->nullOnDelete();
            }

            $table->index(['campaign_id', 'status', 'available_at'], 'mmj_campaign_status_available_idx');
        });
    }

    protected function extendMarketingCampaignsTable(): void
    {
        if (! Schema::hasTable('marketing_campaigns')) {
            return;
        }

        Schema::table('marketing_campaigns', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_campaigns', 'source_label')) {
                $table->string('source_label', 120)->nullable()->after('channel');
                $table->index('source_label', 'marketing_campaigns_source_label_idx');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'store_key')) {
                $table->string('store_key', 80)->nullable()->after('tenant_id');
                $table->index('store_key', 'marketing_campaigns_store_key_idx');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'message_subject')) {
                $table->string('message_subject', 200)->nullable()->after('description');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'message_body')) {
                $table->text('message_body')->nullable()->after('message_subject');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'message_html')) {
                $table->longText('message_html')->nullable()->after('message_body');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'target_snapshot')) {
                $table->json('target_snapshot')->nullable()->after('message_html');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'status_counts')) {
                $table->json('status_counts')->nullable()->after('target_snapshot');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'queued_at')) {
                $table->timestamp('queued_at')->nullable()->after('launched_at');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'scheduled_for')) {
                $table->timestamp('scheduled_for')->nullable()->after('queued_at');
                $table->index('scheduled_for', 'marketing_campaigns_scheduled_for_idx');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'test_sent_at')) {
                $table->timestamp('test_sent_at')->nullable()->after('scheduled_for');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'template_instance_id')) {
                $table->unsignedBigInteger('template_instance_id')->nullable()->after('test_sent_at');
                $table->index('template_instance_id', 'marketing_campaigns_template_instance_idx');
            }
        });

        if (Schema::hasTable('marketing_template_instances')) {
            try {
                Schema::table('marketing_campaigns', function (Blueprint $table): void {
                    $table->foreign('template_instance_id', 'marketing_campaigns_template_instance_fk')
                        ->references('id')
                        ->on('marketing_template_instances')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
                // no-op when the foreign key is already present.
            }
        }
    }

    protected function seedTemplateDefinitions(): void
    {
        if (! Schema::hasTable('marketing_template_definitions')) {
            return;
        }

        $now = now();

        $templates = [
            [
                'template_key' => 'announcement',
                'channel' => 'email',
                'name' => 'Announcement',
                'description' => 'Clean launch/update announcement layout.',
                'default_subject' => 'A quick update from Forestry Backstage',
                'default_sections' => [
                    ['id' => 'heading_1', 'type' => 'heading', 'text' => 'Big news', 'align' => 'left'],
                    ['id' => 'body_1', 'type' => 'text', 'html' => 'Share your update in two or three concise paragraphs.'],
                    ['id' => 'button_1', 'type' => 'button', 'label' => 'Read more', 'href' => '', 'align' => 'left'],
                ],
            ],
            [
                'template_key' => 'product_spotlight',
                'channel' => 'email',
                'name' => 'Product spotlight',
                'description' => 'Single product feature with focused CTA.',
                'default_subject' => 'Product spotlight',
                'default_sections' => [
                    ['id' => 'heading_1', 'type' => 'heading', 'text' => 'Featured right now', 'align' => 'left'],
                    ['id' => 'product_1', 'type' => 'product', 'productId' => '', 'title' => '', 'imageUrl' => '', 'price' => '', 'href' => '', 'buttonLabel' => 'Shop now'],
                    ['id' => 'body_1', 'type' => 'text', 'html' => 'Add one supporting paragraph with key details.'],
                ],
            ],
            [
                'template_key' => 'event_update',
                'channel' => 'email',
                'name' => 'Event/update',
                'description' => 'Simple event or operations update.',
                'default_subject' => 'Upcoming event update',
                'default_sections' => [
                    ['id' => 'heading_1', 'type' => 'heading', 'text' => 'Upcoming event', 'align' => 'left'],
                    ['id' => 'body_1', 'type' => 'text', 'html' => 'Share the key details: when, where, and what to expect.'],
                    ['id' => 'button_1', 'type' => 'button', 'label' => 'View details', 'href' => '', 'align' => 'left'],
                ],
            ],
            [
                'template_key' => 'photo_cta',
                'channel' => 'email',
                'name' => 'Photo + CTA',
                'description' => 'Image-first message with a focused next step.',
                'default_subject' => 'See what is new',
                'default_sections' => [
                    ['id' => 'image_1', 'type' => 'image', 'imageUrl' => '', 'alt' => 'Feature image', 'href' => '', 'padding' => '0 0 12px 0'],
                    ['id' => 'heading_1', 'type' => 'heading', 'text' => 'A quick look', 'align' => 'left'],
                    ['id' => 'button_1', 'type' => 'button', 'label' => 'Open', 'href' => '', 'align' => 'left'],
                ],
            ],
            [
                'template_key' => 'minimal_plain',
                'channel' => 'email',
                'name' => 'Minimal / plain',
                'description' => 'Low-friction plain style note.',
                'default_subject' => 'Quick note',
                'default_sections' => [
                    ['id' => 'body_1', 'type' => 'text', 'html' => 'Keep this short and conversational.'],
                ],
            ],
        ];

        foreach ($templates as $template) {
            DB::table('marketing_template_definitions')->updateOrInsert(
                ['template_key' => $template['template_key']],
                [
                    'channel' => $template['channel'],
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'default_subject' => $template['default_subject'],
                    'default_sections' => json_encode($template['default_sections']),
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    protected function dropCampaignExtensions(): void
    {
        if (! Schema::hasTable('marketing_campaigns')) {
            return;
        }

        try {
            Schema::table('marketing_campaigns', function (Blueprint $table): void {
                $table->dropForeign('marketing_campaigns_template_instance_fk');
            });
        } catch (\Throwable) {
            // no-op when foreign key does not exist.
        }

        Schema::table('marketing_campaigns', function (Blueprint $table): void {
            if (Schema::hasColumn('marketing_campaigns', 'template_instance_id')) {
                $table->dropIndex('marketing_campaigns_template_instance_idx');
                $table->dropColumn('template_instance_id');
            }

            if (Schema::hasColumn('marketing_campaigns', 'test_sent_at')) {
                $table->dropColumn('test_sent_at');
            }

            if (Schema::hasColumn('marketing_campaigns', 'scheduled_for')) {
                $table->dropIndex('marketing_campaigns_scheduled_for_idx');
                $table->dropColumn('scheduled_for');
            }

            if (Schema::hasColumn('marketing_campaigns', 'queued_at')) {
                $table->dropColumn('queued_at');
            }

            if (Schema::hasColumn('marketing_campaigns', 'status_counts')) {
                $table->dropColumn('status_counts');
            }

            if (Schema::hasColumn('marketing_campaigns', 'target_snapshot')) {
                $table->dropColumn('target_snapshot');
            }

            if (Schema::hasColumn('marketing_campaigns', 'message_html')) {
                $table->dropColumn('message_html');
            }

            if (Schema::hasColumn('marketing_campaigns', 'message_body')) {
                $table->dropColumn('message_body');
            }

            if (Schema::hasColumn('marketing_campaigns', 'message_subject')) {
                $table->dropColumn('message_subject');
            }

            if (Schema::hasColumn('marketing_campaigns', 'store_key')) {
                $table->dropIndex('marketing_campaigns_store_key_idx');
                $table->dropColumn('store_key');
            }

            if (Schema::hasColumn('marketing_campaigns', 'source_label')) {
                $table->dropIndex('marketing_campaigns_source_label_idx');
                $table->dropColumn('source_label');
            }
        });
    }
};
