<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScentAlias extends Model
{
    protected $fillable = [
        'alias',
        'scent_id',
        'scope',
    ];

    public function scent(): BelongsTo
    {
        return $this->belongsTo(Scent::class);
    }

    public static function normalizeLabel(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim(mb_strtolower($value)));

        return is_string($value) ? $value : '';
    }
}
