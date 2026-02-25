<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'parse_confidence')) {
                $table->string('parse_confidence', 20)->nullable()->after('source_ref');
            }
            if (!Schema::hasColumn('events', 'parse_notes_json')) {
                $table->json('parse_notes_json')->nullable()->after('parse_confidence');
            }
            if (!Schema::hasColumn('events', 'needs_review')) {
                $table->boolean('needs_review')->default(false)->after('parse_notes_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            foreach (['needs_review', 'parse_notes_json', 'parse_confidence'] as $col) {
                if (Schema::hasColumn('events', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

