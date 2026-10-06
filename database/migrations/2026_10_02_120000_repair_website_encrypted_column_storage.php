<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Website models encrypt these arrays. MySQL JSON columns reject the
     * resulting ciphertext, so use text storage and preserve any legacy JSON.
     * Each column is checked independently so a stopped MySQL DDL can resume.
     */
    public function up(): void
    {
        foreach ($this->columns() as $tableName => $columnNames) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($columnNames as $columnName) {
                if (! Schema::hasColumn($tableName, $columnName)) {
                    continue;
                }

                if (Schema::getColumnType($tableName, $columnName) === 'json') {
                    Schema::table($tableName, function (Blueprint $table) use ($tableName, $columnName): void {
                        $column = $table->longText($columnName);
                        if (! in_array($tableName.'.'.$columnName, ['website_fulfillment_locations.address', 'website_shipping_rate_quotes.destination'], true)) {
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
                            if (json_validate($value)) {
                                DB::table($tableName)->where('id', $row->id)
                                    ->update([$columnName => Crypt::encryptString($value)]);
                            } else {
                                // A previous attempt may already have encrypted this row.
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

    /** @return array<string, array<int, string>> */
    private function columns(): array
    {
        return [
            'website_orders' => ['customer_snapshot', 'shipping_address', 'billing_address', 'service_request'],
            'website_order_events' => ['data'],
            'website_fulfillment_locations' => ['address'],
            'website_shipping_rate_quotes' => ['destination'],
            'website_shipments' => ['destination'],
            'website_shipment_events' => ['payload'],
        ];
    }
};
