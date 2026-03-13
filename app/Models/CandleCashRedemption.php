<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandleCashRedemption extends Model
{
    protected $fillable = [
        'marketing_profile_id',
        'reward_id',
        'points_spent',
        'platform',
        'redemption_code',
        'status',
        'issued_at',
        'expires_at',
        'redeemed_at',
        'canceled_at',
        'redeemed_channel',
        'external_order_source',
        'external_order_id',
        'redemption_context',
        'reconciliation_notes',
        'redeemed_by',
    ];

    protected $casts = [
        'points_spent' => 'integer',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'redemption_context' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(CandleCashReward::class, 'reward_id');
    }

    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by');
    }

    public function storefrontEvents(): HasMany
    {
        return $this->hasMany(MarketingStorefrontEvent::class, 'candle_cash_redemption_id');
    }
}
