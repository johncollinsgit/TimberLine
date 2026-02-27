<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventMapping extends Model
{
    protected $fillable = [
        'upcoming_event_id',
        'past_event_id',
        'created_by',
    ];

    public function upcomingEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'upcoming_event_id');
    }

    public function pastEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'past_event_id');
    }
}
