<?php

namespace App\Models;

use App\Models\Concerns\TracksLegacyCandleCashCompatibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandleCashRedemption extends Model
{
    use TracksLegacyCandleCashCompatibility;

    protected $fillable = [
        'marketing_profile_id',
        'reward_id',
        'points_spent',
        'candle_cash_spent',
        'platform',
        'redemption_code',
        'status',
        'issued_at',
        'expires_at',
        'redeemed_at',
        'canceled_at',
        'redeemed_channel',
        'external_order_source',
        'external_order_id',
        'redemption_context',
        'reconciliation_notes',
        'redeemed_by',
    ];

    protected $casts = [
        'points_spent' => 'integer',
        'candle_cash_spent' => 'integer',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'redemption_context' => 'array',
    ];

    public function getCandleCashSpentAttribute($value): int
    {
        if ($value !== null) {
            return (int) $value;
        }

        if (array_key_exists('points_spent', $this->attributes) && $this->attributes['points_spent'] !== null) {
            $this->recordLegacyCandleCashCompatibility('candle_cash_redemptions.points_spent', 'fallback_read', __METHOD__);
        }

        return (int) ($this->attributes['points_spent'] ?? 0);
    }

    public function setCandleCashSpentAttribute($value): void
    {
        $normalized = max(0, (int) $value);

        $this->attributes['candle_cash_spent'] = $normalized;
        $this->attributes['points_spent'] = $normalized;
    }

    public function getPointsSpentAttribute($value): int
    {
        if ($value !== null) {
            $this->recordLegacyCandleCashCompatibility('candle_cash_redemptions.points_spent', 'legacy_read', __METHOD__);

            return (int) $value;
        }

        if (array_key_exists('candle_cash_spent', $this->attributes) && $this->attributes['candle_cash_spent'] !== null) {
            $this->recordLegacyCandleCashCompatibility('candle_cash_redemptions.points_spent', 'legacy_read', __METHOD__);
        }

        return (int) ($this->attributes['candle_cash_spent'] ?? 0);
    }

    public function setPointsSpentAttribute($value): void
    {
        $this->recordLegacyCandleCashCompatibility('candle_cash_redemptions.points_spent', 'legacy_write', __METHOD__);

        $normalized = max(0, (int) $value);

        $this->attributes['points_spent'] = $normalized;
        $this->attributes['candle_cash_spent'] = $normalized;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(CandleCashReward::class, 'reward_id');
    }

    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by');
    }

    public function storefrontEvents(): HasMany
    {
        return $this->hasMany(MarketingStorefrontEvent::class, 'candle_cash_redemption_id');
    }
}
