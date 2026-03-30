<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCandleCashRewardOverride extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'candle_cash_reward_id',
        'name',
        'description',
        'candle_cash_cost',
        'reward_value',
        'is_active',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'candle_cash_reward_id' => 'integer',
        'candle_cash_cost' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(CandleCashReward::class, 'candle_cash_reward_id');
    }
}
