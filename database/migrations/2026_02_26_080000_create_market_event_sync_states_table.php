<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_event_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('sync_key')->unique();
            $table->string('status')->default('idle'); // idle|queued|running|success|failed|skipped
            $table->unsignedInteger('weeks')->default(4);
            $table->unsignedBigInteger('queued_by_user_id')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status')->nullable();
            $table->unsignedInteger('last_http_status')->nullable();
            $table->text('last_error')->nullable();
            $table->json('last_result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_event_sync_states');
    }
};
