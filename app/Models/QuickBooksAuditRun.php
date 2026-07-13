<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class QuickBooksAuditRun extends Model
{
    use HasTenantScope;

    protected $table = 'quickbooks_audit_runs';

    protected $fillable = [
        'tenant_id',
        'integration_connection_id',
        'status',
        'dry_run',
        'summary',
        'errors',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'integration_connection_id' => 'integer',
        'dry_run' => 'boolean',
        'summary' => 'encrypted:array',
        'errors' => 'encrypted:array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
