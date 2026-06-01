<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationWorkflowLink extends Model
{
    protected $fillable = [
        'workflow_key',
        'source_system',
        'source_id',
        'destination_system',
        'destination_id',
        'source_fingerprint',
        'metadata',
        'last_synced_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_synced_at' => 'datetime',
    ];
}
