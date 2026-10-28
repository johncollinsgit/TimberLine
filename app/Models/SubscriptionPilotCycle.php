<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPilotCycle extends Model
{
    use HasTenantScope;

    protected $guarded=[];
    protected $hidden=['terms','source_evidence','stripe_payload','shopify_payload','reconciliation'];

    protected function casts(): array
    {
        return ['tenant_id'=>'integer','enrollment_id'=>'integer','amount_cents'=>'integer',
            'terms'=>'encrypted:array','source_evidence'=>'encrypted:array','stripe_payload'=>'encrypted:array','shopify_payload'=>'encrypted:array','reconciliation'=>'encrypted:array',
            'cycle_date'=>'immutable_date','payment_started_at'=>'immutable_datetime','paid_at'=>'immutable_datetime','handoff_started_at'=>'immutable_datetime','cancellation_started_at'=>'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function(self $row): void {
            foreach(['tenant_id','enrollment_id','public_id','cycle_date','amount_cents','currency','terms','source_evidence','stripe_key','stripe_payload'] as $field) {
                if($row->isDirty($field)) throw new \LogicException('Billing cycle terms and request are immutable.');
            }
            foreach(['shopify_payload','payment_intent_id','shopify_order_id'] as $field) {
                if($row->getOriginal($field)!==null && $row->isDirty($field)) throw new \LogicException('Original provider handoff cannot be replaced.');
            }
        });
        static::deleting(fn()=>throw new \LogicException('Retain subscription payment evidence.'));
    }
}
