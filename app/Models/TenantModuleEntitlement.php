<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantModuleEntitlement extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'module_key',
        'availability_status',
        'enabled_status',
        'billing_status',
        'price_override_cents',
        'currency',
        'entitlement_source',
        'price_source',
        'starts_at',
        'ends_at',
        'notes',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'price_override_cents' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
