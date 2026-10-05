<?php

namespace App\Services\HighLevel;

use App\Models\HighLevel\Authorization;
use App\Models\HighLevel\Installation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class HighLevelApi
{
    public function exchange(string $code): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => config('highlevel.redirect_uri')]);
    }

    public function token(array $payload): array
    {
        // Rotating tokens and authorization codes must never be blindly retried.
        $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(15)
            ->post($this->url('/oauth/token'), [...$payload,
                'client_id' => config('highlevel.client_id'), 'client_secret' => config('highlevel.client_secret')]);
        abort_unless($response->successful(), 503, 'HighLevel authorization is temporarily unavailable.');
        $data = $response->json();
        abort_unless(is_array($data) && filled($data['access_token'] ?? null), 503, 'HighLevel authorization did not complete.');

        return $data;
    }

    public function saveTokens(Model $record, array $tokens): void
    {
        abort_unless(filled($tokens['access_token'] ?? null), 503);
        $record->forceFill(['access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $record->refresh_token,
            'expires_at' => now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 86400)))])->save();
    }

    public function accessToken(Authorization|Installation $record): string
    {
        return Cache::lock('hl:token:'.$record->getTable().':'.$record->id, 45)->block(5, function () use ($record): string {
            $record->refresh();
            abort_unless(filled($record->access_token), 403, 'This HighLevel installation must be reauthorized.');
            if (! $record->expires_at || $record->expires_at->lte(now()->addMinute())) {
                abort_unless(filled($record->refresh_token), 403, 'This HighLevel installation must be reauthorized.');
                $observedRefresh = $record->refresh_token;
                $observedAccess = $record->access_token;
                $observedStatus = $record->status;
                $tokens = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $record->refresh_token,
                    'user_type' => $record instanceof Authorization ? 'Company' : 'Location']);
                abort_if(isset($tokens['companyId']) && $tokens['companyId'] !== $record->company_id, 403);
                abort_if($record instanceof Installation && isset($tokens['locationId']) && $tokens['locationId'] !== $record->location_id, 403);
                DB::transaction(function () use ($record, $tokens, $observedRefresh, $observedAccess, $observedStatus): void {
                    $current = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                    abort_unless($current->status === $observedStatus && $current->refresh_token === $observedRefresh
                        && $current->access_token === $observedAccess, 403, 'HighLevel authorization changed. Reopen the app.');
                    $this->saveTokens($current, $tokens);
                    $record->refresh();
                });
            }

            return (string) $record->access_token;
        });
    }

    public function get(Authorization|Installation $record, string $path, array $query = []): array
    {
        $response = Http::withToken($this->accessToken($record))->acceptJson()
            ->withHeaders(['Version' => config('highlevel.api_version')])->connectTimeout(5)->timeout(15)
            ->get($this->url($path), $query);
        abort_if(in_array($response->status(), [401, 403, 404], true), 403, 'HighLevel access could not be verified.');
        abort_unless($response->successful(), 503, 'HighLevel is temporarily unavailable. Try again shortly.');
        $data = $response->json();
        abort_unless(is_array($data), 503);

        return $data;
    }

    public function locationToken(Authorization $agency, string $locationId): array
    {
        $response = Http::withToken($this->accessToken($agency))->asForm()->acceptJson()
            ->withHeaders(['Version' => config('highlevel.api_version')])->connectTimeout(5)->timeout(15)
            ->post($this->url('/oauth/locationToken'), ['companyId' => $agency->company_id, 'locationId' => $locationId]);
        abort_unless($response->successful(), 503, 'HighLevel location authorization could not be completed.');
        $data = $response->json();
        abort_unless(is_array($data) && ($data['locationId'] ?? null) === $locationId
            && (! isset($data['appId']) || $data['appId'] === config('highlevel.app_id')), 403);

        return $data;
    }

    public function installedLocations(Authorization $agency): array
    {
        $locations = [];
        for ($skip = 0; $skip < 10000; $skip += 100) {
            $data = $this->get($agency, '/oauth/installedLocations', ['companyId' => $agency->company_id,
                'appId' => config('highlevel.app_id'), 'isInstalled' => 'true', 'limit' => 100, 'skip' => $skip]);
            $page = $data['locations'] ?? [];
            abort_unless(is_array($page), 503);
            array_push($locations, ...$page);
            if (count($page) < 100) {
                return $locations;
            }
        }
        abort(503, 'HighLevel installation pagination could not complete.');
    }

    public function verifiedAdmin(Installation|Authorization $record, string $userId, ?string $locationId = null): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,80}$/', $userId), 403);
        $user = $this->get($record, '/users/'.$userId);
        $roles = $user['roles'] ?? [];
        $isAgency = ($roles['type'] ?? null) === 'agency';
        // Agency users are fetched with that agency's authorization, never a
        // location token that cannot independently prove their agency membership.
        abort_unless(($user['id'] ?? null) === $userId && ($roles['role'] ?? null) === 'admin', 403, 'A current HighLevel administrator is required.');
        if ($isAgency) {
            abort_unless($record instanceof Authorization, 403);
            $members = $this->get($record, '/users/search', ['companyId' => $record->company_id, 'ids' => $userId, 'limit' => 1]);
            abort_unless(collect($members['users'] ?? [])->contains(fn ($member) => ($member['id'] ?? null) === $userId), 403, 'Agency membership could not be verified.');
            abort_if(isset($roles['companyId']) && $roles['companyId'] !== $record->company_id, 403);
            $assigned = $roles['locationIds'] ?? [];
            abort_if($locationId && is_array($assigned) && count($assigned) > 0 && ! in_array($locationId, $assigned, true), 403);
        } else {
            abort_unless($locationId && in_array($locationId, (array) ($roles['locationIds'] ?? []), true), 403, 'You no longer have administrator access to this client account.');
        }

        return $user;
    }

    private function url(string $path): string
    {
        return rtrim(config('highlevel.api_base'), '/').$path;
    }
}
