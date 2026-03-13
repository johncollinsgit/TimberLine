<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingTimingInsight extends Model
{
    protected $fillable = [
        'channel',
        'objective',
        'segment_key',
        'event_context',
        'recommended_hour',
        'recommended_daypart',
        'confidence',
        'reasons_json',
    ];

    protected $casts = [
        'recommended_hour' => 'integer',
        'confidence' => 'decimal:2',
        'reasons_json' => 'array',
    ];
}

