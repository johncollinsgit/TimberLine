<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAccessProfile extends Model
{
    protected $fillable = [
        'tenant_id',
        'plan_key',
        'operating_mode',
        'source',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

