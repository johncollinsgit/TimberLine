<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class QuickBooksSyncRun extends Model
{
    use HasTenantScope;

    protected $table = 'quickbooks_sync_runs';

    protected $fillable = [
        'tenant_id', 'integration_connection_id', 'mode', 'status', 'checkpoint_started_at',
        'checkpoint_finished_at', 'summary', 'errors', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'integration_connection_id' => 'integer',
        'summary' => 'encrypted:array',
        'errors' => 'encrypted:array',
        'checkpoint_started_at' => 'datetime',
        'checkpoint_finished_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
