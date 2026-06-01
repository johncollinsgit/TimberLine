<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationWorkflowState extends Model
{
    protected $fillable = [
        'workflow_key',
        'status',
        'cursor',
        'context',
        'last_started_at',
        'last_finished_at',
        'last_status',
        'last_error',
        'last_result',
    ];

    protected $casts = [
        'context' => 'array',
        'last_result' => 'array',
        'last_started_at' => 'datetime',
        'last_finished_at' => 'datetime',
    ];
}
