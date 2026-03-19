<?php

namespace App\Models;

use App\Models\Concerns\TracksLegacyCandleCashCompatibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandleCashReward extends Model
{
    use TracksLegacyCandleCashCompatibility;

    protected $fillable = [
        'name',
        'description',
        'points_cost',
        'candle_cash_cost',
        'reward_type',
        'reward_value',
        'is_active',
    ];

    protected $casts = [
        'points_cost' => 'integer',
        'candle_cash_cost' => 'integer',
        'is_active' => 'boolean',
    ];

    public function getCandleCashCostAttribute($value): int
    {
        if ($value !== null) {
            return (int) $value;
        }

        if (array_key_exists('points_cost', $this->attributes) && $this->attributes['points_cost'] !== null) {
            $this->recordLegacyCandleCashCompatibility('candle_cash_rewards.points_cost', 'fallback_read', __METHOD__);
        }

        return (int) ($this->attributes['points_cost'] ?? 0);
    }

    public function setCandleCashCostAttribute($value): void
    {
        $normalized = max(0, (int) $value);

        $this->attributes['candle_cash_cost'] = $normalized;
        $this->attributes['points_cost'] = $normalized;
    }

    public function getPointsCostAttribute($value): int
    {
        if ($value !== null) {
            $this->recordLegacyCandleCashCompatibility('candle_cash_rewards.points_cost', 'legacy_read', __METHOD__);

            return (int) $value;
        }

        if (array_key_exists('candle_cash_cost', $this->attributes) && $this->attributes['candle_cash_cost'] !== null) {
            $this->recordLegacyCandleCashCompatibility('candle_cash_rewards.points_cost', 'legacy_read', __METHOD__);
        }

        return (int) ($this->attributes['candle_cash_cost'] ?? 0);
    }

    public function setPointsCostAttribute($value): void
    {
        $this->recordLegacyCandleCashCompatibility('candle_cash_rewards.points_cost', 'legacy_write', __METHOD__);

        $normalized = max(0, (int) $value);

        $this->attributes['points_cost'] = $normalized;
        $this->attributes['candle_cash_cost'] = $normalized;
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CandleCashRedemption::class, 'reward_id');
    }
}
