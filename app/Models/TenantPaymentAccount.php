<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantPaymentAccount extends Model
{
    use HasTenantScope;

    public const STATUSES = ['not_started', 'onboarding', 'restricted', 'ready', 'disabled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'charges_enabled' => 'boolean',
            'payouts_enabled' => 'boolean',
            'details_submitted' => 'boolean',
            'platform_fee_bps' => 'integer',
            'onboarding_started_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready'
            && str_starts_with((string) $this->provider_account_id, 'acct_')
            && $this->charges_enabled
            && $this->payouts_enabled;
    }
}
