<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SubscriptionBillingCycle extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $hidden = ['terms', 'method_binding', 'source_evidence', 'provider_request', 'reconciliation', 'shopify_request'];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'agreement_id' => 'integer', 'agreement_revision' => 'integer', 'amount_cents' => 'integer',
            'terms' => 'encrypted:array', 'method_binding' => 'encrypted:array', 'source_evidence' => 'encrypted:array',
            'provider_request' => 'encrypted:array', 'reconciliation' => 'encrypted:array', 'shopify_request' => 'encrypted:array',
            'cycle_date' => 'immutable_date', 'payment_started_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'handoff_started_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->public_id ??= (string) Str::uuid());
        static::updating(function (self $row): void {
            foreach (['tenant_id', 'agreement_id', 'public_id', 'processor', 'merchant_fingerprint', 'cycle_date', 'agreement_revision',
                'amount_cents', 'currency', 'terms', 'method_binding', 'source_evidence', 'request_uuid', 'provider_request'] as $field) {
                if ($row->isDirty($field)) {
                    throw new \LogicException('Original subscription cycle terms and provider request are immutable.');
                }
            }
            foreach (['provider_reference', 'payment_started_at', 'shopify_request', 'shopify_order_id', 'handoff_started_at'] as $field) {
                if ($row->getOriginal($field) !== null && $row->isDirty($field)) {
                    throw new \LogicException('An original subscription provider attempt cannot be replaced.');
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Retain subscription payment evidence.'));
    }
}
