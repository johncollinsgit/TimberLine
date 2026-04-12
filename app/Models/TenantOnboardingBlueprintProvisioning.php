<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantOnboardingBlueprintProvisioning extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'source_blueprint_id',
        'provisioned_tenant_id',
        'created_by_user_id',
        'status',
        'first_opened_at',
        'first_open_acknowledged_by_user_id',
        'first_open_payload_anchor',
        'first_open_opened_path',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'source_blueprint_id' => 'integer',
        'provisioned_tenant_id' => 'integer',
        'created_by_user_id' => 'integer',
        'first_opened_at' => 'datetime',
        'first_open_acknowledged_by_user_id' => 'integer',
        'metadata' => 'array',
    ];

    public function sourceTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function provisionedTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'provisioned_tenant_id');
    }

    public function sourceBlueprint(): BelongsTo
    {
        return $this->belongsTo(TenantOnboardingBlueprint::class, 'source_blueprint_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
