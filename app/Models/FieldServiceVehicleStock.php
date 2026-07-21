<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceVehicleStock extends Model
{
    use HasTenantScope;

    protected $table = 'field_service_vehicle_inventory';

    protected $fillable = ['tenant_id', 'field_service_vehicle_id', 'field_material_catalog_item_id', 'quantity'];

    protected $casts = ['tenant_id' => 'integer', 'field_service_vehicle_id' => 'integer', 'field_material_catalog_item_id' => 'integer', 'quantity' => 'decimal:2'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FieldServiceVehicle::class, 'field_service_vehicle_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(FieldMaterialCatalogItem::class, 'field_material_catalog_item_id');
    }
}
