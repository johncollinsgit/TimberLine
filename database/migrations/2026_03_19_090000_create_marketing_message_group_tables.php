<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_message_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('channel')->default('sms')->index();
            $table->boolean('is_reusable')->default(true)->index();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('marketing_message_group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_message_group_id')
                ->constrained('marketing_message_groups')
                ->cascadeOnDelete();
            $table->foreignId('marketing_profile_id')
                ->nullable()
                ->constrained('marketing_profiles')
                ->nullOnDelete();
            $table->string('source_type')->default('profile')->index();
            $table->string('full_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('normalized_phone')->nullable()->index();
            $table->timestamps();

            $table->index(
                ['marketing_message_group_id', 'normalized_phone'],
                'mmgm_group_phone_idx'
            );
            $table->index(
                ['marketing_message_group_id', 'marketing_profile_id'],
                'mmgm_group_profile_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_message_group_members');
        Schema::dropIfExists('marketing_message_groups');
    }
};
