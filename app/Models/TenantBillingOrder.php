<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantBillingOrder extends Model
{
    use HasTenantScope;

    public const STATUSES = ['authorized', 'checkout_pending', 'processing', 'paid', 'failed', 'expired', 'refunded', 'void'];

    public const TYPES = ['initial', 'milestone', 'supplemental_work'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'line_items' => 'array',
            'metadata' => 'array',
            'authorized_at' => 'datetime',
            'checkout_started_at' => 'datetime',
            'processing_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AgreementVersion::class, 'agreement_version_id');
    }

    public function acceptance(): BelongsTo
    {
        return $this->belongsTo(AgreementAcceptance::class, 'agreement_acceptance_id');
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(SubscriptionAuthorization::class, 'subscription_authorization_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(TenantBillingReceipt::class);
    }
}
