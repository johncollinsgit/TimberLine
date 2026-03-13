<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaignVariant extends Model
{
    protected $fillable = [
        'campaign_id',
        'template_id',
        'name',
        'variant_key',
        'message_text',
        'weight',
        'is_control',
        'status',
        'notes',
    ];

    protected $casts = [
        'is_control' => 'boolean',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MarketingMessageTemplate::class, 'template_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MarketingCampaignRecipient::class, 'variant_id');
    }

    public function performanceSnapshots(): HasMany
    {
        return $this->hasMany(MarketingVariantPerformanceSnapshot::class, 'variant_id');
    }
}
