<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable accounting-review package. It intentionally is not a QuickBooks
 * invoice and cannot send, charge, or report recipient-open activity.
 */
class ModernForestryFundraiserInvoicePackage extends Model
{
    use HasTenantScope;

    public const STATUSES = ['review_required'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'payment_terms_days' => 'integer',
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'shipping_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'order_ids' => 'array',
            'invoice_lines' => 'encrypted:array',
            'review_notes' => 'array',
            'prepared_at' => 'datetime',
        ];
    }
}
