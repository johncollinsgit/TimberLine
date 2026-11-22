<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('studio_episode_deliveries')) {
            Schema::create('studio_episode_deliveries', function (Blueprint $table): void {
                $table->char('id', 64)->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->string('destination', 16);
                $table->string('status', 24);
                $table->longText('details');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserve publication receipts and resumable uploads on rollback.
    }
};
