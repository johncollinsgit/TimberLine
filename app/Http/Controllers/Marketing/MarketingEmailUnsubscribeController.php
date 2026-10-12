<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingEmailUnsubscribeService;
use App\Services\Marketing\MessagingContactChannelStateService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MarketingEmailUnsubscribeController extends Controller
{
    public function show(Request $request, MarketingEmailUnsubscribeService $unsubscribe): Response
    {
        $token = (string) $request->query('token', '');
        abort_unless($unsubscribe->recipient($token), 404);

        return response()->view('emails.marketing.unsubscribe', ['token' => $token, 'done' => false])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, MarketingEmailUnsubscribeService $unsubscribe, MessagingContactChannelStateService $states): Response
    {
        $recipient = $unsubscribe->recipient((string) $request->query('token', ''));
        abort_unless($recipient, 404);
        $oneClick = $request->input('List-Unsubscribe') === 'One-Click';
        abort_unless($oneClick || $request->input('confirm') === '1', 422);
        $states->markEmailStatus($recipient['tenant_id'], null, $recipient['email'], 'unsubscribed',
            'unsubscribe_link', 'everbranch_unsubscribe', ['method' => $oneClick ? 'one_click' : 'footer']);

        return ($oneClick ? response('Unsubscribed', 200) : response()->view('emails.marketing.unsubscribe', ['done' => true, 'token' => '']))
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
