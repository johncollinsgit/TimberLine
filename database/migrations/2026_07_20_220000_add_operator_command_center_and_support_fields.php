<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_support_tickets', function (Blueprint $table): void {
            $table->string('source_type', 40)->default('account_help')->after('status');
            $table->string('dedupe_key', 120)->nullable()->unique()->after('source_type');
            $table->text('resolution_summary')->nullable()->after('status');
            $table->timestamp('resolved_at')->nullable()->after('last_activity_at');
            $table->json('metadata')->nullable()->after('resolved_at');
        });

        Schema::create('operator_recurring_costs', function (Blueprint $table): void {
            $table->id();
            $table->string('vendor', 80);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('USD');
            $table->string('cadence', 20)->default('monthly');
            $table->date('effective_on')->nullable();
            $table->string('source', 30)->default('manual');
            $table->string('receipt_reference')->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['active', 'vendor']);
        });

        Schema::create('operator_alert_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 120);
            $table->string('dedupe_key', 160)->unique();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('target_type', 80)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('destination', 80);
            $table->string('status', 30);
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_bud_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('disabled');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_bud_settings');
        Schema::dropIfExists('operator_alert_logs');
        Schema::dropIfExists('operator_recurring_costs');
        Schema::table('tenant_support_tickets', function (Blueprint $table): void {
            $table->dropColumn(['source_type', 'dedupe_key', 'resolution_summary', 'resolved_at', 'metadata']);
        });
    }
};
