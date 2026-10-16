<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_conversation_controls')) {
            Schema::create('marketing_conversation_controls', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index('md_10_tenant_idx');
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('policy_id')->nullable();
                $table->string('mode', 24)->default('human');
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedBigInteger('last_message_id')->nullable();
                $table->string('reason', 100)->nullable();
                $table->unique(['tenant_id', 'conversation_id'], 'md_conversation_control_unique');
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('marketing_conversation_controls', 'md_10_tenant_idx')) {
            Schema::table('marketing_conversation_controls', fn (Blueprint $table) => $table->index('tenant_id', 'md_10_tenant_idx'));
        }
        if (! Schema::hasIndex('marketing_conversation_controls', 'md_conversation_control_unique')) {
            Schema::table('marketing_conversation_controls', fn (Blueprint $table) => $table->unique(['tenant_id', 'conversation_id'], 'md_conversation_control_unique'));
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_conversation_controls');
    }
};
