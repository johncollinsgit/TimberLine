<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingComplianceTask extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'accounting_profile_id', 'task_key', 'period_key', 'name',
        'explanation', 'jurisdiction', 'obligation', 'due_at', 'amount_due',
        'status', 'destination_name', 'destination_url', 'source_url',
        'quickbooks_expected', 'confidence', 'assignee_label', 'notes',
        'metadata', 'completed_at', 'completed_by_user_id',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'accounting_profile_id' => 'integer',
        'due_at' => 'datetime',
        'amount_due' => 'decimal:2',
        'quickbooks_expected' => 'boolean',
        'metadata' => 'encrypted:array',
        'completed_at' => 'datetime',
        'completed_by_user_id' => 'integer',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AccountingProfile::class, 'accounting_profile_id');
    }
}
