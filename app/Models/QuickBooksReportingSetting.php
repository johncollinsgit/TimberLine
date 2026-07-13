<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickBooksReportingSetting extends Model
{
    use HasTenantScope;

    protected $table = 'quickbooks_reporting_settings';

    protected $fillable = [
        'tenant_id', 'integration_connection_id', 'scheduled_sync_enabled', 'sync_cadence',
        'supplies_account_mappings', 'wage_account_mappings', 'contract_labor_account_mappings',
        'owner_compensation_account_mappings', 'owner_compensation_adjustments',
        'mappings_reviewed_at', 'mappings_reviewed_by_user_id',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'integration_connection_id' => 'integer',
        'scheduled_sync_enabled' => 'boolean',
        'supplies_account_mappings' => 'encrypted:array',
        'wage_account_mappings' => 'encrypted:array',
        'contract_labor_account_mappings' => 'encrypted:array',
        'owner_compensation_account_mappings' => 'encrypted:array',
        'owner_compensation_adjustments' => 'encrypted:array',
        'mappings_reviewed_at' => 'datetime',
        'mappings_reviewed_by_user_id' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
