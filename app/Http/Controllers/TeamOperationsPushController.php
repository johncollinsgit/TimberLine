<?php

namespace App\Http\Controllers;

use App\Jobs\SyncTeamOperations;
use App\Models\TeamProviderConnection;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TeamOperationsPushController extends Controller
{
    public function __invoke(Request $request, string $connection)
    {
        abort_unless(config('team_operations.enabled'), 404);
        abort_if(strlen($request->getContent()) > 32768, 413);
        $c = TeamProviderConnection::query()->where('status', 'connected')->findOrFail($connection);
        if ($c->provider === 'gmail') {
            $audience = config('team_operations.google.push_audience');
            $service = config('team_operations.google.push_service_account');
            abort_unless($audience && $service && $request->bearerToken(), 403);
            try {
                $keys = Cache::remember('team-google-push-jwks', 3600, fn () => Http::connectTimeout(5)->timeout(10)->get('https://www.googleapis.com/oauth2/v3/certs')->throw()->json());
                $claims = JWT::decode($request->bearerToken(), JWK::parseKeySet($keys));
                abort_unless(in_array($claims->iss ?? '', ['https://accounts.google.com', 'accounts.google.com'], true) && ($claims->aud ?? '') === $audience && ($claims->email ?? '') === $service && ($claims->email_verified ?? false) === true, 403);
                $decoded = json_decode(base64_decode(strtr((string) $request->input('message.data'), '-_', '+/'), true) ?: '', true, 8, JSON_THROW_ON_ERROR);
                abort_unless(($decoded['emailAddress'] ?? '') === $c->account_name && ctype_digit((string) ($decoded['historyId'] ?? '')), 403);
            } catch (\Throwable) {
                abort(403, 'Push identity could not be verified.');
            }
        } elseif ($c->provider === 'sheets') {
            $watch = $c->settings['watch'] ?? [];
            abort_unless(! empty($watch['token']) && hash_equals($watch['token'], $request->header('X-Goog-Channel-Token', '')) && ($watch['id'] ?? '') === $request->header('X-Goog-Channel-ID') && ($watch['resource_id'] ?? '') === $request->header('X-Goog-Resource-ID'), 403);
        } else {
            abort(404);
        }
        // Notifications signal a read. Cell contents or email bodies in a notification are never imported.
        if (Cache::add('team-push:'.$c->id.':'.$c->generation, true, 20)) {
            SyncTeamOperations::dispatch($c->id, $c->generation);
        }

        return response()->noContent();
    }
}
