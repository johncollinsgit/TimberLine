<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosRefund extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'pos_payment_attempt_id' => 'integer', 'user_id' => 'integer', 'amount_cents' => 'integer',
            'snapshot' => 'encrypted:array', 'reason' => 'encrypted', 'provider_requested_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PosPaymentAttempt::class, 'pos_payment_attempt_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
