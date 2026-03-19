<?php

namespace App\Models;

use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandleCashBalance extends Model
{
    protected $table = 'candle_cash_balances';
    protected $primaryKey = 'marketing_profile_id';
    public $incrementing = false;

    protected $fillable = [
        'marketing_profile_id',
        'balance',
    ];

    public function getBalanceAttribute($value): float
    {
        return CandleCashMeasurement::normalizeStoredAmount($value);
    }

    public function setBalanceAttribute($value): void
    {
        $this->attributes['balance'] = CandleCashMeasurement::normalizeStoredAmount($value);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }
}
