<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_branch_accounts')) {
            Schema::create('ai_branch_accounts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique('aib_account_tenant_uq');
                $table->boolean('enabled')->default(false);
                $table->unsignedInteger('budget_cents')->default(0);
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_branch_accounts');
    }
};
