<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandleCashTransaction extends Model
{
    protected $fillable = [
        'marketing_profile_id',
        'type',
        'candle_cash_delta',
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
        'candle_cash_delta' => 'integer',
        'points' => 'integer',
    ];

    public function getCandleCashDeltaAttribute($value): int
    {
        if ($value !== null) {
            return (int) $value;
        }

        return (int) ($this->attributes['points'] ?? 0);
    }

    public function setCandleCashDeltaAttribute($value): void
    {
        $normalized = (int) $value;

        $this->attributes['candle_cash_delta'] = $normalized;
        $this->attributes['points'] = $normalized;
    }

    public function getPointsAttribute($value): int
    {
        if ($value !== null) {
            return (int) $value;
        }

        return (int) ($this->attributes['candle_cash_delta'] ?? 0);
    }

    public function setPointsAttribute($value): void
    {
        $normalized = (int) $value;

        $this->attributes['points'] = $normalized;
        $this->attributes['candle_cash_delta'] = $normalized;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }
}
