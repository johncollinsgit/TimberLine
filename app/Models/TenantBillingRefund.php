<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBillingRefund extends Model
{
    public const STATUSES = ['pending', 'succeeded', 'failed', 'canceled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'processed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(TenantBillingReceipt::class, 'tenant_billing_receipt_id');
    }

    public function billingOrder(): BelongsTo
    {
        return $this->belongsTo(TenantBillingOrder::class, 'tenant_billing_order_id');
    }

    public function directInvoice(): BelongsTo
    {
        return $this->belongsTo(TenantDirectInvoice::class, 'tenant_direct_invoice_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
