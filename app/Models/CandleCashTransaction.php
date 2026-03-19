<?php

namespace App\Models;

use App\Models\Concerns\TracksLegacyCandleCashCompatibility;
use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandleCashTransaction extends Model
{
    use TracksLegacyCandleCashCompatibility;

    protected $fillable = [
        'marketing_profile_id',
        'type',
        'candle_cash_delta',
        'legacy_points_origin',
        'legacy_points_value',
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
        'legacy_points_origin' => 'boolean',
        'legacy_points_value' => 'integer',
        'points' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $transaction): void {
            if (CandleCashMeasurement::isLegacyPointsOriginTransaction($transaction->attributes)) {
                $legacyPoints = CandleCashMeasurement::legacyPointsValue($transaction->attributes) ?? 0;

                $transaction->attributes['legacy_points_origin'] = true;
                $transaction->attributes['legacy_points_value'] = $legacyPoints;
                $transaction->attributes['candle_cash_delta'] = CandleCashMeasurement::legacyPointsToStartingCandleCash($legacyPoints);

                if (! array_key_exists('points', $transaction->attributes) || $transaction->attributes['points'] === null) {
                    $transaction->attributes['points'] = $legacyPoints;
                }

                return;
            }

            if (CandleCashMeasurement::isLegacyRebaseTransaction($transaction->attributes)) {
                $transaction->attributes['candle_cash_delta'] = 0;
            }
        });
    }

    public function getCandleCashDeltaAttribute($value): float
    {
        if ($value !== null) {
            return CandleCashMeasurement::normalizeStoredAmount($value);
        }

        if (array_key_exists('points', $this->attributes) && $this->attributes['points'] !== null) {
            $this->recordLegacyCandleCashCompatibility('candle_cash_transactions.points', 'fallback_read', __METHOD__);
        }

        if (CandleCashMeasurement::isLegacyPointsOriginTransaction($this->attributes)) {
            return CandleCashMeasurement::legacyPointsToStartingCandleCash($this->attributes['points'] ?? 0);
        }

        return CandleCashMeasurement::normalizeStoredAmount($this->attributes['points'] ?? 0);
    }

    public function setCandleCashDeltaAttribute($value): void
    {
        $normalized = CandleCashMeasurement::normalizeStoredAmount($value);

        $this->attributes['candle_cash_delta'] = $normalized;

        if (CandleCashMeasurement::isWholeAmount($normalized)) {
            $this->attributes['points'] = (int) round($normalized);
        }
    }

    public function getPointsAttribute($value): int
    {
        if ($value !== null) {
            $this->recordLegacyCandleCashCompatibility('candle_cash_transactions.points', 'legacy_read', __METHOD__);

            return (int) $value;
        }

        if (array_key_exists('candle_cash_delta', $this->attributes) && $this->attributes['candle_cash_delta'] !== null) {
            $this->recordLegacyCandleCashCompatibility('candle_cash_transactions.points', 'legacy_read', __METHOD__);
        }

        return (int) ($this->attributes['candle_cash_delta'] ?? 0);
    }

    public function setPointsAttribute($value): void
    {
        $this->recordLegacyCandleCashCompatibility('candle_cash_transactions.points', 'legacy_write', __METHOD__);

        $normalized = (int) $value;

        $this->attributes['points'] = $normalized;
        $this->attributes['candle_cash_delta'] = $normalized;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }
}
