<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\CarbonInterface;

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
        'shopify_store_key',
        'shopify_discount_node_id',
        'discount_sync_status',
        'discount_sync_error',
        'claim_window_starts_at',
        'claim_window_ends_at',
        'issued_at',
        'claimed_at',
        'activated_at',
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
        'shopify_store_key' => 'string',
        'shopify_discount_node_id' => 'string',
        'discount_sync_status' => 'string',
        'claim_window_starts_at' => 'datetime',
        'claim_window_ends_at' => 'datetime',
        'issued_at' => 'datetime',
        'claimed_at' => 'datetime',
        'activated_at' => 'datetime',
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

    public function resolvedActivationAt(): ?CarbonInterface
    {
        return $this->activated_at ?: $this->claimed_at;
    }

    public function resolvedDiscountSyncStatus(): string
    {
        $status = strtolower(trim((string) ($this->discount_sync_status ?? '')));
        if ($status !== '') {
            return $status;
        }

        if ($this->reward_type === 'points') {
            return 'not_applicable';
        }

        if ($this->shopify_discount_id || $this->shopify_discount_node_id) {
            return 'synced';
        }

        if (in_array((string) $this->status, ['expired', 'cancelled'], true)) {
            return 'not_applicable';
        }

        return 'pending';
    }

    public function isActivated(): bool
    {
        return in_array((string) $this->status, ['claimed', 'redeemed'], true);
    }

    public function isRedeemed(): bool
    {
        return (string) $this->status === 'redeemed';
    }

    public function isUsable(): bool
    {
        return (string) $this->status === 'claimed'
            && $this->reward_code !== null
            && $this->resolvedDiscountSyncStatus() === 'synced'
            && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return (string) $this->status === 'expired'
            || ($this->expires_at !== null && $this->expires_at->isPast() && ! $this->isRedeemed());
    }
}
