<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConnection;
use App\Services\Marketing\Desk\MarketingDeskAccess;
use App\Services\Marketing\Desk\MetaMarketingClient;
use App\Services\Marketing\MessagingConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MarketingDeskMetaController extends Controller
{
    public function connect(Request $request)
    {
        $tenantId = (int) $request->attributes->get('current_tenant_id');
        app(MarketingDeskAccess::class)->ensure($tenantId, $request->user(), true);
        abort_unless(filled(config('marketing_desk.meta.client_id')) && filled(config('marketing_desk.meta.client_secret')) && str_starts_with((string) config('marketing_desk.meta.redirect_uri'), 'https://'), 503, 'Facebook app setup is required.');
        $state = Str::random(48);
        Cache::put('marketing-meta-state:'.$state, ['tenant_id' => $tenantId, 'user_id' => $request->user()->id], now()->addMinutes(10));

        return redirect()->away('https://www.facebook.com/'.config('marketing_desk.meta.version').'/dialog/oauth?'.http_build_query([
            'client_id' => config('marketing_desk.meta.client_id'), 'redirect_uri' => config('marketing_desk.meta.redirect_uri'), 'state' => $state,
            'scope' => 'pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_metadata,pages_messaging,read_insights', 'response_type' => 'code',
        ]));
    }

    public function callback(Request $request)
    {
        $state = Cache::pull('marketing-meta-state:'.(string) $request->query('state'));
        abort_unless(is_array($state) && (int) $state['user_id'] === (int) $request->user()->id, 403);
        app(MarketingDeskAccess::class)->ensure($state['tenant_id'], $request->user(), true);
        abort_if($request->query('error') || ! $request->filled('code'), 422);
        $base = 'https://graph.facebook.com/'.config('marketing_desk.meta.version');
        $token = Http::acceptJson()->timeout(20)->get($base.'/oauth/access_token', [
            'client_id' => config('marketing_desk.meta.client_id'), 'client_secret' => config('marketing_desk.meta.client_secret'),
            'redirect_uri' => config('marketing_desk.meta.redirect_uri'), 'code' => $request->query('code'),
        ])->throw()->json('access_token');
        abort_unless(is_string($token) && $token !== '', 502);
        $long = Http::acceptJson()->timeout(20)->get($base.'/oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => config('marketing_desk.meta.client_id'), 'client_secret' => config('marketing_desk.meta.client_secret'), 'fb_exchange_token' => $token])->throw()->json();
        abort_if(empty($long['access_token']), 502);
        $http = Http::withToken($long['access_token'])->acceptJson()->timeout(20);
        $pages = $http->get($base.'/me/accounts', ['fields' => 'id,name,access_token,tasks', 'limit' => 100])->throw()->json('data');
        $permissions = $http->get($base.'/me/permissions')->throw()->json('data');
        $scopes = collect($permissions)->where('status', 'granted')->pluck('permission')->all();
        $ticket = Str::random(48);
        Cache::put('marketing-meta-pages:'.$ticket, Crypt::encryptString(json_encode([...$state, 'pages' => $pages, 'scopes' => $scopes], JSON_THROW_ON_ERROR)), now()->addMinutes(10));

        return view('marketing.desk.meta-pages', ['pages' => collect($pages)->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']])->all(), 'ticket' => $ticket, 'tenantId' => $state['tenant_id']]);
    }

    public function selectPage(Request $request)
    {
        $data = $request->validate(['ticket' => 'required|string|size:48', 'page_id' => 'required|string|max:100']);
        $encrypted = Cache::pull('marketing-meta-pages:'.$data['ticket']);
        abort_unless(is_string($encrypted), 403);
        $state = json_decode(Crypt::decryptString($encrypted), true, 32, JSON_THROW_ON_ERROR);
        abort_unless((int) $state['user_id'] === (int) $request->user()->id, 403);
        app(MarketingDeskAccess::class)->ensure($state['tenant_id'], $request->user(), true);
        $page = collect($state['pages'])->firstWhere('id', $data['page_id']);
        abort_unless($page, 403);
        $fingerprint = hash_hmac('sha256', $page['id'], (string) config('app.key'));
        app(\App\Services\Marketing\Desk\SocialAccountOwnership::class)->claim($state['tenant_id'], 'facebook', $fingerprint);
        $connection = IntegrationConnection::forAllTenants()->updateOrCreate(['tenant_id' => $state['tenant_id'], 'provider' => 'facebook', 'external_account_id' => $fingerprint], [
            'external_account_secret' => $page['id'], 'external_account_label' => $page['name'], 'access_token' => $page['access_token'],
            'oauth_client_id' => config('marketing_desk.meta.client_id'), 'oauth_client_secret' => config('marketing_desk.meta.client_secret'),
            'scopes' => $state['scopes'], 'status' => 'connected', 'connected_by_user_id' => $request->user()->id, 'connected_at' => now(),
            'metadata' => ['webhook_subscription' => 'pending'],
        ]);
        if (in_array('pages_manage_metadata', $state['scopes'], true)) {
            $result = app(MetaMarketingClient::class)->request($connection, 'POST', $page['id'].'/subscribed_apps', ['subscribed_fields' => 'messages,messaging_postbacks']);
            $connection->update(['metadata' => ['webhook_subscription' => ($result['success'] ?? false) ? 'subscribed' : 'pending']]);
        }

        return redirect()->route('marketing.desk.index', ['tenant' => $state['tenant_id'], 'view' => 'settings']);
    }

    public function disconnect(Request $request, int $id)
    {
        $tenantId = (int) $request->attributes->get('current_tenant_id');
        app(MarketingDeskAccess::class)->ensure($tenantId, $request->user(), true);
        $request->validate(['confirmed' => 'accepted']);
        $connection = IntegrationConnection::forTenantId($tenantId)->where('provider', 'facebook')->findOrFail($id);
        $connection->update(['status' => 'disconnected', 'access_token' => null, 'refresh_token' => null]);

        return redirect()->route('marketing.desk.index', ['view' => 'settings']);
    }

    public function webhook(Request $request)
    {
        $token = (string) config('marketing_desk.meta.verify_token');
        if ($request->isMethod('GET')) {
            abort_unless($token !== '' && ($request->query('hub_mode') ?? $request->query('hub.mode')) === 'subscribe' && hash_equals($token, (string) ($request->query('hub_verify_token') ?? $request->query('hub.verify_token'))), 403);

            return response((string) ($request->query('hub_challenge') ?? $request->query('hub.challenge')));
        }
        $secret = (string) config('marketing_desk.meta.client_secret');
        abort_unless($secret !== '' && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('X-Hub-Signature-256')), 403);
        $payload = $request->json()->all();
        abort_unless(($payload['object'] ?? '') === 'page', 422);
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $event) {
                if (data_get($event, 'message.is_echo')) {
                    continue;
                }
                $account = (string) data_get($event, 'recipient.id');
                $sender = (string) data_get($event, 'sender.id');
                $mid = (string) data_get($event, 'message.mid');
                $body = (string) data_get($event, 'message.text');
                if ($account === '' || $sender === '' || $sender === $account || $mid === '' || $body === '') {
                    continue;
                }
                $matches = IntegrationConnection::forAllTenants()->where('provider', 'facebook')->where('status', 'connected')->where('external_account_id', hash_hmac('sha256', $account, (string) config('app.key')))->limit(2)->get();
                if ($matches->count() !== 1) {
                    continue;
                }
                $connection = $matches->first();
                $service = app(MessagingConversationService::class);
                $conversation = $service->findOrCreateInstagramConversation($connection->tenant_id, null, null, $account, $sender, ['source_context' => ['facebook_connection_id' => $connection->id]], 'facebook');
                $timestamp = is_numeric($event['timestamp'] ?? null) ? \Carbon\CarbonImmutable::createFromTimestampMs($event['timestamp']) : now();
                if ($timestamp->gt(now()->addMinutes(5))) {
                    continue;
                }
                $service->appendMessage($conversation, ['channel' => 'facebook', 'direction' => 'inbound', 'provider' => 'facebook', 'provider_message_id' => $mid, 'body' => $body, 'received_at' => $timestamp, 'delivery_status' => 'received']);
            }
        }

        return response('', 200);
    }
}
