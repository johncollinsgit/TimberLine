<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldMaterialCatalogItem extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'name', 'sku', 'unit', 'description', 'quantity_on_hand', 'reorder_level', 'unit_cost', 'active'];

    protected $casts = [
        'tenant_id' => 'integer',
        'quantity_on_hand' => 'decimal:2',
        'reorder_level' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function vehicleStocks(): HasMany
    {
        return $this->hasMany(FieldServiceVehicleStock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FieldInventoryMovement::class);
    }
}
