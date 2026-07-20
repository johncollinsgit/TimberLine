<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreements', function (Blueprint $table): void {
            $table->text('agreement_sms_message')->nullable()->after('sms_sent_at');
            $table->string('agreement_mms_image_url', 2048)->nullable()->after('agreement_sms_message');
        });
    }

    public function down(): void
    {
        Schema::table('agreements', function (Blueprint $table): void {
            $table->dropColumn(['agreement_sms_message', 'agreement_mms_image_url']);
        });
    }
};
