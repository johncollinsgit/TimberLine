<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingReviewHistory extends Model
{
    protected $fillable = [
        'marketing_profile_id',
        'marketing_review_summary_id',
        'provider',
        'integration',
        'store_key',
        'external_customer_id',
        'external_review_id',
        'rating',
        'title',
        'body',
        'is_published',
        'is_pinned',
        'is_verified_buyer',
        'votes',
        'has_media',
        'media_count',
        'product_id',
        'product_title',
        'reviewed_at',
        'source_synced_at',
        'raw_payload',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_published' => 'boolean',
        'is_pinned' => 'boolean',
        'is_verified_buyer' => 'boolean',
        'votes' => 'integer',
        'has_media' => 'boolean',
        'media_count' => 'integer',
        'reviewed_at' => 'datetime',
        'source_synced_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function summary(): BelongsTo
    {
        return $this->belongsTo(MarketingReviewSummary::class, 'marketing_review_summary_id');
    }
}
