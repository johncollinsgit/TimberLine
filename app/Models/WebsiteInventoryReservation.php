<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class WebsiteInventoryReservation extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'website_product_variant_id' => 'integer', 'website_order_id' => 'integer', 'quantity' => 'integer', 'expires_at' => 'datetime'];
    }
}
