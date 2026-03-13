<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingReviewSummary extends Model
{
    protected $fillable = [
        'marketing_profile_id',
        'provider',
        'integration',
        'store_key',
        'external_customer_id',
        'external_customer_email',
        'review_count',
        'published_review_count',
        'average_rating',
        'last_reviewed_at',
        'source_synced_at',
        'raw_payload',
    ];

    protected $casts = [
        'review_count' => 'integer',
        'published_review_count' => 'integer',
        'average_rating' => 'decimal:2',
        'last_reviewed_at' => 'datetime',
        'source_synced_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(MarketingReviewHistory::class, 'marketing_review_summary_id');
    }
}
