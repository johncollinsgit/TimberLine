<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUsageCounter extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'metric_key',
        'metric_value',
        'included_limit',
        'source',
        'last_recorded_at',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'metric_value' => 'integer',
        'included_limit' => 'integer',
        'last_recorded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
