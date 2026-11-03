<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class WebsiteShopPayAttempt extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $hidden = ['session_token', 'receipt_token', 'checkout_state'];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'website_cart_id' => 'integer',
            'website_order_id' => 'integer',
            'website_payment_id' => 'integer',
            'amount_cents' => 'integer',
            'session_token' => 'encrypted',
            'receipt_token' => 'encrypted',
            'checkout_state' => 'encrypted:array',
            'submitted_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }
}
