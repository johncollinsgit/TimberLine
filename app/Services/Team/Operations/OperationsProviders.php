<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Independent multi-account connections; never borrows automation credentials. */
class OperationsProviders
{
    public function begin(Request $request, Tenant $tenant, TeamArea $area, string $provider, bool $send): string
    {
        $this->provider($provider);
        $config = $provider === 'asana' ? 'team_work.asana' : 'team_operations.google';
        abort_unless(config($config.'.client_id') && config($config.'.client_secret'), 503, 'This provider needs administrator OAuth configuration.');
        $scopes = $provider === 'asana' ? \App\Services\Team\AsanaReadProvider::SCOPES.($send ? ' tasks:write' : '') : 'openid email '.($provider === 'gmail' ? 'https://www.googleapis.com/auth/gmail.readonly'.($send ? ' https://www.googleapis.com/auth/gmail.send' : '') : 'https://www.googleapis.com/auth/spreadsheets'.($send ? '' : '.readonly').' https://www.googleapis.com/auth/drive.metadata.readonly');
        $state = Str::random(64);
        $verifier = Str::random(96);
        Cache::put('team-ops-oauth:'.hash('sha256', $state), ['tenant' => $tenant->id, 'user' => $request->user()->id, 'area' => $area->id, 'provider' => $provider, 'scopes' => $scopes, 'send' => $send, 'session' => hash('sha256', $request->session()->getId()), 'verifier' => $verifier], now()->addMinutes(10));

        return ($provider === 'asana' ? 'https://app.asana.com/-/oauth_authorize' : 'https://accounts.google.com/o/oauth2/v2/auth').'?'.http_build_query(['client_id' => config($config.'.client_id'), 'redirect_uri' => route('team.operations.callback', ['provider' => $provider]), 'response_type' => 'code', 'scope' => $scopes, 'state' => $state, 'access_type' => 'offline', 'prompt' => 'consent', 'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')]);
    }

    public function callback(Request $request, string $provider): Tenant
    {
        $this->provider($provider);
        $input = $request->validate(['state' => 'required|string|max:256', 'code' => 'required|string|max:4096']);
        $key = 'team-ops-oauth:'.hash('sha256', $input['state']);
        $state = Cache::get($key);
        abort_unless(is_array($state) && $state['provider'] === $provider && (int) $state['user'] === (int) $request->user()->id && hash_equals($state['session'], hash('sha256', $request->session()->getId())), 403);
        Cache::forget($key);
        $tenant = Tenant::query()->findOrFail($state['tenant']);
        $area = app(OperationsAccess::class)->area($tenant, $request->user(), $state['area'], $state['send']);
        if ($provider === 'sheets') {
            app(OperationsAccess::class)->financial($tenant, $request->user());
        }
        $config = $provider === 'asana' ? 'team_work.asana' : 'team_operations.google';
        $response = Http::asForm()->timeout(20)->post($provider === 'asana' ? 'https://app.asana.com/-/oauth_token' : 'https://oauth2.googleapis.com/token', ['grant_type' => 'authorization_code', 'client_id' => config($config.'.client_id'), 'client_secret' => config($config.'.client_secret'), 'redirect_uri' => route('team.operations.callback', ['provider' => $provider]), 'code' => $input['code'], 'code_verifier' => $state['verifier']]);
        abort_unless($response->successful() && $response->json('access_token') && $response->json('refresh_token'), 502, 'Connection was not completed. Try connecting again.');
        $token = $response->json();
        if ($provider === 'asana') {
            $identity = $token['user'] ?? [];
        } else {
            $identityResponse = Http::withToken($token['access_token'])->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo');
            abort_unless($identityResponse->successful() && is_array($identityResponse->json()), 502, 'Google account identity could not be verified.');
            $identity = $identityResponse->json();
        }
        $account = (string) ($identity['gid'] ?? $identity['sub'] ?? '');
        abort_if($account === '', 502);
        if ($provider !== 'asana') {
            abort_unless(($identity['email_verified'] ?? false) === true, 403, 'Verify the Google account email first.');
        }
        if ($provider === 'gmail' && filled($area->settings['preset']['mailbox'] ?? null)) {
            abort_unless(strtolower($identity['email'] ?? '') === strtolower($area->settings['preset']['mailbox']), 403, 'Choose the mailbox configured for this business area.');
        }
        $granted = preg_split('/\s+/', trim($token['scope'] ?? $state['scopes']));
        $readScopes = match ($provider) {
            'gmail' => ['https://www.googleapis.com/auth/gmail.readonly'], 'sheets' => [], default => explode(' ', \App\Services\Team\AsanaReadProvider::SCOPES)
        };
        abort_unless(array_diff($readScopes, $granted) === [] && ($provider !== 'sheets' || count(array_intersect($granted, ['https://www.googleapis.com/auth/spreadsheets', 'https://www.googleapis.com/auth/spreadsheets.readonly'])) > 0), 403, 'Required source read consent was not granted.');
        $sendScope = match ($provider) {
            'asana' => 'tasks:write', 'gmail' => 'https://www.googleapis.com/auth/gmail.send', default => 'https://www.googleapis.com/auth/spreadsheets'
        };
        $state['send'] = $state['send'] && in_array($sendScope, $granted, true);
        $state['scopes'] = implode(' ', $granted);
        $connection = TeamProviderConnection::query()->firstOrNew(['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'area_id' => $area->id, 'provider' => $provider, 'account_key' => hash('sha256', $account)]);
        $connection->fill(['id' => $connection->id ?: (string) Str::uuid(), 'account_name' => $identity['email'] ?? $identity['name'] ?? 'Connected account', 'generation' => ($connection->generation ?? 0) + 1, 'status' => 'connected', 'credentials' => ['access_token' => $token['access_token'], 'refresh_token' => $token['refresh_token']], 'settings' => ['scopes' => $state['scopes'], 'send_authorized' => $state['send'], 'source' => $area->settings['preset'] ?? []], 'sync_state' => ['complete' => false, 'status' => 'selection_required'], 'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)), 'last_success_at' => null]);
        $connection->settings = [...$connection->settings, 'external_user_id' => $account];
        $connection->save();
        $request->session()->flash('team_operations.connected_area', $area->id);

        return $tenant;
    }

    private function provider(string $provider): void
    {
        abort_unless(in_array($provider, ['asana', 'gmail', 'sheets'], true), 404);
    }

    public function status(TeamProviderConnection $c): array
    {
        return ['id' => $c->id, 'area_id' => $c->area_id, 'provider' => $c->provider, 'account_name' => $c->account_name, 'status' => $c->status, 'generation' => $c->generation, 'source' => $c->settings['source'] ?? [], 'scopes' => $c->settings['scopes'] ?? '', 'capabilities' => ['read' => $c->status === 'connected', 'send' => ($c->settings['send_authorized'] ?? false) && config('team_operations.send_enabled'), 'create_event' => $c->provider === 'asana' && ($c->settings['send_authorized'] ?? false) && config('team_operations.asana_create_enabled')], 'sync' => $c->sync_state ?? [], 'last_success_at' => $c->last_success_at?->toIso8601String(), 'retry_at' => $c->retry_at?->toIso8601String()];
    }

    public function token(TeamProviderConnection $c): string
    {
        $fresh = $c->fresh();
        abort_unless($fresh && $fresh->status === 'connected' && $fresh->generation === $c->generation, 409, 'Reconnect this source.');
        if ($fresh->expires_at?->isAfter(now()->addSeconds(60))) {
            return $fresh->credentials['access_token'];
        }
        $result = DB::transaction(function () use ($c) {
            $current = TeamProviderConnection::query()->lockForUpdate()->findOrFail($c->id);
            abort_unless($current->status === 'connected' && $current->generation === $c->generation, 409);
            if ($current->expires_at?->isAfter(now()->addSeconds(60))) {
                return ['token' => $current->credentials['access_token']];
            }
            $config = $c->provider === 'asana' ? 'team_work.asana' : 'team_operations.google';
            $r = Http::asForm()->timeout(20)->post($c->provider === 'asana' ? 'https://app.asana.com/-/oauth_token' : 'https://oauth2.googleapis.com/token', ['grant_type' => 'refresh_token', 'client_id' => config($config.'.client_id'), 'client_secret' => config($config.'.client_secret'), 'refresh_token' => $current->credentials['refresh_token']]);
            if (! $r->successful() || ! $r->json('access_token')) {
                if (in_array($r->status(), [400, 401]) && in_array($r->json('error'), ['invalid_grant', 'invalid_token'], true)) {
                    $current->update(['status' => 'reconnect']);

                    return ['error' => 409];
                }
                if ($r->status() === 429) {
                    $raw = $r->header('Retry-After');
                    $delay = ctype_digit((string) $raw) ? max(1, (int) $raw) : max(60, (strtotime((string) $raw) ?: time() + 60) - time());
                    $current->update(['retry_at' => now()->addSeconds($delay)]);

                    return ['error' => 429, 'delay' => $delay];
                }

                return ['error' => 503];
            }
            $current->update(['credentials' => ['access_token' => $r->json('access_token'), 'refresh_token' => $r->json('refresh_token') ?: $current->credentials['refresh_token']], 'expires_at' => now()->addSeconds((int) ($r->json('expires_in') ?? 3600))]);

            return ['token' => $r->json('access_token')];
        });
        if (isset($result['error'])) {
            throw new HttpException($result['error'], $result['error'] === 409 ? 'Reconnect this source.' : 'Token refresh is temporarily unavailable. Wait before retrying.', null, isset($result['delay']) ? ['Retry-After' => (string) $result['delay']] : []);
        }

        return $result['token'];
    }

    public function http(TeamProviderConnection $c, string $method, string $url, array $parameters = []): array
    {
        if (strtoupper($method) === 'GET') {
            return $this->request($c, $method, $url, $parameters);
        }
        // Serialize provider mutations against disconnects and source changes. Never
        // let a database retry replay an external write after an uncertain response.
        $result = DB::transaction(function () use ($c, $method, $url, $parameters) {
            $current = TeamProviderConnection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->generation === $c->generation && $current->status === 'connected', 409);
            try {
                return ['data' => $this->request($c, $method, $url, $parameters)];
            } catch (HttpException $e) {
                return ['error' => $e];
            }
        }, 1);
        if (isset($result['error'])) {
            throw $result['error'];
        }

        return $result['data'];
    }

    private function request(TeamProviderConnection $c, string $method, string $url, array $parameters): array
    {
        $allowed = match ($c->provider) {
            'asana' => ['app.asana.com'], 'gmail' => ['gmail.googleapis.com'], 'sheets' => ['sheets.googleapis.com', 'www.googleapis.com'], default => []
        };
        abort_unless(parse_url($url, PHP_URL_SCHEME) === 'https' && in_array(parse_url($url, PHP_URL_HOST), $allowed, true), 422);
        abort_if($c->fresh()?->retry_at?->isFuture(), 429, 'This source is cooling down. Wait before retrying.');
        $token = $this->token($c);
        try {
            $r = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(25)->send($method, $url, [strtoupper($method) === 'GET' ? 'query' : 'json' => $parameters]);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new HttpException(503, 'Source unavailable. Reads may be retried; writes require reconciliation.');
        }
        abort_unless(TeamProviderConnection::query()->whereKey($c->id)->where('generation', $c->generation)->where('status', 'connected')->exists(), 409, 'Connection changed; response discarded.');
        if ($r->status() === 429) {
            $raw = $r->header('Retry-After');
            $delay = ctype_digit((string) $raw) ? max(1, (int) $raw) : max(60, (strtotime((string) $raw) ?: time() + 60) - time());
            $c->update(['retry_at' => now()->addSeconds($delay)]);
            throw new HttpException(429, 'Source rate limit. Wait before retrying.', null, ['Retry-After' => (string) $delay]);
        }
        if ($r->status() === 401) {
            $c->update(['status' => 'reconnect']);
            abort(409, 'Reconnect this source.');
        }
        abort_if(in_array($r->status(), [403, 404]), 403, 'Source access is unavailable. Protected content is hidden.');
        abort_unless($r->successful() && is_array($r->json()), 502, 'Source could not return a valid result.');
        abort_unless(TeamProviderConnection::query()->whereKey($c->id)->where('generation', $c->generation)->where('status', 'connected')->exists(), 409, 'Connection changed; response discarded.');

        return $r->json();
    }

    public function asana(TeamProviderConnection $c, string $path, array $query = []): array
    {
        return $this->http($c, 'GET', 'https://app.asana.com/api/1.0/'.$path, $query);
    }

    public function verifyAsanaRecord(TeamProviderConnection $c, string $id, string $project): array
    {
        abort_unless(ctype_digit($id) && in_array($project, $this->selectedProjects($c), true), 403);
        $task = $this->asana($c, 'tasks/'.$id, ['opt_fields' => 'gid,name,notes,modified_at,workspace.gid,memberships.project.gid'])['data'] ?? [];
        abort_unless((string) ($task['gid'] ?? '') === $id && (string) ($task['workspace']['gid'] ?? '') === (string) ($c->settings['source']['asana_workspace'] ?? '') && collect($task['memberships'] ?? [])->contains(fn ($m) => (string) ($m['project']['gid'] ?? '') === $project), 403);

        return $task;
    }

    public function selectedProjects(TeamProviderConnection $c): array
    {
        $source = $c->settings['source'] ?? [];
        $ids = array_values(array_unique(array_filter(array_map('strval', [$source['asana_project'] ?? '', ...($source['asana_projects'] ?? [])]))));
        abort_unless(count($ids) <= 1000 && collect($ids)->every(fn ($id) => ctype_digit($id)), 422, 'Choose valid Asana projects.');

        return $ids;
    }

    public function verifyAsanaProject(TeamProviderConnection $c, string $id): array
    {
        abort_unless(in_array($id, $this->selectedProjects($c), true), 403);
        $project = $this->asana($c, 'projects/'.$id, ['opt_fields' => 'gid,name,notes,modified_at,workspace.gid'])['data'] ?? [];
        abort_unless((string) ($project['gid'] ?? '') === $id && (string) ($project['workspace']['gid'] ?? '') === (string) ($c->settings['source']['asana_workspace'] ?? ''), 403);

        return $project;
    }

    public function read(TeamProviderConnection $c, string $resource, ?string $cursor = null): array
    {
        $source = $c->settings['source'] ?? [];
        $offset = null;
        $extra = [];
        $sourceHash = hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
        if ($cursor) {
            try {
                $payload = \Illuminate\Support\Facades\Crypt::decrypt($cursor);
            } catch (\Throwable) {
                abort(422, 'Invalid cursor.');
            } abort_unless($payload['connection'] === $c->id && $payload['generation'] === $c->generation && $payload['resource'] === $resource && ($payload['source_hash'] ?? '') === $sourceHash && $payload['expires'] > time(), 422, 'This cursor no longer belongs to this source.');
            $offset = $payload['offset'];
        }
        $pageIdentity = hash('sha256', json_encode($offset, JSON_THROW_ON_ERROR));
        if ($c->provider === 'asana') {
            $workspace = (string) ($source['asana_workspace'] ?? '');
            abort_unless($resource === 'workspaces' || ctype_digit($workspace), 422, 'Select an Asana workspace.');
            if ($resource === 'workspaces') {
                $data = $this->asana($c, 'workspaces', ['limit' => 100, 'offset' => $offset]);
            } elseif ($resource === 'catalog') {
                $data = $this->asana($c, 'workspaces/'.$workspace.'/projects', ['limit' => 100, 'offset' => $offset, 'archived' => ($source['include_archived'] ?? false) ? null : 'false', 'opt_fields' => 'gid,name,archived']);
            } else {
                $projects = $this->selectedProjects($c);
                $index = is_array($offset) ? (int) $offset['project_index'] : 0;
                $taskOffset = is_array($offset) ? $offset['task_offset'] : $offset;
                abort_unless($resource === 'records' && isset($projects[$index]), 422, 'Select a project first.');
                $projectId = $projects[$index];
                $project = $this->verifyAsanaProject($c, $projectId);
                $data = $this->asana($c, 'projects/'.$projectId.'/tasks', ['limit' => 100, 'offset' => $taskOffset, 'opt_fields' => 'gid,name,notes,modified_at,completed,due_on,start_on,assignee.gid,memberships.project.gid,memberships.section.name']);
                $extra['project'] = $project;
                $extra['project_id'] = $projectId;
            }
            $items = $data['data'] ?? [];
            $next = $data['next_page']['offset'] ?? null;
            if ($resource === 'records') {
                $next = $next ? ['project_index' => $index, 'task_offset' => $next] : (isset($projects[$index + 1]) ? ['project_index' => $index + 1, 'task_offset' => null] : null);
            }
        } elseif ($c->provider === 'gmail') {
            abort_unless($resource === 'records', 422);
            $history = $c->sync_state['gmail_history_id'] ?? null;
            $full = ! $history || empty($c->sync_state['gmail_reconciled_at']) || \Carbon\CarbonImmutable::parse($c->sync_state['gmail_reconciled_at'])->lt(now()->subMinutes(5));
            if (is_array($offset)) {
                $full = $offset['full'];
                $offset = $offset['token'];
            }
            if (! $full) {
                try {
                    $data = $this->http($c, 'GET', 'https://gmail.googleapis.com/gmail/v1/users/me/history', ['maxResults' => 100, 'pageToken' => $offset, 'startHistoryId' => $history, 'historyTypes' => 'messageAdded']);
                } catch (HttpException $e) {
                    if ($e->getStatusCode() !== 403) {
                        throw $e;
                    } $full = true;
                    $offset = null;
                }
            }
            if ($full) {
                if (! $offset) {
                    $extra['gmail_history_id'] = $this->http($c, 'GET', 'https://gmail.googleapis.com/gmail/v1/users/me/profile')['historyId'] ?? null;
                }
                $data = $this->http($c, 'GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages', ['maxResults' => 100, 'pageToken' => $offset, 'q' => 'after:'.($source['after'] ?? now()->subDays(90)->toDateString()).' -in:spam -in:trash']);
                $items = $data['messages'] ?? [];
                $extra['gmail_reconciliation'] = true;
            } else {
                $items = collect($data['history'] ?? [])->flatMap(fn ($h) => $h['messagesAdded'] ?? [])->pluck('message')->filter()->unique('id')->values()->all();
                $extra['gmail_history_id'] = $data['historyId'] ?? $history;
            }
            $next = isset($data['nextPageToken']) ? ['full' => $full, 'token' => $data['nextPageToken']] : null;
        } else {
            abort_unless($resource === 'records' && preg_match('/^[A-Za-z0-9_-]{10,160}$/D', $source['spreadsheet_id'] ?? ''), 422, 'Select a spreadsheet.');
            $tabs = $source['tabs'] ?? [];
            abort_unless(count($tabs) > 0 && count($tabs) <= 10, 422, 'Select up to ten mapped tabs.');
            $ranges = array_map(fn ($tab) => "'".str_replace("'", "''", $tab)."'!A:ZZ", $tabs);
            $items = [];
            foreach ($ranges as $range) {
                $items[] = $this->http($c, 'GET', 'https://sheets.googleapis.com/v4/spreadsheets/'.$source['spreadsheet_id'].'/values/'.rawurlencode($range), ['valueRenderOption' => 'UNFORMATTED_VALUE']);
            }
            $next = null;
        }

        return [...$extra, 'items' => $items, 'has_more' => $next !== null, 'page_identity' => $pageIdentity, 'next_cursor' => $next ? \Illuminate\Support\Facades\Crypt::encrypt(['connection' => $c->id, 'generation' => $c->generation, 'resource' => $resource, 'source_hash' => $sourceHash, 'offset' => $next, 'expires' => time() + 3600]) : null, 'complete' => $next === null, 'source' => ['provider' => $c->provider, 'connection_id' => $c->id, 'generation' => $c->generation], 'observed_at' => now()->toIso8601String()];
    }
}
