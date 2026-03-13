<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingRecommendationRun extends Model
{
    protected $fillable = [
        'type',
        'status',
        'summary',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}

