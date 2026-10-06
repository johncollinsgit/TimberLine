<?php

namespace App\Http\Controllers\HighLevel;

use App\Http\Controllers\Controller;
use App\Services\HighLevel\EmbeddedSessionService;
use App\Services\HighLevel\HighLevelApi;
use App\Services\HighLevel\InstallationService;
use App\Services\HighLevel\OAuthStateService;
use App\Services\HighLevel\WebhookInbox;
use Illuminate\Http\Request;

class HighLevelController extends Controller
{
    public function launch(EmbeddedSessionService $sessions)
    {
        return view('highlevel.fleet', ['challenge' => $sessions->challenge(), 'parentOrigins' => config('highlevel.parent_origins')]);
    }

    public function challenge(EmbeddedSessionService $sessions)
    {
        return response()->json(['challenge' => $sessions->challenge()]);
    }

    public function exchange(Request $request, EmbeddedSessionService $sessions)
    {
        $data = $request->validate(['encryptedData' => 'required|string|max:16384', 'challenge' => 'required|string|size:64', 'parentOrigin' => 'required|string|max:255']);

        return response()->json($sessions->exchange($data['encryptedData'], $data['challenge'], $data['parentOrigin']));
    }

    public function install(OAuthStateService $states)
    {
        abort_unless(filled(config('highlevel.client_id')), 503, 'The private app is awaiting developer configuration.');
        $state = $states->issue('highlevel');

        return redirect()->away(config('highlevel.authorization_url').'?'.http_build_query([
            'response_type' => 'code', 'client_id' => config('highlevel.client_id'), 'redirect_uri' => config('highlevel.redirect_uri'),
            'scope' => implode(' ', config('highlevel.scopes')), 'state' => $state]));
    }

    public function callback(Request $request, OAuthStateService $states, HighLevelApi $api, InstallationService $installs)
    {
        // Marketplace-initiated installs do not originate at our install URL.
        // They carry no local session and cannot link any existing workspace.
        // Validate state when we initiated OAuth; otherwise require the returned
        // agency token to prove the exact app and independently verified installer.
        if ($request->filled('state')) {
            $states->consume('highlevel', (string) $request->query('state'));
        }
        abort_if($request->filled('error'), 422, 'HighLevel authorization was cancelled.');
        $code = (string) $request->query('code');
        abort_if($code === '' || strlen($code) > 4096, 422);
        $installed = $installs->authorize($api->exchange($code));

        return response()->view('highlevel.complete', ['title' => 'Everbranch Fleet installed',
            'message' => count($installed).' client account(s) connected. Return to your CRM and open Everbranch. Billing and pilot activation are checked before vehicle collection.']);
    }

    public function lifecycle(Request $request, WebhookInbox $inbox)
    {
        abort_if(strlen($request->getContent()) > 65536, 413);
        $inbox->verifyHighLevel($request);
        $payload = $request->json()->all();
        abort_unless(($payload['appId'] ?? '') === config('highlevel.app_id'), 422);
        if (! in_array($payload['type'] ?? '', ['INSTALL', 'UPDATE', 'UNINSTALL', 'APP_PAYMENT_STATUS'], true)) {
            return response()->json(['accepted' => false]);
        }
        $inbox->accept('highlevel', $payload, $request->getContent());

        return response()->json(['accepted' => true], 202);
    }

    public function bouncieWebhook(Request $request, WebhookInbox $inbox)
    {
        abort_if(strlen($request->getContent()) > 1048576, 413);
        $secret = (string) config('highlevel.bouncie_webhook_key');
        abort_unless($secret !== '' && hash_equals($secret, (string) ($request->header('X-Bouncie-Authorization') ?: $request->header('Authorization'))), 401);
        $inbox->accept('bouncie', $request->json()->all(), $request->getContent());

        return response()->json(['accepted' => true], 202);
    }

    public function disconnect(Request $request, InstallationService $installs)
    {
        $install = $request->attributes->get('highlevel_session')->installation;
        $installs->uninstall($install);

        return response()->json(['message' => 'Everbranch access and collection stopped. An agency administrator must also uninstall the app in HighLevel to end app billing. Your Bouncie subscription is separate.']);
    }
}
