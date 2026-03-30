<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCandleCashTaskOverride extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'candle_cash_task_id',
        'title',
        'description',
        'reward_amount',
        'enabled',
        'display_order',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'candle_cash_task_id' => 'integer',
        'reward_amount' => 'decimal:2',
        'enabled' => 'boolean',
        'display_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(CandleCashTask::class, 'candle_cash_task_id');
    }
}
