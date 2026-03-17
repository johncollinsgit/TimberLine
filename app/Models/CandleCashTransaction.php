<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandleCashTransaction extends Model
{
    protected $fillable = [
        'marketing_profile_id',
        'type',
        'points',
        'source',
        'source_id',
        'description',
        'gift_intent',
        'gift_origin',
        'notified_via',
        'notification_status',
        'campaign_key',
    ];

    protected $casts = [
        'points' => 'integer',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }
}
