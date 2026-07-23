<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class AccountingEventSourceImport extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'source_type', 'source_filename', 'sheet_name', 'checksum',
        'mapping_version', 'status', 'source_metadata', 'imported_by_user_id',
        'imported_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'source_metadata' => 'encrypted:array',
        'imported_by_user_id' => 'integer',
        'imported_at' => 'datetime',
    ];
}
