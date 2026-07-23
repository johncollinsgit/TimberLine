<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingClosePeriod extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'period_start', 'period_end', 'status', 'completed_items',
        'total_items', 'closed_at', 'closed_by_user_id',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'completed_items' => 'integer',
        'total_items' => 'integer',
        'closed_at' => 'datetime',
        'closed_by_user_id' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(AccountingCloseItem::class);
    }
}
