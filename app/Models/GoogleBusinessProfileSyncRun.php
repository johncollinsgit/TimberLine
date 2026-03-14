<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleBusinessProfileSyncRun extends Model
{
    protected $fillable = [
        'google_business_profile_connection_id',
        'triggered_by_user_id',
        'trigger_type',
        'status',
        'fetched_reviews_count',
        'new_reviews_count',
        'updated_reviews_count',
        'matched_reviews_count',
        'awarded_reviews_count',
        'duplicate_reviews_count',
        'unmatched_reviews_count',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
        'metadata',
    ];

    protected $casts = [
        'fetched_reviews_count' => 'integer',
        'new_reviews_count' => 'integer',
        'updated_reviews_count' => 'integer',
        'matched_reviews_count' => 'integer',
        'awarded_reviews_count' => 'integer',
        'duplicate_reviews_count' => 'integer',
        'unmatched_reviews_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleBusinessProfileConnection::class, 'google_business_profile_connection_id');
    }

    public function triggerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
