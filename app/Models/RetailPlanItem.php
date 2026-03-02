<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetailPlanItem extends Model
{
    use HasFactory;

    public const MARKET_DRAFT_SOURCES = [
        'market_box_draft',
        'market_box_manual',
        'market_box_event_prefill',
        'event_prefill',
        'market_top_shelf_template',
        'market_duration_template',
    ];

    public const MARKET_MERGEABLE_SOURCES = [
        'market_box_manual',
        'market_box_draft',
        'market_box_event_prefill',
        'event_prefill',
        'market_duration_template',
    ];

    public const TOP_SHELF_PRESETS = [
        'same_12' => '12 Same',
        'split_6_6' => '6 + 6',
        'split_4_4_4' => '4 + 4 + 4',
        'split_3_3_3_3' => '3 + 3 + 3 + 3',
        'different_12' => '12 Different',
    ];

    public const TOP_SHELF_SIZE_MODES = [
        '16oz' => '16oz',
        '8oz' => '8oz',
        'wax_melt' => 'Wax Melts',
    ];

    protected $fillable = [
        'retail_plan_id',
        'order_id',
        'order_line_id',
        'scent_id',
        'size_id',
        'sku',
        'quantity',
        'inventory_quantity',
        'source',
        'status',
        'upcoming_event_id',
        'box_tier',
        'notes',
    ];

    public function plan()
    {
        return $this->belongsTo(RetailPlan::class, 'retail_plan_id');
    }

    public function scent(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Scent::class, 'scent_id');
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Size::class, 'size_id');
    }

    /**
     * @return array<int,string>
     */
    public static function marketDraftSources(): array
    {
        return self::MARKET_DRAFT_SOURCES;
    }

    /**
     * @return array<int,string>
     */
    public static function marketMergeableSources(): array
    {
        return self::MARKET_MERGEABLE_SOURCES;
    }

    public static function isTopShelfLabel(?string $value): bool
    {
        return strtolower(trim((string) $value)) === 'top shelf';
    }

    /**
     * @return array<string,mixed>
     */
    public static function defaultTopShelfConfiguration(?int $fallbackScentId = null): array
    {
        return self::normalizeTopShelfConfiguration([], $fallbackScentId);
    }

    /**
     * @return array<string,mixed>
     */
    public static function decodeTopShelfConfiguration(?string $notes, ?int $fallbackScentId = null): array
    {
        $decoded = [];
        $notes = trim((string) $notes);

        if ($notes !== '') {
            try {
                $decoded = json_decode($notes, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = [];
            }
        }

        return self::normalizeTopShelfConfiguration(is_array($decoded) ? $decoded : [], $fallbackScentId);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function encodeTopShelfConfiguration(array $input, ?int $fallbackScentId = null): ?string
    {
        try {
            return json_encode(
                self::normalizeTopShelfConfiguration($input, $fallbackScentId),
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public static function normalizeTopShelfConfiguration(array $input, ?int $fallbackScentId = null): array
    {
        $preset = trim((string) ($input['preset'] ?? ''));
        if (! array_key_exists($preset, self::TOP_SHELF_PRESETS)) {
            $preset = 'same_12';
        }

        $sizeMode = trim((string) ($input['size_mode'] ?? ''));
        if (! array_key_exists($sizeMode, self::TOP_SHELF_SIZE_MODES)) {
            $sizeMode = '16oz';
        }

        $unitsPerBox = $sizeMode === 'wax_melt'
            ? max(1, min(36, (int) ($input['wax_melt_capacity'] ?? $input['capacity'] ?? 12)))
            : 12;

        $slotCount = self::topShelfSlotCount($preset, $unitsPerBox);
        $rawSlots = self::extractTopShelfSlots($input);
        if ($rawSlots === [] && $fallbackScentId && $fallbackScentId > 0) {
            $rawSlots = [$fallbackScentId];
        }

        $slots = [];
        for ($index = 0; $index < $slotCount; $index++) {
            $value = $rawSlots[$index] ?? null;
            $slots[] = is_numeric($value) && (int) $value > 0 ? (int) $value : null;
        }

        $shares = self::distributeTopShelfUnits($unitsPerBox, $slotCount);
        $composition = [];

        foreach ($shares as $index => $share) {
            $composition[] = [
                'slot' => $index + 1,
                'scent_id' => $slots[$index] ?? null,
                'units_per_box' => $share,
            ];
        }

        return [
            'type' => 'top_shelf_builder',
            'version' => 1,
            'preset' => $preset,
            'size_mode' => $sizeMode,
            'wax_melt_capacity' => $sizeMode === 'wax_melt' ? $unitsPerBox : 12,
            'units_per_box' => $unitsPerBox,
            'slots' => $slots,
            'composition' => $composition,
        ];
    }

    /**
     * @param  array<string,mixed>  $configuration
     */
    public static function topShelfConfigurationIsComplete(array $configuration): bool
    {
        $normalized = self::normalizeTopShelfConfiguration($configuration);
        $composition = (array) ($normalized['composition'] ?? []);

        if ($composition === [] || (int) ($normalized['units_per_box'] ?? 0) <= 0) {
            return false;
        }

        foreach ($composition as $slot) {
            if ((int) ($slot['scent_id'] ?? 0) <= 0 || (int) ($slot['units_per_box'] ?? 0) <= 0) {
                return false;
            }
        }

        return true;
    }

    public static function topShelfSlotCount(string $preset, int $unitsPerBox = 12): int
    {
        return match ($preset) {
            'split_6_6' => 2,
            'split_4_4_4' => 3,
            'split_3_3_3_3' => 4,
            'different_12' => max(1, min(12, $unitsPerBox)),
            default => 1,
        };
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<int,mixed>
     */
    protected static function extractTopShelfSlots(array $input): array
    {
        $slots = $input['slots'] ?? null;
        if (is_array($slots) && $slots !== []) {
            return array_values($slots);
        }

        $composition = $input['composition'] ?? null;
        if (! is_array($composition)) {
            return [];
        }

        return array_values(array_map(
            fn ($slot) => is_array($slot) ? ($slot['scent_id'] ?? null) : null,
            $composition
        ));
    }

    /**
     * @return array<int,int>
     */
    protected static function distributeTopShelfUnits(int $unitsPerBox, int $slotCount): array
    {
        $unitsPerBox = max(1, $unitsPerBox);
        $slotCount = max(1, $slotCount);
        $base = intdiv($unitsPerBox, $slotCount);
        $remainder = $unitsPerBox % $slotCount;

        $shares = [];
        for ($index = 0; $index < $slotCount; $index++) {
            $shares[] = $base + ($index < $remainder ? 1 : 0);
        }

        return $shares;
    }
}
