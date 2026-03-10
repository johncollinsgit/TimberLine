<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaignRecipient extends Model
{
    protected $fillable = [
        'campaign_id',
        'marketing_profile_id',
        'segment_snapshot',
        'recommendation_snapshot',
        'variant_id',
        'channel',
        'status',
        'send_attempt_count',
        'last_send_attempt_at',
        'reason_codes',
        'scheduled_for',
        'approved_by',
        'approved_at',
        'sent_at',
        'delivered_at',
        'rejected_by',
        'rejected_at',
        'failed_at',
        'last_status_note',
    ];

    protected $casts = [
        'segment_snapshot' => 'array',
        'recommendation_snapshot' => 'array',
        'reason_codes' => 'array',
        'send_attempt_count' => 'integer',
        'last_send_attempt_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'rejected_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignVariant::class, 'variant_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(MarketingSendApproval::class, 'campaign_recipient_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(MarketingMessageDelivery::class, 'campaign_recipient_id');
    }

    public function latestDelivery(): HasOne
    {
        return $this->hasOne(MarketingMessageDelivery::class, 'campaign_recipient_id')->latestOfMany();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
