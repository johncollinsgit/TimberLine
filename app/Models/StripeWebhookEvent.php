<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeWebhookEvent extends Model
{
    protected $fillable = [
        'event_id',
        'event_type',
        'status',
        'livemode',
        'tenant_id',
        'checkout_session_id',
        'processed_at',
        'payload',
    ];

    protected $casts = [
        'livemode' => 'boolean',
        'tenant_id' => 'integer',
        'processed_at' => 'datetime',
        'payload' => 'array',
    ];
}

