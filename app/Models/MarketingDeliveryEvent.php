<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingDeliveryEvent extends Model
{
    protected $fillable = [
        'marketing_message_delivery_id',
        'provider',
        'provider_message_id',
        'event_type',
        'event_status',
        'event_hash',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(MarketingMessageDelivery::class, 'marketing_message_delivery_id');
    }
}
