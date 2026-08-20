<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Dedicated Modern Forestry fundraiser intake record. This is neither a
 * Shopify order nor a Website Commerce order and has no payment side effect.
 */
class ModernForestryFundraiserOrder extends Model
{
    use HasTenantScope;

    public const STATUSES = ['needs_review', 'approved', 'packaged'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'shipping_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'recipient_name' => 'encrypted',
            'recipient_email' => 'encrypted',
            'recipient_phone' => 'encrypted',
            'shipping_address' => 'encrypted:array',
            'line_items' => 'encrypted:array',
            'source_payload' => 'encrypted:array',
            'source_created_at' => 'datetime',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
