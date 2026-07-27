<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class WebsiteOrderLine extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'website_order_id' => 'integer', 'website_product_variant_id' => 'integer', 'quantity' => 'integer', 'unit_price_cents' => 'integer', 'line_total_cents' => 'integer', 'snapshot' => 'array'];
    }
}
