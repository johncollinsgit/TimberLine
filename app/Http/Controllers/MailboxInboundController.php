<?php

namespace App\Http\Controllers;

use App\Services\Mailbox\TenantMailboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailboxInboundController extends Controller
{
    public function sendGrid(Request $request, TenantMailboxService $mail): JsonResponse
    {
        $expected = (string) config('mailbox.inbound_token');
        $provided = (string) $request->query('token', '');
        abort_if($expected === '' || ! hash_equals($expected, $provided), 403);
        abort_if((int) $request->header('content-length', 0) > 32_000_000, 413);

        return response()->json(['accepted' => $mail->ingestSendGrid($request->all())]);
    }
}
