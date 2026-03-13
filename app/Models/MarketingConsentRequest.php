<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingConsentRequest extends Model
{
    protected $fillable = [
        'marketing_profile_id',
        'channel',
        'token',
        'status',
        'source_type',
        'source_id',
        'payload',
        'requested_at',
        'confirmed_at',
        'revoked_at',
        'expires_at',
        'reward_awarded_points',
        'reward_awarded_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'requested_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
        'reward_awarded_points' => 'integer',
        'reward_awarded_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }
}

