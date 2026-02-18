<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::create('order_lines', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->cascadeOnDelete();

    // Normalized fields
    $table->string('scent_name');
    $table->string('size_code'); // 16oz, 8oz, melt, room_spray, etc
    $table->unsignedInteger('quantity')->default(1);

    // Raw Shopify-ish data for later cleanup
    $table->string('raw_title')->nullable();
    $table->string('raw_variant')->nullable();

    // Pouring workflow per line
    $table->string('pour_status')->default('queued');
    // queued | laid_out | first_pour | second_pour | waiting_on_oil | brought_down

    // Cycle timing
    $table->timestamp('started_at')->nullable();
    $table->timestamp('brought_down_at')->nullable();

    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_lines');
    }
};
