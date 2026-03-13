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
        'reward_type',
        'reward_value',
        'is_active',
    ];

    protected $casts = [
        'points_cost' => 'integer',
        'is_active' => 'boolean',
    ];

    public function redemptions(): HasMany
    {
        return $this->hasMany(CandleCashRedemption::class, 'reward_id');
    }
}
