<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandleCashReferral extends Model
{
    protected $fillable = [
        'referrer_marketing_profile_id',
        'referred_marketing_profile_id',
        'referral_code',
        'referred_identity_key',
        'referred_email',
        'normalized_email',
        'referred_phone',
        'normalized_phone',
        'status',
        'qualifying_order_source',
        'qualifying_order_id',
        'qualifying_order_number',
        'qualifying_order_total',
        'referrer_completion_id',
        'referred_completion_id',
        'referrer_transaction_id',
        'referred_transaction_id',
        'referrer_reward_status',
        'referred_reward_status',
        'first_seen_at',
        'qualified_at',
        'rewarded_at',
        'metadata',
    ];

    protected $casts = [
        'qualifying_order_total' => 'decimal:2',
        'first_seen_at' => 'datetime',
        'qualified_at' => 'datetime',
        'rewarded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'referrer_marketing_profile_id');
    }

    public function referredProfile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'referred_marketing_profile_id');
    }

    public function referrerCompletion(): BelongsTo
    {
        return $this->belongsTo(CandleCashTaskCompletion::class, 'referrer_completion_id');
    }

    public function referredCompletion(): BelongsTo
    {
        return $this->belongsTo(CandleCashTaskCompletion::class, 'referred_completion_id');
    }

    public function referrerTransaction(): BelongsTo
    {
        return $this->belongsTo(CandleCashTransaction::class, 'referrer_transaction_id');
    }

    public function referredTransaction(): BelongsTo
    {
        return $this->belongsTo(CandleCashTransaction::class, 'referred_transaction_id');
    }
}
