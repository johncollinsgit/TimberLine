<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class TenantFleetTrackingSetting extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'phone_tracking_enabled', 'bouncie_tracking_enabled', 'policy_version', 'policy_sha256', 'counsel_review_reference', 'legal_reviewed_at', 'legal_reviewed_by_user_id', 'retention_days'];

    protected $casts = ['tenant_id' => 'integer', 'phone_tracking_enabled' => 'boolean', 'bouncie_tracking_enabled' => 'boolean', 'legal_reviewed_at' => 'datetime', 'legal_reviewed_by_user_id' => 'integer', 'retention_days' => 'integer'];
}
