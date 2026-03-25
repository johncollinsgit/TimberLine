<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantModuleState extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'module_key',
        'enabled_override',
        'setup_status',
        'setup_completed_at',
        'coming_soon_override',
        'upgrade_prompt_override',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'enabled_override' => 'boolean',
        'setup_completed_at' => 'datetime',
        'coming_soon_override' => 'boolean',
        'upgrade_prompt_override' => 'boolean',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

