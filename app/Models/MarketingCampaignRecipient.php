<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'reason_codes',
        'scheduled_for',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'last_status_note',
    ];

    protected $casts = [
        'segment_snapshot' => 'array',
        'recommendation_snapshot' => 'array',
        'reason_codes' => 'array',
        'scheduled_for' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
