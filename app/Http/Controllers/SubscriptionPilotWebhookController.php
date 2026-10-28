<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SubscriptionPilotWebhookController extends Controller
{
    public function receive(Request $request,\App\Services\Subscriptions\SubscriptionPilotWebhookService $webhooks)
    {
        $receipt=$webhooks->receive($request->getContent(),(string)$request->header('Stripe-Signature'));
        if($receipt)\App\Jobs\ReconcileSubscriptionPilotReceipt::dispatch($receipt->tenant_id,$receipt->id);
        return response()->json(['received'=>true],202);
    }
}
