<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BirthdayRewardIssuance extends Model
{
    protected $fillable = [
        'customer_birthday_profile_id',
        'marketing_profile_id',
        'cycle_year',
        'reward_type',
        'reward_name',
        'status',
        'points_awarded',
        'reward_value',
        'reward_code',
        'shopify_discount_id',
        'claim_window_starts_at',
        'claim_window_ends_at',
        'issued_at',
        'claimed_at',
        'expires_at',
        'redeemed_at',
        'order_id',
        'order_number',
        'order_total',
        'attributed_revenue',
        'campaign_type',
        'metadata',
    ];

    protected $casts = [
        'cycle_year' => 'integer',
        'points_awarded' => 'integer',
        'reward_value' => 'decimal:2',
        'claim_window_starts_at' => 'datetime',
        'claim_window_ends_at' => 'datetime',
        'issued_at' => 'datetime',
        'claimed_at' => 'datetime',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'order_total' => 'decimal:2',
        'attributed_revenue' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function birthdayProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerBirthdayProfile::class, 'customer_birthday_profile_id');
    }

    public function marketingProfile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function messageEvents(): HasMany
    {
        return $this->hasMany(BirthdayMessageEvent::class, 'birthday_reward_issuance_id');
    }
}
