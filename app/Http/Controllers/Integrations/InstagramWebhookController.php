<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\Instagram\InstagramWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InstagramWebhookController extends Controller
{
    public function verify(Request $request, InstagramWebhookService $webhooks): Response
    {
        abort_unless($webhooks->verifies($request), 403);

        $challenge = (string) ($request->query('hub_challenge') ?? $request->query('hub.challenge'));

        return response($challenge, 200)
            ->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request, InstagramWebhookService $webhooks): Response
    {
        $payload = $request->json()->all();
        abort_unless(is_array($payload), 422, 'Invalid Instagram webhook payload.');
        $webhooks->assertValidSignature($request, $payload);
        $webhooks->ingest($payload);

        return response('', 200);
    }
}
