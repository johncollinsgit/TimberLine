<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingExternalCampaignStat extends Model
{
    protected $fillable = [
        'marketing_profile_id',
        'source_type',
        'external_contact_id',
        'sends_count',
        'opens_count',
        'clicks_count',
        'unsubscribed_at',
        'last_engaged_at',
        'raw_payload',
    ];

    protected $casts = [
        'unsubscribed_at' => 'datetime',
        'last_engaged_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function marketingProfile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class);
    }
}
