<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BirthdayMessageEvent extends Model
{
    protected $fillable = [
        'customer_birthday_profile_id',
        'marketing_profile_id',
        'birthday_reward_issuance_id',
        'event_key',
        'campaign_type',
        'channel',
        'provider',
        'provider_message_id',
        'status',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'conversion_at',
        'utm_campaign',
        'utm_source',
        'metadata',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'conversion_at' => 'datetime',
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

    public function rewardIssuance(): BelongsTo
    {
        return $this->belongsTo(BirthdayRewardIssuance::class, 'birthday_reward_issuance_id');
    }
}
