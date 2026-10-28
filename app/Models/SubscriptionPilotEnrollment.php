<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SubscriptionPilotEnrollment extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $hidden = ['terms', 'access_hash', 'requests', 'payment_method_id'];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'member_id' => 'integer', 'activated_by' => 'integer',
            'terms' => 'encrypted:array', 'requests' => 'encrypted:array', 'access_expires_at' => 'immutable_datetime',
            'authorized_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime', 'next_billing_date' => 'immutable_date', 'paused_until' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->public_id ??= (string) Str::uuid());
        static::updating(function (self $row): void {
            foreach (['tenant_id', 'member_id', 'public_id', 'terms', 'terms_hash'] as $field) {
                if ($row->isDirty($field)) {
                    throw new \LogicException('Reviewed enrollment terms are immutable.');
                }
            }
            foreach (['stripe_customer_id', 'setup_session_id', 'setup_intent_id', 'payment_method_id'] as $field) {
                if ($row->getOriginal($field) !== null && $row->isDirty($field)) {
                    throw new \LogicException('The original payment authorization cannot be replaced.');
                }
            }
            $prior = $row->getOriginal('requests') ?? [];
            $next = $row->requests ?? [];
            foreach ($prior as $key => $request) {
                if (($next[$key] ?? null) !== $request) {
                    throw new \LogicException('Provider requests are immutable.');
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Retain subscription authorization evidence.'));
    }
}
