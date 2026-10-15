<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class WebsiteOrderNotification extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'website_order_id' => 'integer', 'website_shipment_id' => 'integer',
            'snapshot' => 'encrypted:array', 'attempts' => 'integer', 'next_attempt_at' => 'datetime',
            'lease_expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
