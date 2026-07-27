<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class WebsiteFulfillment extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'website_order_id' => 'integer', 'fulfilled_by_user_id' => 'integer', 'fulfilled_at' => 'datetime'];
    }
}
