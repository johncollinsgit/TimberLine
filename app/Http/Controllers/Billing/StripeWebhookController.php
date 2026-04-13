<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\StripeWebhookIngestService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StripeWebhookController extends Controller
{
    public function events(Request $request, StripeWebhookIngestService $service): Response
    {
        $payload = (string) $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');

        $result = $service->ingest($payload, $signature);

        return response((string) ($result['message'] ?? 'ok'), (int) ($result['status_code'] ?? 200));
    }
}

