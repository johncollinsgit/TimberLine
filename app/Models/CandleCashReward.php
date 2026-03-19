<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandleCashReward extends Model
{
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
            return (int) $value;
        }

        return (int) ($this->attributes['candle_cash_cost'] ?? 0);
    }

    public function setPointsCostAttribute($value): void
    {
        $normalized = max(0, (int) $value);

        $this->attributes['points_cost'] = $normalized;
        $this->attributes['candle_cash_cost'] = $normalized;
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CandleCashRedemption::class, 'reward_id');
    }
}
