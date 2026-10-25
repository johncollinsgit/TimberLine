<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WebsiteInventoryReservation extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    /** Core-owned holds have no Website order, and still consume shared stock. */
    public function scopeExcludingWebsiteOrder(Builder $query, int $orderId): Builder
    {
        return $query->where(fn (Builder $owners) => $owners->whereNull('website_order_id')
            ->orWhere('website_order_id', '!=', $orderId));
    }

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'website_product_variant_id' => 'integer', 'website_order_id' => 'integer', 'quantity' => 'integer', 'expires_at' => 'datetime'];
    }
}
