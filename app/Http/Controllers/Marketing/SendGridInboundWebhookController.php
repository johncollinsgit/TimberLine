<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\SendGridInboundEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SendGridInboundWebhookController extends Controller
{
    public function handle(Request $request, SendGridInboundEmailService $inboundEmailService): JsonResponse
    {
        $configuredToken = trim((string) config('marketing.messaging.responses.sendgrid_inbound_token', ''));
        $requestToken = trim((string) ($request->query('token', $request->input('token', ''))));

        if ($configuredToken !== '' && ! hash_equals($configuredToken, $requestToken)) {
            return response()->json([
                'ok' => false,
                'status' => 'invalid_token',
                'message' => 'Inbound email token is invalid.',
            ], 403);
        }

        return response()->json($inboundEmailService->handleInbound($request->all()));
    }
}
