<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class AccountingDebtSnapshot extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'source_account_id', 'account_name', 'account_type',
        'observed_on', 'balance', 'credit_limit', 'available_credit',
        'interest_rate', 'source_metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'observed_on' => 'date',
        'balance' => 'decimal:2',
        'credit_limit' => 'decimal:2',
        'available_credit' => 'decimal:2',
        'interest_rate' => 'decimal:5',
        'source_metadata' => 'encrypted:array',
    ];
}
