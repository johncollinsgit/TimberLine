<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingEventSourceMapping extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'source_system',
        'raw_value',
        'normalized_value',
        'event_instance_id',
        'confidence',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'confidence' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function eventInstance(): BelongsTo
    {
        return $this->belongsTo(EventInstance::class);
    }
}
