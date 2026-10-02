<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fundraiser models encrypt these arrays. MySQL JSON columns cannot hold
     * ciphertext. Convert each column independently so a partial DDL can resume.
     */
    public function up(): void
    {
        foreach ($this->columns() as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($columns as $columnName) {
                if (! Schema::hasColumn($tableName, $columnName)) {
                    continue;
                }

                if (Schema::getColumnType($tableName, $columnName) !== 'longtext') {
                    Schema::table($tableName, function (Blueprint $table) use ($columnName): void {
                        $column = $table->longText($columnName);
                        if (in_array($columnName, ['recipient_email', 'recipient_phone', 'source_payload'], true)) {
                            $column->nullable();
                        }
                        $column->change();
                    });
                }

                DB::table($tableName)->select('id', $columnName)
                    ->whereNotNull($columnName)->orderBy('id')
                    ->chunkById(100, function ($rows) use ($tableName, $columnName): void {
                        foreach ($rows as $row) {
                            $value = (string) $row->{$columnName};
                            if (json_validate($value) || in_array($columnName, ['recipient_name', 'recipient_email', 'recipient_phone'], true)) {
                                if (in_array($columnName, ['recipient_name', 'recipient_email', 'recipient_phone'], true)) {
                                    try {
                                        Crypt::decryptString($value);

                                        continue;
                                    } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                                        $decoded = json_decode((string) base64_decode($value, true), true);
                                        if (is_array($decoded) && isset($decoded['iv'], $decoded['value'], $decoded['mac'])) {
                                            throw new \RuntimeException('An encrypted fundraiser field could not be decrypted with the active key.');
                                        }
                                    }
                                }
                                DB::table($tableName)->where('id', $row->id)
                                    ->update([$columnName => Crypt::encryptString($value)]);
                            } else {
                                Crypt::decryptString($value);
                            }
                        }
                    });
            }
        }
    }

    public function down(): void
    {
        // Ciphertext cannot be written back into MySQL JSON columns.
    }

    /** @return array<string,list<string>> */
    private function columns(): array
    {
        return [
            'modern_forestry_fundraiser_orders' => ['recipient_name', 'recipient_email', 'recipient_phone', 'shipping_address', 'line_items', 'source_payload'],
            'modern_forestry_fundraiser_invoice_packages' => ['invoice_lines'],
        ];
    }
};
