<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agreements')) {
            return;
        }

        Schema::table('agreements', function (Blueprint $table): void {
            if (! Schema::hasColumn('agreements', 'recipient_phone')) {
                $table->string('recipient_phone')->nullable();
            }

            if (! Schema::hasColumn('agreements', 'sms_sent_at')) {
                $table->timestamp('sms_sent_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // This is a production schema repair. The original delivery migration owns these columns.
    }
};
