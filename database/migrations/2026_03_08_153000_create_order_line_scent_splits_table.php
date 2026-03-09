<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_line_scent_splits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_line_id');
            $table->unsignedBigInteger('mapping_exception_id')->nullable();
            $table->unsignedBigInteger('scent_id')->nullable();
            $table->string('raw_scent_name')->nullable();
            $table->integer('quantity')->default(1);
            $table->string('allocation_type', 40)->default('manual_split');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('order_line_id', 'olss_order_line_idx');
            $table->index('mapping_exception_id', 'olss_exception_idx');
            $table->index('scent_id', 'olss_scent_idx');

            $table->foreign('order_line_id', 'olss_order_line_fk')
                ->references('id')
                ->on('order_lines')
                ->cascadeOnDelete();
            $table->foreign('mapping_exception_id', 'olss_exception_fk')
                ->references('id')
                ->on('mapping_exceptions')
                ->nullOnDelete();
            $table->foreign('scent_id', 'olss_scent_fk')
                ->references('id')
                ->on('scents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_line_scent_splits');
    }
};

