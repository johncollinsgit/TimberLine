<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class SubscriptionBillingAction extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $hidden = ['request', 'before_state', 'after_state', 'actor_reference'];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'agreement_id' => 'integer', 'expected_revision' => 'integer',
            'request' => 'encrypted:array', 'before_state' => 'encrypted:array', 'after_state' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Subscription action evidence is append-only.'));
        static::deleting(fn () => throw new \LogicException('Retain subscription action evidence.'));
    }
}
