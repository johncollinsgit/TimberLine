<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('store_key')->nullable();
            $table->string('source')->nullable();
            $table->boolean('is_dry_run')->default(false);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('lines_count')->default(0);
            $table->unsignedInteger('merged_lines_count')->default(0);
            $table->unsignedInteger('mapping_exceptions_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_import_runs');
    }
};
