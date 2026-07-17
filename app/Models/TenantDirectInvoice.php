<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantDirectInvoice extends Model
{
    use HasTenantScope;

    public const STATUSES = ['draft', 'sending', 'open', 'paid', 'payment_failed', 'send_failed', 'uncollectible', 'void', 'refunded'];

    public const LINE_CATEGORIES = [
        'everbranch_service',
        'evergrove_implementation',
        'evergrove_supplemental_work',
        'evergrove_milestone',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'billing_address' => 'array',
            'line_items' => 'array',
            'metadata' => 'array',
            'finalized_at' => 'datetime',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'voided_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(TenantBillingReceipt::class, 'tenant_direct_invoice_id');
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft' && ! filled($this->provider_invoice_id);
    }
}
