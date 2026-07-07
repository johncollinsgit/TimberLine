<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A candidate next step for the platform, shown on the operator vision board.
 * Platform-global (no tenant scope) — see the vision_ideas migration.
 */
class VisionIdea extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'pitch',
        'impact',
        'effort',
        'category',
        'source',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
