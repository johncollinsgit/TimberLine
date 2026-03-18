<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerExternalProfile extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'marketing_profile_id',
        'provider',
        'integration',
        'store_key',
        'external_customer_id',
        'external_customer_gid',
        'first_name',
        'last_name',
        'full_name',
        'email',
        'normalized_email',
        'phone',
        'normalized_phone',
        'accepts_marketing',
        'order_count',
        'last_order_at',
        'last_activity_at',
        'source_channels',
        'raw_metafields',
        'points_balance',
        'vip_tier',
        'referral_link',
        'synced_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'raw_metafields' => 'array',
        'accepts_marketing' => 'boolean',
        'order_count' => 'integer',
        'last_order_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'source_channels' => 'array',
        'points_balance' => 'integer',
        'synced_at' => 'datetime',
    ];

    public function marketingProfile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
