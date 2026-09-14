<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Jobs\Trajectory\SyncBank;
use App\Models\Trajectory\Connection;
use App\Services\Trajectory\PlaidService;
use App\Services\Trajectory\SmsService;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function plaid(Request $request, PlaidService $plaid)
    {
        abort_unless(config('trajectory.enabled'), 404);
        abort_unless($plaid->verifyWebhook((string) $request->header('Plaid-Verification'), $request->getContent()), 403);
        $connection = Connection::where('external_id', (string) $request->input('item_id'))->first();
        if ($connection && $connection->status !== 'disconnected') {
            if ($request->input('webhook_type') === 'ITEM' && $request->input('webhook_code') === 'ERROR') {
                $connection->update(['status' => 'needs_attention']);
            } else {
                SyncBank::dispatch($connection->id);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function sms(Request $request, SmsService $sms)
    {
        abort_unless(config('trajectory.enabled') && config('trajectory.sms_enabled'), 404);
        $valid = app(\App\Services\Marketing\TwilioSmsService::class)->validateSignature($request->fullUrl(), $request->post(), (string) $request->header('X-Twilio-Signature'));
        abort_unless($valid, 403);
        $sms->reply((string) $request->input('From'), (string) $request->input('Body'), (string) $request->input('MessageSid'));

        return response('<Response/>', 200)->header('Content-Type', 'text/xml');
    }
}
