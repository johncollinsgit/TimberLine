<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('studio_briefs')) {
            Schema::create('studio_briefs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('studio_offer_id');
                $table->uuid('request_id');
                $table->string('status', 24)->default('submitted');
                $table->string('sponsor');
                $table->string('email');
                $table->text('brief');
                $table->boolean('social_clip')->default(false);
                $table->unsignedBigInteger('website_order_id')->nullable();
                $table->json('proof')->nullable();
                $table->timestamps();
            });
        }
        foreach (['request_id', 'website_order_id'] as $column) {
            $name = 'studio_briefs_'.$column.'_unique';
            if (! Schema::hasIndex('studio_briefs', $name)) {
                Schema::table('studio_briefs', fn (Blueprint $table) => $table->unique($column, $name));
            }
        }
        if (! Schema::hasIndex('studio_briefs', 'studio_briefs_offer')) {
            Schema::table('studio_briefs', fn (Blueprint $table) => $table->index('studio_offer_id', 'studio_briefs_offer'));
        }
    }

    public function down(): void
    {
        // Preserve approved briefs and invoices through application rollback.
    }
};
