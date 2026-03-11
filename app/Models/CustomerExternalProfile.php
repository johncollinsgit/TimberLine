<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerExternalProfile extends Model
{
    protected $fillable = [
        'marketing_profile_id',
        'provider',
        'integration',
        'store_key',
        'external_customer_id',
        'external_customer_gid',
        'email',
        'normalized_email',
        'raw_metafields',
        'points_balance',
        'vip_tier',
        'referral_link',
        'synced_at',
    ];

    protected $casts = [
        'raw_metafields' => 'array',
        'points_balance' => 'integer',
        'synced_at' => 'datetime',
    ];

    public function marketingProfile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class);
    }
}
