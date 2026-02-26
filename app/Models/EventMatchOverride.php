<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventMatchOverride extends Model
{
    protected $fillable = [
        'upcoming_event_id',
        'candidate_event_id',
        'created_by_user_id',
    ];

    public function upcomingEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'upcoming_event_id');
    }

    public function candidateEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'candidate_event_id');
    }
}
