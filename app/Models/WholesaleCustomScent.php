<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleCustomScent extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_name',
        'custom_scent_name',
        'oil_1',
        'oil_2',
        'oil_3',
        'total_oils',
        'abbreviation',
        'canonical_scent_id',
        'notes',
        'top_level_recipe_json',
        'resolved_recipe_json',
        'active',
    ];

    protected $casts = [
        'total_oils' => 'integer',
        'top_level_recipe_json' => 'array',
        'resolved_recipe_json' => 'array',
        'active' => 'boolean',
    ];

    public function canonicalScent(): BelongsTo
    {
        return $this->belongsTo(Scent::class, 'canonical_scent_id');
    }

    public static function normalizeAccountName(?string $value): string
    {
        $clean = strtolower(trim((string) $value));
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        return trim($clean);
    }

    public static function normalizeScentName(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return Scent::normalizeName($value);
    }
}
