<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('venue')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->date('due_date')->nullable();
            $table->date('ship_date')->nullable();
            $table->string('status')->default('planned');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('event_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('scent_id')->nullable()->constrained('scents')->nullOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('sizes')->nullOnDelete();
            $table->string('wick_type')->nullable();
            $table->unsignedInteger('planned_qty')->default(0);
            $table->unsignedInteger('sent_qty')->nullable();
            $table->unsignedInteger('returned_qty')->nullable();
            $table->unsignedInteger('sold_qty')->nullable();
            $table->timestamps();
        });

        Schema::create('market_pour_lists', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->timestamp('generated_at')->nullable();
            $table->unsignedBigInteger('generated_by_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('market_pour_list_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_pour_list_id')->constrained('market_pour_lists')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('market_pour_list_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_pour_list_id')->constrained('market_pour_lists')->cascadeOnDelete();
            $table->foreignId('scent_id')->nullable()->constrained('scents')->nullOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('sizes')->nullOnDelete();
            $table->string('wick_type')->nullable();
            $table->unsignedInteger('recommended_qty')->default(0);
            $table->unsignedInteger('edited_qty')->nullable();
            $table->json('reason_json')->nullable();
            $table->timestamps();
        });

        Schema::create('market_pour_list_event_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_pour_list_id')->constrained('market_pour_lists')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('scent_id')->nullable()->constrained('scents')->nullOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('sizes')->nullOnDelete();
            $table->string('wick_type')->nullable();
            $table->unsignedInteger('recommended_qty')->default(0);
            $table->unsignedInteger('edited_qty')->nullable();
            $table->timestamps();
        });

        Schema::create('pour_requests', function (Blueprint $table) {
            $table->id();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('status')->default('open');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pour_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pour_request_id')->constrained('pour_requests')->cascadeOnDelete();
            $table->foreignId('scent_id')->nullable()->constrained('scents')->nullOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('sizes')->nullOnDelete();
            $table->string('wick_type')->nullable();
            $table->unsignedInteger('qty')->default(0);
            $table->unsignedInteger('produced_qty')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pour_request_lines');
        Schema::dropIfExists('pour_requests');
        Schema::dropIfExists('market_pour_list_event_lines');
        Schema::dropIfExists('market_pour_list_lines');
        Schema::dropIfExists('market_pour_list_events');
        Schema::dropIfExists('market_pour_lists');
        Schema::dropIfExists('event_shipments');
        Schema::dropIfExists('events');
    }
};
