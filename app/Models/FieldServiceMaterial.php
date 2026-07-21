<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceMaterial extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'field_service_job_id',
        'field_material_catalog_item_id',
        'name',
        'quantity',
        'pulled_quantity',
        'loaded_quantity',
        'used_quantity',
        'unit',
        'unit_cost',
        'status',
        'external_source',
        'external_id',
        'notes',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'field_service_job_id' => 'integer',
        'field_material_catalog_item_id' => 'integer',
        'quantity' => 'decimal:2',
        'pulled_quantity' => 'decimal:2',
        'loaded_quantity' => 'decimal:2',
        'used_quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(FieldMaterialCatalogItem::class, 'field_material_catalog_item_id');
    }
}
