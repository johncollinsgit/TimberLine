<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('automation_workflows', 'definition_schema_version')) {
            Schema::table('automation_workflows', function (Blueprint $table): void {
                $table->unsignedSmallInteger('definition_schema_version')->default(1)->after('draft_definition');
            });
        }

        if (! Schema::hasColumn('automation_workflows', 'draft_revision')) {
            Schema::table('automation_workflows', function (Blueprint $table): void {
                $table->unsignedBigInteger('draft_revision')->default(1)->after('definition_schema_version');
            });
        }

        if (! Schema::hasColumn('automation_workflows', 'next_run_at')) {
            Schema::table('automation_workflows', function (Blueprint $table): void {
                $table->timestamp('next_run_at')->nullable()->after('last_run_at');
            });
        }

        if (! Schema::hasIndex('automation_workflows', 'aw_tenant_status_next_run_idx')) {
            Schema::table('automation_workflows', function (Blueprint $table): void {
                $table->index(
                    ['tenant_id', 'status', 'next_run_at'],
                    'aw_tenant_status_next_run_idx'
                );
            });
        }

        if (! Schema::hasTable('automation_workflow_run_items')) {
            Schema::create('automation_workflow_run_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('automation_workflow_id');
                $table->unsignedBigInteger('automation_workflow_run_id');
                $table->unsignedBigInteger('automation_workflow_version_id');
                $table->string('trigger_step_id', 100);
                $table->string('source_system', 100);
                $table->string('source_id', 191);
                $table->string('source_fingerprint', 128)->nullable();
                $table->string('event_key', 128);
                $table->string('status', 30)->default('pending');
                $table->longText('payload');
                $table->longText('context')->nullable();
                $table->longText('execution_stack')->nullable();
                $table->string('current_step_id', 100)->nullable();
                $table->timestamp('available_at')->nullable();
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->text('error_summary')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id', 'awri_tenant_fk')
                    ->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('automation_workflow_id', 'awri_workflow_fk')
                    ->references('id')->on('automation_workflows')->cascadeOnDelete();
                $table->foreign('automation_workflow_run_id', 'awri_run_fk')
                    ->references('id')->on('automation_workflow_runs')->cascadeOnDelete();
                $table->foreign('automation_workflow_version_id', 'awri_version_fk')
                    ->references('id')->on('automation_workflow_versions')->cascadeOnDelete();

                $table->unique(
                    ['automation_workflow_id', 'event_key'],
                    'awri_workflow_event_uq'
                );
                $table->index(
                    ['tenant_id', 'status', 'available_at'],
                    'awri_tenant_status_available_idx'
                );
                $table->index(
                    ['automation_workflow_run_id', 'status'],
                    'awri_run_status_idx'
                );
                $table->index(
                    ['automation_workflow_id', 'source_system', 'source_id'],
                    'awri_workflow_source_idx'
                );
            });
        }

        if (! Schema::hasColumn('automation_workflow_run_steps', 'automation_workflow_run_item_id')) {
            Schema::table('automation_workflow_run_steps', function (Blueprint $table): void {
                $table->unsignedBigInteger('automation_workflow_run_item_id')
                    ->nullable()
                    ->after('automation_workflow_run_id');
            });
        }

        if (! Schema::hasColumn('automation_workflow_run_steps', 'parent_step_id')) {
            Schema::table('automation_workflow_run_steps', function (Blueprint $table): void {
                $table->string('parent_step_id', 100)->nullable()->after('step_key');
            });
        }

        if (! Schema::hasColumn('automation_workflow_run_steps', 'branch_key')) {
            Schema::table('automation_workflow_run_steps', function (Blueprint $table): void {
                $table->string('branch_key', 100)->nullable()->after('parent_step_id');
            });
        }

        if (! Schema::hasColumn('automation_workflow_run_steps', 'attempt')) {
            Schema::table('automation_workflow_run_steps', function (Blueprint $table): void {
                $table->unsignedSmallInteger('attempt')->default(1)->after('branch_key');
            });
        }

        if (! Schema::hasColumn('automation_workflow_run_steps', 'idempotency_key')) {
            Schema::table('automation_workflow_run_steps', function (Blueprint $table): void {
                $table->string('idempotency_key', 128)->nullable()->after('attempt');
            });
        }

        if (! Schema::hasColumn('automation_workflow_run_steps', 'input_summary')) {
            Schema::table('automation_workflow_run_steps', function (Blueprint $table): void {
                $table->json('input_summary')->nullable()->after('summary');
            });
        }

        if (! Schema::hasColumn('automation_workflow_run_steps', 'output_summary')) {
            Schema::table('automation_workflow_run_steps', function (Blueprint $table): void {
                $table->json('output_summary')->nullable()->after('input_summary');
            });
        }

        if (! Schema::hasColumn('automation_workflow_run_steps', 'started_at')) {
            Schema::table('automation_workflow_run_steps', function (Blueprint $table): void {
                $table->timestamp('started_at')->nullable()->after('duration_ms');
            });
        }

        if (! Schema::hasColumn('automation_workflow_run_steps', 'finished_at')) {
            Schema::table('automation_workflow_run_steps', function (Blueprint $table): void {
                $table->timestamp('finished_at')->nullable()->after('started_at');
            });
        }

        if (! $this->hasForeignKey('automation_workflow_run_steps', ['automation_workflow_run_item_id'])) {
            Schema::table('automation_workflow_run_steps', function (Blueprint $table): void {
                $table->foreign(
                    'automation_workflow_run_item_id',
                    'awrs_run_item_fk'
                )->references('id')->on('automation_workflow_run_items')->nullOnDelete();
            });
        }

        if (! Schema::hasIndex('automation_workflow_run_steps', 'awrs_item_idempotency_uq')) {
            Schema::table('automation_workflow_run_steps', function (Blueprint $table): void {
                $table->unique(
                    ['automation_workflow_run_item_id', 'idempotency_key'],
                    'awrs_item_idempotency_uq'
                );
            });
        }

        if (! Schema::hasIndex('automation_workflow_run_steps', 'awrs_item_status_idx')) {
            Schema::table('automation_workflow_run_steps', function (Blueprint $table): void {
                $table->index(
                    ['automation_workflow_run_item_id', 'status'],
                    'awrs_item_status_idx'
                );
            });
        }

        $addedStepKey = ! Schema::hasColumn('automation_workflow_links', 'step_key');

        if ($addedStepKey) {
            Schema::table('automation_workflow_links', function (Blueprint $table): void {
                $table->string('step_key', 100)->default('action')->after('automation_workflow_id');
            });
        }

        DB::table('automation_workflow_links')
            ->whereNull('step_key')
            ->orWhere('step_key', '')
            ->update(['step_key' => 'action']);

        // MySQL may use the legacy unique index to support the
        // automation_workflow_id foreign key. Create the replacement first so
        // dropping the old index never leaves that foreign key uncovered.
        if (! Schema::hasIndex('automation_workflow_links', 'awl_workflow_step_source_uq')) {
            Schema::table('automation_workflow_links', function (Blueprint $table): void {
                $table->unique(
                    ['automation_workflow_id', 'step_key', 'source_system', 'source_id'],
                    'awl_workflow_step_source_uq'
                );
            });
        }

        if (Schema::hasIndex('automation_workflow_links', 'automation_links_workflow_source_unique')) {
            Schema::table('automation_workflow_links', function (Blueprint $table): void {
                $table->dropUnique('automation_links_workflow_source_unique');
            });
        }

        if (! Schema::hasTable('automation_workflow_domain_events')) {
            Schema::create('automation_workflow_domain_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('event_key', 128);
                $table->string('event_type', 120);
                $table->string('subject_type', 160);
                $table->string('subject_id', 191);
                $table->longText('payload');
                $table->timestamp('occurred_at');
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id', 'awde_tenant_fk')
                    ->references('id')->on('tenants')->cascadeOnDelete();
                $table->unique(['tenant_id', 'event_key'], 'awde_tenant_event_uq');
                $table->index(
                    ['tenant_id', 'consumed_at', 'event_type'],
                    'awde_tenant_consumed_type_idx'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_workflow_domain_events');

        if (! Schema::hasIndex('automation_workflow_links', 'automation_links_workflow_source_unique')) {
            Schema::table('automation_workflow_links', function (Blueprint $table): void {
                $table->unique(
                    ['automation_workflow_id', 'source_system', 'source_id'],
                    'automation_links_workflow_source_unique'
                );
            });
        }

        Schema::table('automation_workflow_links', function (Blueprint $table): void {
            $table->dropUnique('awl_workflow_step_source_uq');
            $table->dropColumn('step_key');
        });

        Schema::table('automation_workflow_run_steps', function (Blueprint $table): void {
            $table->dropUnique('awrs_item_idempotency_uq');
            $table->dropIndex('awrs_item_status_idx');
            $table->dropForeign('awrs_run_item_fk');
            $table->dropColumn([
                'automation_workflow_run_item_id',
                'parent_step_id',
                'branch_key',
                'attempt',
                'idempotency_key',
                'input_summary',
                'output_summary',
                'started_at',
                'finished_at',
            ]);
        });

        Schema::dropIfExists('automation_workflow_run_items');

        Schema::table('automation_workflows', function (Blueprint $table): void {
            $table->dropIndex('aw_tenant_status_next_run_idx');
            $table->dropColumn([
                'definition_schema_version',
                'draft_revision',
                'next_run_at',
            ]);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasForeignKey(string $table, array $columns): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }
};
