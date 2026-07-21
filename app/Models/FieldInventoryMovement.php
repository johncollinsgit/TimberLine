<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldInventoryMovement extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'field_material_catalog_item_id',
        'field_service_vehicle_id',
        'field_service_job_id',
        'created_by_user_id',
        'movement_type',
        'quantity',
        'notes',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'field_material_catalog_item_id' => 'integer',
        'field_service_vehicle_id' => 'integer',
        'field_service_job_id' => 'integer',
        'created_by_user_id' => 'integer',
        'quantity' => 'decimal:2',
    ];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(FieldMaterialCatalogItem::class, 'field_material_catalog_item_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FieldServiceVehicle::class, 'field_service_vehicle_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
