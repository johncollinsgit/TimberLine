<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketEventSyncState extends Model
{
    protected $fillable = [
        'sync_key',
        'status',
        'weeks',
        'queued_by_user_id',
        'queued_at',
        'started_at',
        'finished_at',
        'last_sync_at',
        'last_sync_status',
        'last_http_status',
        'last_error',
        'last_result',
    ];

    protected $casts = [
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'last_result' => 'array',
    ];
}
