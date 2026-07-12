<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->text('external_account_secret')->nullable()->after('external_account_id');
        });

        DB::table('integration_connections')
            ->where('provider', 'quickbooks')
            ->orderBy('id')
            ->eachById(function (object $connection): void {
                $metadata = json_decode((string) ($connection->metadata ?? ''), true);
                $metadata = is_array($metadata) ? $metadata : [];
                $realmId = trim((string) ($metadata['realm_id'] ?? $connection->external_account_id ?? ''));

                if ($realmId === '') {
                    return;
                }

                unset($metadata['realm_id']);

                DB::table('integration_connections')
                    ->where('id', $connection->id)
                    ->update([
                        'external_account_id' => hash_hmac('sha256', $realmId, (string) config('app.key')),
                        'external_account_secret' => Crypt::encryptString($realmId),
                        'external_account_label' => 'QuickBooks company',
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    ]);
            });
    }

    public function down(): void
    {
        DB::table('integration_connections')
            ->where('provider', 'quickbooks')
            ->whereNotNull('external_account_secret')
            ->orderBy('id')
            ->eachById(function (object $connection): void {
                $realmId = Crypt::decryptString((string) $connection->external_account_secret);
                $metadata = json_decode((string) ($connection->metadata ?? ''), true);
                $metadata = is_array($metadata) ? $metadata : [];
                $metadata['realm_id'] = $realmId;

                DB::table('integration_connections')
                    ->where('id', $connection->id)
                    ->update([
                        'external_account_id' => $realmId,
                        'external_account_label' => 'QuickBooks company '.$realmId,
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    ]);
            });

        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropColumn('external_account_secret');
        });
    }
};
