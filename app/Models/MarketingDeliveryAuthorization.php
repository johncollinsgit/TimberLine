<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class MarketingDeliveryAuthorization extends Model
{
    use HasTenantScope;

    protected $table = 'marketing_delivery_authorizations';

    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'approved_at' => 'datetime'];
}
