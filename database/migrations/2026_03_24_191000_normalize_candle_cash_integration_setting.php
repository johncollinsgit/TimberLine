<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('marketing_settings')
            ->where('key', 'candle_cash_integration_config')
            ->first(['id', 'value']);

        if (! $row) {
            return;
        }

        $config = $this->decodeSettingValue($row->value);
        $nested = $config[0] ?? $config['0'] ?? null;

        if (is_string($nested)) {
            $decodedNested = json_decode($nested, true);
            if (is_array($decodedNested)) {
                unset($config[0], $config['0']);
                $config = array_merge($decodedNested, $config);
            }
        }

        DB::table('marketing_settings')
            ->where('id', $row->id)
            ->update([
                'value' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Irreversible cleanup of malformed legacy payload shape.
    }

    /**
     * @return array<string|int,mixed>
     */
    protected function decodeSettingValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
};
