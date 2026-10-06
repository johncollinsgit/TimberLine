<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class WebsiteShipmentEvent extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'website_shipment_id' => 'integer', 'payload' => 'encrypted:array', 'occurred_at' => 'datetime'];
    }
}
