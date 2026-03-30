<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingOrderEventAttribution extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'source_type',
        'source_id',
        'event_instance_id',
        'attribution_method',
        'confidence',
        'meta',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'confidence' => 'decimal:2',
        'meta' => 'array',
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
