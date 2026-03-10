<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingEventSourceMapping extends Model
{
    protected $fillable = [
        'source_system',
        'raw_value',
        'normalized_value',
        'event_instance_id',
        'confidence',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function eventInstance(): BelongsTo
    {
        return $this->belongsTo(EventInstance::class);
    }
}
