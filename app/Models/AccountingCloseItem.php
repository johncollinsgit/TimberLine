<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingCloseItem extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'accounting_close_period_id', 'definition_key', 'title',
        'sort_order', 'status', 'deep_link', 'owner_notes', 'evidence',
        'completed_at', 'completed_by_user_id',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'accounting_close_period_id' => 'integer',
        'sort_order' => 'integer',
        'evidence' => 'encrypted:array',
        'completed_at' => 'datetime',
        'completed_by_user_id' => 'integer',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingClosePeriod::class, 'accounting_close_period_id');
    }
}
