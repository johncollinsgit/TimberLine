<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingIdentityReview extends Model
{
    protected $fillable = [
        'status',
        'proposed_marketing_profile_id',
        'raw_email',
        'raw_phone',
        'raw_first_name',
        'raw_last_name',
        'source_type',
        'source_id',
        'conflict_reasons',
        'payload',
        'reviewed_by',
        'reviewed_at',
        'resolution_notes',
    ];

    protected $casts = [
        'conflict_reasons' => 'array',
        'payload' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function proposedMarketingProfile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'proposed_marketing_profile_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
