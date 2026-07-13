<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class QuickBooksSourceRecord extends Model
{
    use HasTenantScope;

    protected $table = 'quickbooks_source_records';

    protected $fillable = [
        'tenant_id',
        'integration_connection_id',
        'entity_type',
        'external_id',
        'payload',
        'source_updated_at',
        'observed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'integration_connection_id' => 'integer',
        'payload' => 'encrypted:array',
        'source_updated_at' => 'datetime',
        'observed_at' => 'datetime',
    ];
}
