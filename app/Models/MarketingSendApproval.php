<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingSendApproval extends Model
{
    protected $fillable = [
        'campaign_recipient_id',
        'recommendation_id',
        'approval_type',
        'status',
        'approver_id',
        'approved_at',
        'rejected_at',
        'notes',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function campaignRecipient(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignRecipient::class, 'campaign_recipient_id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(MarketingRecommendation::class, 'recommendation_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
