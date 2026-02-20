<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Scent extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'oil_reference_name',
        'abbreviation',
        'is_blend',
        'oil_blend_id',
        'blend_oil_count',
        'is_wholesale_custom',
        'is_candle_club',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_blend' => 'boolean',
        'blend_oil_count' => 'integer',
        'is_wholesale_custom' => 'boolean',
        'is_candle_club' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function oilBlend(): BelongsTo
    {
        return $this->belongsTo(Blend::class, 'oil_blend_id');
    }

    public static function normalizeName(string $name): string
    {
        $clean = strtolower(trim($name));
        $clean = preg_replace('/\bwholesale\b/i', '', $clean);
        $clean = str_replace('&', 'and', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean);
        return trim($clean);
    }
}
