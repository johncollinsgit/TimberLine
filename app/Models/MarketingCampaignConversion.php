<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaignConversion extends Model
{
    protected $fillable = [
        'campaign_id',
        'marketing_profile_id',
        'campaign_recipient_id',
        'attribution_type',
        'source_type',
        'source_id',
        'converted_at',
        'order_total',
        'notes',
        'attribution_snapshot',
    ];

    protected $casts = [
        'converted_at' => 'datetime',
        'order_total' => 'decimal:2',
        'attribution_snapshot' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignRecipient::class, 'campaign_recipient_id');
    }
}
