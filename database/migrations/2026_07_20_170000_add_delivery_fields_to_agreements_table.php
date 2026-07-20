<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreements', function (Blueprint $table): void {
            $table->string('recipient_email')->nullable()->after('sent_at');
            $table->timestamp('email_sent_at')->nullable()->after('recipient_email');
            $table->string('recipient_phone')->nullable()->after('email_sent_at');
            $table->timestamp('sms_sent_at')->nullable()->after('recipient_phone');
        });
    }

    public function down(): void
    {
        Schema::table('agreements', function (Blueprint $table): void {
            $table->dropColumn(['recipient_email', 'email_sent_at', 'recipient_phone', 'sms_sent_at']);
        });
    }
};
