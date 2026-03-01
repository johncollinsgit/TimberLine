<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventBoxPlan extends Model
{
    protected $fillable = [
        'event_instance_id',
        'scent_raw',
        'box_count_sent',
        'box_count_returned',
        'line_notes',
        'is_split_box',
        'import_batch_id',
    ];

    protected $casts = [
        'box_count_sent' => 'decimal:2',
        'box_count_returned' => 'decimal:2',
        'is_split_box' => 'boolean',
    ];

    public function eventInstance(): BelongsTo
    {
        return $this->belongsTo(EventInstance::class);
    }
}
