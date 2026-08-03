<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteProductVariant extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'website_product_id' => 'integer', 'price_cents' => 'integer', 'wholesale_price_cents' => 'integer', 'compare_at_price_cents' => 'integer', 'inventory_quantity' => 'integer', 'is_available' => 'boolean', 'options' => 'array'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(WebsiteProduct::class, 'website_product_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(WebsiteInventoryMovement::class);
    }
}
