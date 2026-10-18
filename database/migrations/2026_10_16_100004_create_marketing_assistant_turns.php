<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_assistant_turns')) {
            Schema::create('marketing_assistant_turns', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index('md_5_tenant_idx');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('request_uuid', 36);
                $table->string('thread_uuid', 36);
                $table->string('purpose', 24);
                $table->string('status', 24)->default('reserved');
                $table->longText('input');
                $table->longText('result')->nullable();
                $table->unsignedBigInteger('usage_event_id')->nullable();
                $table->string('context_hash', 64);
                $table->unique(['tenant_id', 'request_uuid'], 'md_assistant_request_unique');
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('marketing_assistant_turns', 'md_5_tenant_idx')) {
            Schema::table('marketing_assistant_turns', fn (Blueprint $table) => $table->index('tenant_id', 'md_5_tenant_idx'));
        }
        if (! Schema::hasIndex('marketing_assistant_turns', 'md_assistant_request_unique')) {
            Schema::table('marketing_assistant_turns', fn (Blueprint $table) => $table->unique(['tenant_id', 'request_uuid'], 'md_assistant_request_unique'));
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_assistant_turns');
    }
};
