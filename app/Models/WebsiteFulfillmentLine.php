<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class WebsiteFulfillmentLine extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'website_fulfillment_id' => 'integer', 'website_order_line_id' => 'integer', 'quantity' => 'integer'];
    }
}
