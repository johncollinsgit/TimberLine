<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePlanVersionMedia extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'service_plan_version_id', 'workspace_asset_id', 'visibility', 'sort_order', 'caption', 'alt_text'];

    protected $casts = ['tenant_id' => 'integer', 'service_plan_version_id' => 'integer', 'workspace_asset_id' => 'integer', 'sort_order' => 'integer'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ServicePlanVersion::class, 'service_plan_version_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(WorkspaceAsset::class, 'workspace_asset_id');
    }
}
