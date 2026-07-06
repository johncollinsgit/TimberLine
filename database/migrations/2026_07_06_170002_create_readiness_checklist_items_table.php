<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production-readiness checklist ("what grown-up software has") shown on the
 * landlord Developer Control Center. Landlord-global, not tenant-scoped.
 * status: done | partial | todo
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('readiness_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('category')->default('platform');
            $table->string('status')->default('todo');
            $table->text('detail')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('category');
            $table->index('status');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('readiness_checklist_items');
    }
};
