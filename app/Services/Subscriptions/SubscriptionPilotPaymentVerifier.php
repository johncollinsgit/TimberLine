<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionPilotCycle;

/** Redirects, local flags, setup sessions and imported orders are never payment proof. */
class SubscriptionPilotPaymentVerifier
{
    public function read(SubscriptionPilotCycle $cycle): array
    {
        abort_unless($cycle->payment_intent_id,409,'The original payment is not identified yet.');
        $stripe=app(SubscriptionPilotStripeTransport::class);
        $merchant=$cycle->terms['merchant'];
        $stripe->verifyMerchant($cycle->tenant_id,$merchant,false);
        $payment=$stripe->read($merchant,'payment_intent',$cycle->payment_intent_id,['expand'=>['latest_charge']]);
        $this->identity($cycle,$payment);
        $refunds=$stripe->refunds($merchant,$cycle->payment_intent_id);
        abort_unless(isset($refunds['data']) && is_array($refunds['data']) && array_is_list($refunds['data'])
            && ($refunds['has_more']??null)===false && collect($refunds['data'])->every(fn($refund)=>($refund['payment_intent']??null)===$cycle->payment_intent_id),409,'Refund coverage requires review.');
        $payment['_pilot_refunds_clear']=$refunds['data']===[];
        return $payment;
    }

    public function identity(SubscriptionPilotCycle $cycle,array $payment): void
    {
        $payload=$cycle->stripe_payload;
        abort_unless(($payment['object']??null)==='payment_intent' && preg_match('/^pi_[A-Za-z0-9]+$/D',$payment['id']??'')
            && (!$cycle->payment_intent_id || $payment['id']===$cycle->payment_intent_id)
            && ($payment['amount']??null)===$cycle->amount_cents && ($payment['currency']??null)===strtolower($cycle->currency)
            && ($payment['customer']??null)===$payload['customer']
            && (($payment['payment_method']??null)===$payload['payment_method'] || (($payment['status']??null)==='requires_payment_method' && ($payment['payment_method']??null)===null))
            && ($payment['livemode']??null)===$cycle->terms['merchant']['livemode']
            && ($payment['metadata']['pilot_cycle']??null)===$cycle->public_id
            && ($payment['metadata']['pilot_request']??null)===$payload['metadata']['pilot_request'],409,'Stripe returned another payment or different billing terms.');
    }

    public function captured(SubscriptionPilotCycle $cycle,array $payment): bool
    {
        $this->identity($cycle,$payment);
        $charge=$payment['latest_charge']??null;
        return ($payment['status']??null)==='succeeded' && ($payment['amount_received']??null)===$cycle->amount_cents
            && ($payment['_pilot_refunds_clear']??false)===true
            && is_array($charge) && ($charge['payment_intent']??null)===$payment['id']
            && ($charge['customer']??null)===$cycle->stripe_payload['customer']
            && ($charge['status']??null)==='succeeded' && ($charge['paid']??null)===true && ($charge['captured']??null)===true
            && ($charge['amount']??null)===$cycle->amount_cents && ($charge['currency']??null)===strtolower($cycle->currency)
            && ($charge['refunded']??null)===false && ($charge['amount_refunded']??null)===0 && ($charge['disputed']??null)===false;
    }
}
