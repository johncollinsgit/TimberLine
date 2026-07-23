<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingProfile extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'preset_key', 'entity_type', 'country_code', 'state_code',
        'tax_year_basis', 'accounting_basis', 'setup_status', 'configuration',
        'reviewed_at', 'reviewed_by_user_id',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'configuration' => 'encrypted:array',
        'reviewed_at' => 'datetime',
        'reviewed_by_user_id' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function complianceTasks(): HasMany
    {
        return $this->hasMany(AccountingComplianceTask::class);
    }
}
