<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingOrderEventAttribution extends Model
{
    protected $fillable = [
        'source_type',
        'source_id',
        'event_instance_id',
        'attribution_method',
        'confidence',
        'meta',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'meta' => 'array',
    ];

    public function eventInstance(): BelongsTo
    {
        return $this->belongsTo(EventInstance::class);
    }
}
