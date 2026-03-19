<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('candle_cash_legacy_compatibility_usages')) {
            return;
        }

        Schema::create('candle_cash_legacy_compatibility_usages', function (Blueprint $table): void {
            $table->id();
            $table->string('path', 120);
            $table->string('operation', 40);
            $table->string('context', 160);
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['path', 'operation', 'context'], 'cc_legacy_compat_usage_unique');
            $table->index(['operation', 'last_seen_at'], 'cc_legacy_compat_usage_operation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candle_cash_legacy_compatibility_usages');
    }
};
