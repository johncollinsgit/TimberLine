<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'market_id')) {
                $table->foreignId('market_id')->nullable()->after('id')->constrained('markets')->nullOnDelete();
            }
            if (!Schema::hasColumn('events', 'display_name')) {
                $table->string('display_name')->nullable()->after('name');
            }
            if (!Schema::hasColumn('events', 'source')) {
                $table->string('source')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('events', 'source_ref')) {
                $table->string('source_ref')->nullable()->after('source');
            }
            if (!Schema::hasColumn('events', 'year')) {
                $table->unsignedSmallInteger('year')->nullable()->after('market_id');
            }
        });

        Schema::table('events', function (Blueprint $table) {
            try {
                $table->index(['market_id', 'year']);
            } catch (\Throwable $e) {
                // ignore duplicate index in repeatable deployments
            }
            try {
                $table->index(['source', 'source_ref']);
            } catch (\Throwable $e) {
                // ignore duplicate index in repeatable deployments
            }
        });

        Schema::table('market_pour_lists', function (Blueprint $table) {
            if (!Schema::hasColumn('market_pour_lists', 'event_id')) {
                $table->foreignId('event_id')->nullable()->after('id')->constrained('events')->nullOnDelete();
            }
            if (!Schema::hasColumn('market_pour_lists', 'created_by_user_id')) {
                $table->unsignedBigInteger('created_by_user_id')->nullable()->after('generated_by_user_id');
            }
            if (!Schema::hasColumn('market_pour_lists', 'published_by_user_id')) {
                $table->unsignedBigInteger('published_by_user_id')->nullable()->after('created_by_user_id');
            }
            if (!Schema::hasColumn('market_pour_lists', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('generated_at');
            }
        });

        Schema::table('market_pour_lists', function (Blueprint $table) {
            try {
                $table->index(['event_id', 'status']);
            } catch (\Throwable $e) {
                // ignore duplicate index in repeatable deployments
            }
        });
    }

    public function down(): void
    {
        Schema::table('market_pour_lists', function (Blueprint $table) {
            foreach (['event_id', 'created_by_user_id', 'published_by_user_id', 'published_at'] as $col) {
                if (Schema::hasColumn('market_pour_lists', $col)) {
                    try {
                        $table->dropColumn($col);
                    } catch (\Throwable $e) {
                        // ignore
                    }
                }
            }
        });

        Schema::table('events', function (Blueprint $table) {
            foreach (['market_id', 'display_name', 'source', 'source_ref', 'year'] as $col) {
                if (Schema::hasColumn('events', $col)) {
                    try {
                        $table->dropColumn($col);
                    } catch (\Throwable $e) {
                        // ignore
                    }
                }
            }
        });
    }
};

