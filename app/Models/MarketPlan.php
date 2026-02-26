<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketPlan extends Model
{
    protected $fillable = [
        'event_title',
        'event_date',
        'normalized_title',
        'box_type',
        'scent',
        'box_count',
        'top_shelf_definition_json',
        'status',
    ];

    protected $casts = [
        'event_date' => 'date',
        'top_shelf_definition_json' => 'array',
        'box_count' => 'integer',
    ];
}
