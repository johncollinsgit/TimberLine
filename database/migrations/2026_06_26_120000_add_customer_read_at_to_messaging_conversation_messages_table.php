<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messaging_conversation_messages', function (Blueprint $table): void {
            $table->timestamp('customer_read_at')->nullable()->after('operator_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('messaging_conversation_messages', function (Blueprint $table): void {
            $table->dropColumn('customer_read_at');
        });
    }
};
