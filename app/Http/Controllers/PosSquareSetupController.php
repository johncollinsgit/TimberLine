<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Pos\PosStore;
use App\Services\Pos\SquareClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class PosSquareSetupController extends Controller
{
    public function __construct(private PosStore $store, private SquareClient $square) {}

    public function start(Request $request)
    {
        $tenant = $request->integer('tenant_id');
        $this->owner($request, $tenant);
        $redirect = config('pos_live.square_oauth_redirect_uri');
        if (! is_string($redirect) || parse_url($redirect, PHP_URL_SCHEME) !== 'https' || empty(config('pos_live.square_application_id'))
            || empty(config('pos_live.square_application_secret'))) {
            abort(503, 'Square owner setup is not configured.');
        }
        $state = Str::random(64);
        $request->session()->put('pos_square_oauth', ['hash' => hash('sha256', $state), 'tenant_id' => $tenant,
            'actor_id' => $request->user()->id, 'runtime_id' => $this->store->runtime(), 'expires' => time() + 600,
            'environment' => config('pos_live.square_environment'), 'redirect' => $redirect]);
        $origin = config('pos_live.square_environment') === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
        $url = $origin.'/oauth2/authorize?'.http_build_query(['client_id' => config('pos_live.square_application_id'),
            'scope' => 'MERCHANT_PROFILE_READ PAYMENTS_READ PAYMENTS_WRITE PAYMENTS_WRITE_IN_PERSON PAYOUTS_READ',
            'state' => $state, 'session' => 'false', 'redirect_uri' => $redirect]);

        return redirect()->away($url)->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function callback(Request $request)
    {
        $state = $request->session()->pull('pos_square_oauth');
        abort_unless(is_array($state) && $state['expires'] > time() && $state['runtime_id'] === $this->store->runtime()
            && $state['actor_id'] === $request->user()?->id && is_string($request->query('state'))
            && hash_equals($state['hash'], hash('sha256', $request->query('state')))
            && is_string($request->query('code')) && strlen($request->query('code')) <= 1000, 403);
        $this->owner($request, $state['tenant_id']);
        $origin = $state['environment'] === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
        // A lost token-exchange response is not retried with a new authorization.
        $result = Http::acceptJson()->connectTimeout(5)->timeout(20)->post($origin.'/oauth2/token', [
            'client_id' => config('pos_live.square_application_id'), 'client_secret' => config('pos_live.square_application_secret'),
            'code' => $request->query('code'), 'grant_type' => 'authorization_code', 'redirect_uri' => $state['redirect'],
        ]);
        abort_unless($result->successful() && $result->json('access_token') && $result->json('merchant_id') && $result->json('expires_at'), 503,
            'Square authorization outcome is unconfirmed. Review the provider grant before reconnecting.');
        $credentials = $this->store->encrypt(collect($result->json())->only(['access_token', 'refresh_token', 'expires_at'])->all());
        $temporary = (object) ['credentials' => $credentials, 'environment' => $state['environment']];
        $locations = $this->square->get($temporary, '/v2/locations')['locations'] ?? [];
        // Durable unique keys bind one legal seller to one organization even
        // when two owner callbacks arrive concurrently on different hosts.
        $this->store->db->transaction(function () use ($state, $result): void {
            $this->store->rows('merchants', $state['tenant_id'])->insertOrIgnore($this->store->values($state['tenant_id'], [
                'merchant_id' => $result->json('merchant_id'), 'environment' => $state['environment'],
            ]));
            abort_unless($this->store->rows('merchants', $state['tenant_id'])->where('merchant_id', $result->json('merchant_id'))
                ->where('environment', $state['environment'])->lockForUpdate()->exists(), 409, 'Merchant ownership conflicts with an existing organization.');
        });
        $saved = 0;
        foreach ($locations as $location) {
            if (($location['merchant_id'] ?? '') !== $result->json('merchant_id') || ($location['status'] ?? '') !== 'ACTIVE'
                || ($location['country'] ?? '') !== 'US' || ($location['currency'] ?? '') !== 'USD') {
                continue;
            }
            $boundElsewhere = $this->store->db->table('pos_live_connections')->where('runtime_id', $this->store->runtime())
                ->where('merchant_id', $result->json('merchant_id'))->where('tenant_id', '!=', $state['tenant_id'])->exists();
            abort_if($boundElsewhere, 409, 'This merchant already belongs to another workspace.');
            $existing = $this->store->rows('connections', $state['tenant_id'])->where('merchant_id', $result->json('merchant_id'))
                ->where('provider_location_id', $location['id'])->where('environment', $state['environment'])->first();
            // Preserve the identity frozen into earlier sales during reauthorization.
            $id = $existing?->id ?? substr(hash('sha256', implode(':', [$this->store->runtime(), $state['tenant_id'], $result->json('merchant_id'), $location['id'], $state['environment']])), 0, 32);
            $this->store->rows('connections', $state['tenant_id'])->updateOrInsert(['id' => $id], $this->store->values($state['tenant_id'], [
                'merchant_id' => $result->json('merchant_id'), 'provider_location_id' => $location['id'],
                'environment' => $state['environment'], 'credentials' => $credentials,
            ]));
            $connection = $this->square->connection($state['tenant_id'], $id);
            $this->square->verify($connection);
            $this->store->event($state['tenant_id'], 'owner_setup', 'square_owner_connection_verified', ['actor_id' => $state['actor_id'], 'connection_id' => $id]);
            $saved++;
        }

        return response()->json(['connected_locations' => $saved, 'collection_enabled' => false,
            'next_step' => 'Review merchant rates, register/device assignment, catalog, tax and physical acceptance.'], 200, ['Cache-Control' => 'private, no-store']);
    }

    private function owner(Request $request, int $tenant): void
    {
        $user = $request->user();
        $current = $user instanceof User ? User::query()->find($user->id) : null;
        $membership = $this->store->db->table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $user?->id)->first();
        abort_unless(config('pos_live.enabled') === true && $current?->is_active && $current->hasVerifiedEmail()
            && $membership?->membership_active && in_array($membership->role, ['owner', 'tenant_owner', 'admin'], true), 403);
    }
}
