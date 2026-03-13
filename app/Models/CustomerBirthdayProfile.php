<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerBirthdayProfile extends Model
{
    protected $fillable = [
        'marketing_profile_id',
        'birth_month',
        'birth_day',
        'birth_year',
        'birthday_full_date',
        'source',
        'source_captured_at',
        'reward_last_issued_at',
        'reward_last_issued_year',
        'metadata',
    ];

    protected $casts = [
        'birth_month' => 'integer',
        'birth_day' => 'integer',
        'birth_year' => 'integer',
        'birthday_full_date' => 'date',
        'source_captured_at' => 'datetime',
        'reward_last_issued_at' => 'datetime',
        'reward_last_issued_year' => 'integer',
        'metadata' => 'array',
    ];

    public function marketingProfile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(CustomerBirthdayAudit::class, 'customer_birthday_profile_id');
    }

    public function rewardIssuances(): HasMany
    {
        return $this->hasMany(BirthdayRewardIssuance::class, 'customer_birthday_profile_id');
    }
}
