<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BirthdayRewardIssuance extends Model
{
    protected $fillable = [
        'customer_birthday_profile_id',
        'marketing_profile_id',
        'cycle_year',
        'reward_type',
        'status',
        'points_awarded',
        'reward_code',
        'claim_window_starts_at',
        'claim_window_ends_at',
        'issued_at',
        'claimed_at',
        'metadata',
    ];

    protected $casts = [
        'cycle_year' => 'integer',
        'points_awarded' => 'integer',
        'claim_window_starts_at' => 'datetime',
        'claim_window_ends_at' => 'datetime',
        'issued_at' => 'datetime',
        'claimed_at' => 'datetime',
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
}
