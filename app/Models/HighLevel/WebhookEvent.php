<?php

namespace App\Models\HighLevel;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $table = 'highlevel_webhook_events';

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected $casts = ['payload' => 'encrypted:array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
}
