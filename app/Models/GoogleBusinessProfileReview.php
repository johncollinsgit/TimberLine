<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleBusinessProfileReview extends Model
{
    protected $fillable = [
        'google_business_profile_connection_id',
        'marketing_profile_id',
        'candle_cash_task_event_id',
        'candle_cash_task_completion_id',
        'marketing_storefront_event_id',
        'external_review_id',
        'review_name',
        'account_id',
        'account_name',
        'location_id',
        'location_name',
        'star_rating',
        'reviewer_name',
        'reviewer_profile_photo_url',
        'reviewer_is_anonymous',
        'comment',
        'review_reply_comment',
        'created_time',
        'updated_time',
        'sync_status',
        'matched_at',
        'awarded_at',
        'metadata',
        'raw_payload',
    ];

    protected $casts = [
        'star_rating' => 'integer',
        'reviewer_is_anonymous' => 'boolean',
        'created_time' => 'datetime',
        'updated_time' => 'datetime',
        'matched_at' => 'datetime',
        'awarded_at' => 'datetime',
        'metadata' => 'array',
        'raw_payload' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleBusinessProfileConnection::class, 'google_business_profile_connection_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function taskEvent(): BelongsTo
    {
        return $this->belongsTo(CandleCashTaskEvent::class, 'candle_cash_task_event_id');
    }

    public function completion(): BelongsTo
    {
        return $this->belongsTo(CandleCashTaskCompletion::class, 'candle_cash_task_completion_id');
    }

    public function storefrontEvent(): BelongsTo
    {
        return $this->belongsTo(MarketingStorefrontEvent::class, 'marketing_storefront_event_id');
    }
}
