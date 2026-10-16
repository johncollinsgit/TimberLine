<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class MarketingDeliveryAttempt extends Model
{
    use HasTenantScope;

    protected $table = 'marketing_delivery_attempts';

    protected $guarded = [];

    protected $casts = ['sent_at' => 'datetime'];
}
