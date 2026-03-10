<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingRecommendation extends Model
{
    protected $fillable = [
        'type',
        'campaign_id',
        'marketing_profile_id',
        'related_variant_id',
        'title',
        'summary',
        'details_json',
        'status',
        'confidence',
        'created_by_system',
        'reviewed_by',
        'reviewed_at',
        'resolution_notes',
    ];

    protected $casts = [
        'details_json' => 'array',
        'created_by_system' => 'boolean',
        'confidence' => 'decimal:2',
        'reviewed_at' => 'datetime',
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
        return $this->belongsTo(MarketingCampaignVariant::class, 'related_variant_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(MarketingSendApproval::class, 'recommendation_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
