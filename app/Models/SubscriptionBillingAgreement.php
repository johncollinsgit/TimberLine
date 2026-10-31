<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SubscriptionBillingAgreement extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $hidden = ['terms', 'method_binding', 'authorization_evidence'];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'member_id' => 'integer', 'revision' => 'integer', 'prepared_by' => 'integer', 'activated_by' => 'integer',
            'terms' => 'encrypted:array', 'method_binding' => 'encrypted:array', 'authorization_evidence' => 'encrypted:array',
            'method_verified_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime',
            'billing_anchor_date' => 'immutable_date', 'next_billing_date' => 'immutable_date', 'resume_on' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->public_id ??= (string) Str::uuid());
        static::updating(function (self $row): void {
            foreach (['tenant_id', 'member_id', 'public_id', 'processor', 'method_binding', 'prepared_by', 'billing_anchor_date'] as $field) {
                if ($row->isDirty($field)) {
                    throw new \LogicException('The original billing agreement and payment binding cannot be replaced.');
                }
            }
            foreach (['original_authorization', 'reference', 'continuing_authorization_reviewed_by', 'source_terms_reviewed_by'] as $field) {
                if (data_get($row->getOriginal('authorization_evidence'), $field) !== data_get($row->authorization_evidence, $field)) {
                    throw new \LogicException('Retain the original reviewed authorization; never reconstruct it from current terms.');
                }
            }
            foreach (['original', 'calculation_id', 'calculate_dispatched_at', 'commit_original', 'commit_dispatch_proof', 'commit_dispatched_at', 'contract_id'] as $field) {
                $before = data_get($row->getOriginal('authorization_evidence'), 'shopify_adoption.'.$field);
                if ($before !== null && data_get($row->authorization_evidence, 'shopify_adoption.'.$field) !== $before) {
                    throw new \LogicException('Retain the original Shopify adoption request, dispatch and provider references.');
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Retain subscription authorization history.'));
    }
}
