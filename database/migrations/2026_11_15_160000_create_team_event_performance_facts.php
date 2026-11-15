<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('team_event_performance_facts')) {
            Schema::create('team_event_performance_facts', function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('tenant_id');
                $t->uuid('area_id');
                $t->uuid('generation');
                $t->string('kind', 16);
                $t->string('source_key', 64);
                $t->string('group_key', 64);
                $t->uuid('event_id');
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->date('local_date');
                $t->date('ends_on');
                $t->bigInteger('net_sales_minor')->nullable();
                $t->bigInteger('known_net_sales_minor')->default(0);
                $t->bigInteger('paid_total_minor')->nullable();
                $t->unsignedInteger('sale_count')->default(0);
                $t->unsignedInteger('selling_days')->default(0);
                $t->boolean('complete')->default(false);
                $t->json('search_tokens');
                $t->longText('payload');
            });
        }
        foreach ([
            'tepf_source' => ['tenant_id', 'generation', 'kind', 'source_key'],
            'tepf_date' => ['tenant_id', 'area_id', 'generation', 'kind', 'local_date'],
            'tepf_group' => ['tenant_id', 'generation', 'kind', 'group_key'],
            'tepf_event' => ['tenant_id', 'generation', 'event_id'],
            'tepf_customer' => ['tenant_id', 'generation', 'customer_id'],
        ] as $name => $columns) {
            if (! Schema::hasIndex('team_event_performance_facts', $name)) {
                Schema::table('team_event_performance_facts', function (Blueprint $t) use ($name, $columns): void {
                    $name === 'tepf_source' ? $t->unique($columns, $name) : $t->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        // Rebuildable reporting is retained for a non-destructive application rollback.
    }
};
