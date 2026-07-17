<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBillingReceipt extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider_calculated_tax' => 'boolean', 'billing_period_starts_at' => 'datetime',
            'billing_period_ends_at' => 'datetime', 'billed_at' => 'datetime', 'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(SubscriptionAuthorization::class, 'subscription_authorization_id');
    }

    public function billingOrder(): BelongsTo
    {
        return $this->belongsTo(TenantBillingOrder::class, 'tenant_billing_order_id');
    }
}
