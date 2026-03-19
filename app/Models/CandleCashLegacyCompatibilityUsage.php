<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandleCashLegacyCompatibilityUsage extends Model
{
    protected $fillable = [
        'path',
        'operation',
        'context',
        'hits',
        'first_seen_at',
        'last_seen_at',
        'meta',
    ];

    protected $casts = [
        'hits' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'meta' => 'array',
    ];
}
