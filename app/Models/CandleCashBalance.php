<?php

namespace App\Models;

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

    protected $casts = [
        'balance' => 'integer',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }
}
