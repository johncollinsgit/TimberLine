<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trajectory_medical_documents')) {
            Schema::create('trajectory_medical_documents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('space_id')->constrained('trajectory_spaces')->cascadeOnDelete();
                $table->foreignId('need_id')->nullable()->constrained('trajectory_records')->cascadeOnDelete();
                $table->unsignedBigInteger('uploaded_by');
                $table->char('sha256', 64);
                $table->unsignedInteger('byte_size');
                $table->text('metadata');
                $table->longText('contents');
                $table->timestamps();
                $table->unique(['space_id', 'need_id', 'sha256'], 'traj_medical_doc_unique');
            });
        }
    }

    public function down(): void
    {
        // Preserve household source documents when rolling back application code.
    }
};
