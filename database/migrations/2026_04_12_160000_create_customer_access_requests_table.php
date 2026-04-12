<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_access_requests')) {
            return;
        }

        Schema::create('customer_access_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('intent', 40)->default('production');
            $table->string('status', 40)->default('pending');
            $table->string('name', 190);
            $table->string('email', 190)->index();
            $table->string('company', 190)->nullable();
            $table->string('requested_tenant_slug', 120)->nullable()->index();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_access_requests');
    }
};

