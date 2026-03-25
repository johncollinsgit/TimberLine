<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAccessAddon extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'addon_key',
        'enabled',
        'source',
        'starts_at',
        'ends_at',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'enabled' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

