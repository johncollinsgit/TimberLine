<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 190);
            $table->string('email', 190);
            $table->string('company', 190)->nullable();
            $table->string('website', 300)->nullable();
            $table->string('business_size', 80)->nullable();
            $table->string('current_tools', 1000)->nullable();
            $table->string('timeline', 80)->nullable();
            $table->string('budget_range', 80)->nullable();
            $table->text('pain_point')->nullable();
            $table->json('calculator_payload')->nullable();
            $table->string('source_page', 120)->nullable();
            $table->string('status', 80)->default('new');
            $table->timestamps();

            $table->index(['status', 'created_at'], 'service_inquiries_status_created_idx');
            $table->index('email', 'service_inquiries_email_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_inquiries');
    }
};
