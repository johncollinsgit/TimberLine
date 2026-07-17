<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlantInventoryItem extends Model
{
    use HasTenantScope;

    protected $table = 'tenant_plant_inventory_items';

    protected $fillable = [
        'tenant_id',
        'name',
        'category',
        'sku',
        'vendor_source',
        'purchased_cost',
        'sell_price',
        'quantity_on_hand',
        'reserved_quantity',
        'notes',
        'square_id',
        'shopify_product_id',
        'shopify_variant_id',
        'status',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'purchased_cost' => 'decimal:2',
        'sell_price' => 'decimal:2',
        'quantity_on_hand' => 'integer',
        'reserved_quantity' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PlantInventoryAdjustment::class, 'plant_inventory_item_id');
    }

    public function getAvailableQuantityAttribute(): int
    {
        return max(0, (int) $this->quantity_on_hand - (int) $this->reserved_quantity);
    }
}
