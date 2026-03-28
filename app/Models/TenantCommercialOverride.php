<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCommercialOverride extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'template_key',
        'store_channel_allowance',
        'plan_pricing_overrides',
        'addon_pricing_overrides',
        'included_usage_overrides',
        'display_labels',
        'billing_mapping',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'store_channel_allowance' => 'integer',
        'plan_pricing_overrides' => 'array',
        'addon_pricing_overrides' => 'array',
        'included_usage_overrides' => 'array',
        'display_labels' => 'array',
        'billing_mapping' => 'array',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
