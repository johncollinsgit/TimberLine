<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class QuickBooksReportingSnapshot extends Model
{
    use HasTenantScope;

    protected $table = 'quickbooks_reporting_snapshots';

    protected $fillable = [
        'tenant_id', 'integration_connection_id', 'range_key', 'period_start', 'period_end', 'metrics', 'observed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'integration_connection_id' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'metrics' => 'encrypted:array',
        'observed_at' => 'datetime',
    ];
}
