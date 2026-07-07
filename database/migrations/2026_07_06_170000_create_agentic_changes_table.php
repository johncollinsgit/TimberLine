<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide (landlord/operator) feed of autonomous ("agentic") changes to the
 * system. Intentionally NOT tenant-scoped: this is the operator's cross-platform
 * developer log, distinct from the tenant-scoped DevelopmentChangeLog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agentic_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('summary');
            $table->string('category')->default('platform');
            $table->string('status')->default('shipped');
            $table->string('impact')->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->index('changed_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agentic_changes');
    }
};
