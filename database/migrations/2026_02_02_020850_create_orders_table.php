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
       Schema::create('orders', function (Blueprint $table) {
    $table->id();

    // Source + identifiers
    $table->string('source')->default('manual'); // retail|wholesale|manual
    $table->string('shopify_store')->nullable(); // retail|wholesale later
    $table->string('shopify_order_id')->nullable()->index();
    $table->string('order_number')->nullable();  // "#1234" or internal

    // Container info (event / wholesale account / customer)
    $table->string('container_name')->nullable(); // "Market: Frosty Farmer"
    $table->string('customer_name')->nullable();

    // Dates
    $table->date('ordered_at')->nullable();
    $table->date('due_date')->nullable();

    // Workflow status
    $table->string('status')->default('new');
    // new -> reviewed -> submitted_to_pouring -> pouring -> brought_down -> verified -> complete

    $table->text('internal_notes')->nullable();

    $table->timestamps();
});
    }

    

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
