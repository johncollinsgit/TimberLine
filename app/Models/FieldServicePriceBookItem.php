<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class FieldServicePriceBookItem extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'source', 'external_id', 'name', 'item_type', 'sku', 'description',
        'unit_price', 'purchase_cost', 'active', 'taxable', 'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'unit_price' => 'decimal:4',
        'purchase_cost' => 'decimal:4',
        'active' => 'boolean',
        'taxable' => 'boolean',
        'metadata' => 'array',
    ];
}
