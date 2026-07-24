<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->text('oauth_client_id')->nullable()->after('token_type');
            $table->text('oauth_client_secret')->nullable()->after('oauth_client_id');
        });
    }

    public function down(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropColumn(['oauth_client_id', 'oauth_client_secret']);
        });
    }
};
