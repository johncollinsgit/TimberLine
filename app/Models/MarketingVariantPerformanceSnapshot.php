<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingVariantPerformanceSnapshot extends Model
{
    protected $fillable = [
        'campaign_id',
        'variant_id',
        'channel',
        'window_start',
        'window_end',
        'recipients_count',
        'sent_count',
        'delivered_count',
        'opened_count',
        'clicked_count',
        'converted_count',
        'attributed_revenue',
        'snapshot_meta',
    ];

    protected $casts = [
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'snapshot_meta' => 'array',
        'attributed_revenue' => 'decimal:2',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignVariant::class, 'variant_id');
    }
}

