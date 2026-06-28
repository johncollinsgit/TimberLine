<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_profile_scent_quiz_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('marketing_profile_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('quiz_version', 32)->default('scent-v1');
            $table->json('axis_scores');
            $table->json('dominant_traits')->nullable();
            $table->string('headline')->nullable();
            $table->string('personality_title')->nullable();
            $table->text('personality_body')->nullable();
            $table->json('answers')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->unique('marketing_profile_id', 'mpsqr_profile_unique');
            $table->index(['tenant_id', 'completed_at'], 'mpsqr_tenant_completed_idx');
            $table->foreign('marketing_profile_id', 'mpsqr_profile_fk')
                ->references('id')
                ->on('marketing_profiles')
                ->cascadeOnDelete();
            $table->foreign('tenant_id', 'mpsqr_tenant_fk')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_profile_scent_quiz_results');
    }
};
