<?php

namespace App\Services\Team;

use App\Models\TeamUserConnection;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Per-viewer read projection. Never writes source work or imports native records. */
class AsanaReadProvider
{
    public const SCOPES = 'projects:read tasks:read sections:read users:read workspaces:read stories:read attachments:read custom_fields:read tags:read';

    public function connection(int $tenant, int $user): ?TeamUserConnection
    {
        return TeamUserConnection::query()->where(['tenant_id' => $tenant, 'user_id' => $user, 'provider' => 'asana'])->first();
    }

    public function begin(Request $request, Tenant $tenant): string
    {
        abort_unless(config('team_work.asana.client_id') && config('team_work.asana.client_secret'), 503, 'Asana connection setup is not available yet.');
        $state = Str::random(64);
        $verifier = Str::random(96);
        Cache::put('team-asana-state:'.hash('sha256', $state), ['tenant' => $tenant->id, 'user' => $request->user()->id, 'session' => hash('sha256', $request->session()->getId()), 'verifier' => $verifier], now()->addMinutes(10));

        return 'https://app.asana.com/-/oauth_authorize?'.http_build_query(['client_id' => config('team_work.asana.client_id'), 'redirect_uri' => route('team.asana.callback'), 'response_type' => 'code', 'scope' => self::SCOPES, 'state' => $state, 'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')]);
    }

    public function callback(Request $request): Tenant
    {
        abort_unless(config('team_work.asana_enabled'), 404);
        $input = $request->validate(['state' => ['required', 'string', 'max:256'], 'code' => ['required', 'string', 'max:4096']]);
        $key = 'team-asana-state:'.hash('sha256', $input['state']);
        $state = Cache::get($key);
        abort_unless(is_array($state) && (int) $state['user'] === (int) $request->user()->id && hash_equals($state['session'], hash('sha256', $request->session()->getId())), 403, 'Connection request expired or belongs to another session.');
        Cache::forget($key);
        $tenant = Tenant::query()->findOrFail($state['tenant']);
        app(TeamWorkAccess::class)->context($request->user(), $tenant);
        $response = Http::asForm()->timeout(20)->post('https://app.asana.com/-/oauth_token', ['grant_type' => 'authorization_code', 'client_id' => config('team_work.asana.client_id'), 'client_secret' => config('team_work.asana.client_secret'), 'redirect_uri' => route('team.asana.callback'), 'code' => $input['code'], 'code_verifier' => $state['verifier']]);
        abort_unless($response->successful() && $response->json('access_token') && $response->json('refresh_token'), 502, 'Asana could not complete the connection. Try connecting again.');
        $token = $response->json();
        $connection = $this->connection($tenant->id, $request->user()->id) ?? new TeamUserConnection(['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'provider' => 'asana']);
        // A new identity invalidates selections, cursors and in-flight requests.
        $connection->fill(['id' => (string) Str::uuid(), 'credentials' => ['access_token' => $token['access_token'], 'refresh_token' => $token['refresh_token']], 'external_user_id' => $token['user']['gid'] ?? null, 'account_name' => $token['user']['name'] ?? 'Asana account', 'project_ids' => [], 'status' => 'connected', 'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)), 'last_synced_at' => null]);
        $connection->save();

        return $tenant;
    }

    public function status(?TeamUserConnection $connection): array
    {
        return ['provider' => 'asana', 'connected' => $connection?->status === 'connected', 'status' => $connection?->status ?? 'disconnected', 'connection_id' => $connection?->id, 'account_name' => $connection?->account_name, 'selected_projects' => array_map('strval', array_keys($connection?->project_ids ?? [])), 'configured' => (bool) (config('team_work.asana.client_id') && config('team_work.asana.client_secret')), 'last_synced_at' => $connection?->last_synced_at?->toIso8601String(), 'capabilities' => ['read' => true, 'write' => false], 'refresh_seconds' => 60];
    }

    private function token(TeamUserConnection $connection): string
    {
        abort_unless($connection->status === 'connected', 409, 'Reconnect your Asana account.');
        if ($connection->expires_at?->isAfter(now()->addSeconds(30))) {
            return $connection->credentials['access_token'];
        }

        return DB::transaction(function () use ($connection): string {
            $current = TeamUserConnection::query()->lockForUpdate()->find($connection->id);
            abort_unless($current?->status === 'connected', 409, 'Reconnect your Asana account.');
            if ($current->expires_at?->isAfter(now()->addSeconds(30))) {
                return $current->credentials['access_token'];
            }
            $response = Http::asForm()->timeout(20)->post('https://app.asana.com/-/oauth_token', ['grant_type' => 'refresh_token', 'client_id' => config('team_work.asana.client_id'), 'client_secret' => config('team_work.asana.client_secret'), 'refresh_token' => $current->credentials['refresh_token']]);
            if (! $response->successful() || ! $response->json('access_token')) {
                // Commit status before returning an error outside the transaction.
                $current->update(['status' => 'reconnect']);
                Log::notice('team.asana.reconnect_required', ['tenant_id' => $current->tenant_id, 'user_id' => $current->user_id, 'reason' => 'refresh_rejected']);

                return '';
            }
            $current->update(['credentials' => ['access_token' => $response->json('access_token'), 'refresh_token' => $response->json('refresh_token') ?: $current->credentials['refresh_token']], 'expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 3600))]);

            return $current->credentials['access_token'];
        });
    }

    private function get(TeamUserConnection $connection, string $path, array $query = []): array
    {
        $token = $this->token($connection);
        abort_if($token === '', 409, 'Reconnect your Asana account.');
        $cooldown = Cache::get('team-asana-cooldown:'.$connection->id);
        if ($cooldown && $cooldown > time()) {
            throw new HttpException(429, 'Asana is busy. Refresh after the indicated delay.', null, ['Retry-After' => (string) ($cooldown - time())]);
        }
        $started = hrtime(true);
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(20)->get('https://app.asana.com/api/1.0/'.$path, $query);
        } catch (\Illuminate\Http\Client\ConnectionException $error) {
            Log::notice('team.asana.unavailable', ['tenant_id' => $connection->tenant_id, 'user_id' => $connection->user_id]);
            throw new HttpException(503, 'Asana is temporarily unavailable. Try refreshing shortly.');
        }
        Log::debug('team.asana.read', ['tenant_id' => $connection->tenant_id, 'user_id' => $connection->user_id, 'operation' => explode('/', $path)[0], 'status' => $response->status(), 'duration_ms' => (int) ((hrtime(true) - $started) / 1000000)]);
        if ($response->status() === 429) {
            $delay = max(1, min(3600, (int) $response->header('Retry-After') ?: 60));
            Cache::put('team-asana-cooldown:'.$connection->id, time() + $delay, $delay);
            throw new HttpException(429, 'Asana is busy. Refresh after the indicated delay.', null, ['Retry-After' => (string) $delay]);
        }
        if ($response->status() === 401) {
            $connection->update(['status' => 'reconnect']);
            abort(409, 'Reconnect your Asana account.');
        }
        abort_if(in_array($response->status(), [403, 404], true), 403, 'This Asana work is no longer accessible.');
        abort_unless($response->successful() && is_array($response->json('data')), 502, 'Asana could not return this work. Try refreshing.');
        abort_unless(TeamUserConnection::query()->whereKey($connection->id)->where('status', 'connected')->exists(), 409, 'The Asana connection changed. Refresh this page.');

        return $response->json();
    }

    private function gid(string $id): string
    {
        abort_unless(preg_match('/^\d{1,120}$/D', $id), 422);

        return $id;
    }

    private function cursor(TeamUserConnection $connection, string $resource, ?string $cursor): ?string
    {
        if (! $cursor) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, 'Invalid page cursor.');
        }
        abort_unless(($data['connection'] ?? '') === $connection->id && ($data['resource'] ?? '') === $resource && ($data['expires'] ?? 0) > time(), 422, 'Page cursor expired. Refresh this view.');

        return $data['offset'];
    }

    private function page(TeamUserConnection $connection, string $resource, array $response, array $items): array
    {
        $offset = $response['next_page']['offset'] ?? null;

        return ['items' => $items, 'next_cursor' => $offset ? Crypt::encryptString(json_encode(['connection' => $connection->id, 'resource' => $resource, 'offset' => $offset, 'expires' => time() + 600])) : null, 'has_more' => (bool) $offset, 'connection_id' => $connection->id, 'provider' => 'asana', 'fetched_at' => now()->toIso8601String(), 'capabilities' => ['read' => true, 'write' => false]];
    }

    public function workspaces(TeamUserConnection $connection, ?string $cursor): array
    {
        $resource = 'workspaces';
        $response = $this->get($connection, 'workspaces', ['limit' => 100, 'offset' => $this->cursor($connection, $resource, $cursor), 'opt_fields' => 'name']);

        return $this->page($connection, $resource, $response, $response['data']);
    }

    public function projects(TeamUserConnection $connection, string $workspace, ?string $cursor, bool $archived): array
    {
        $resource = 'projects:'.$workspace.':'.(int) $archived;
        $response = $this->get($connection, 'workspaces/'.$this->gid($workspace).'/projects', ['limit' => 100, 'archived' => $archived ? 'true' : 'false', 'offset' => $this->cursor($connection, $resource, $cursor), 'opt_fields' => 'name,workspace.gid,archived,color,default_view,permalink_url']);

        return $this->page($connection, $resource, $response, $response['data']);
    }

    public function select(TeamUserConnection $connection, array $ids): void
    {
        $selected = [];
        foreach (array_unique($ids) as $id) {
            $project = $this->get($connection, 'projects/'.$this->gid($id), ['opt_fields' => 'workspace.gid'])['data'];
            $selected[$id] = (string) ($project['workspace']['gid'] ?? '');
        }
        // Rotate the identity so old selection cursors and in-flight reads cannot survive scope changes.
        $connection->update(['id' => (string) Str::uuid(), 'project_ids' => $selected]);
    }

    public function project(TeamUserConnection $connection, string $id): array
    {
        abort_unless(array_key_exists($id, $connection->project_ids ?? []), 403);
        $project = $this->get($connection, 'projects/'.$this->gid($id), ['opt_fields' => 'name,notes,workspace.gid,archived,color,default_view,permalink_url'])['data'];
        abort_unless((string) ($project['workspace']['gid'] ?? '') === (string) $connection->project_ids[$id], 403);

        return $project;
    }

    public function sections(TeamUserConnection $connection, string $id, ?string $cursor): array
    {
        $this->project($connection, $id);
        $resource = 'sections:'.$id;
        $response = $this->get($connection, 'projects/'.$this->gid($id).'/sections', ['limit' => 100, 'offset' => $this->cursor($connection, $resource, $cursor), 'opt_fields' => 'name']);

        return $this->page($connection, $resource, $response, $response['data']);
    }

    public function tasks(TeamUserConnection $connection, string $projectId, ?string $cursor, bool $completed): array
    {
        $project = $this->project($connection, $projectId);
        $resource = 'tasks:'.$projectId.':'.(int) $completed;
        $query = ['limit' => 100, 'offset' => $this->cursor($connection, $resource, $cursor), 'opt_fields' => 'name,completed,assignee.gid,assignee.name,due_on,due_at,start_on,start_at,memberships.project.gid,memberships.section.gid,memberships.section.name,custom_fields.name,custom_fields.display_value,num_subtasks,modified_at,permalink_url'];
        if (! $completed) {
            $query['completed_since'] = now()->toIso8601String();
        }
        $response = $this->get($connection, 'projects/'.$projectId.'/tasks', $query);
        $items = array_map(fn ($item) => $this->normalize($item, $project['workspace']['gid'], [$projectId]), $response['data']);
        $connection->update(['last_synced_at' => now()]);

        return $this->page($connection, $resource, $response, $items) + ['project' => $project, 'external_user_id' => $connection->external_user_id];
    }

    private function normalize(array $task, string $workspace, array $allowedProjects): array
    {
        $task['memberships'] = array_values(array_filter($task['memberships'] ?? [], fn ($membership) => in_array((string) ($membership['project']['gid'] ?? ''), $allowedProjects, true)));
        unset($task['projects'], $task['parent'], $task['dependencies'], $task['followers']);

        return $task + ['source' => ['provider' => 'asana', 'workspace_id' => $workspace, 'id' => $task['gid']], 'key' => 'asana:'.$workspace.':'.$task['gid'], 'capabilities' => ['read' => true, 'write' => false]];
    }

    public function task(TeamUserConnection $connection, string $id): array
    {
        $task = $this->get($connection, 'tasks/'.$this->gid($id), ['opt_fields' => 'name,notes,completed,assignee.gid,assignee.name,due_on,due_at,start_on,start_at,workspace.gid,parent.gid,memberships.project.gid,memberships.section.gid,memberships.section.name,custom_fields.name,custom_fields.display_value,num_subtasks,modified_at,permalink_url'])['data'];
        $root = $task;
        $depth = 0;
        while (! array_intersect(array_keys($connection->project_ids ?? []), array_column(array_column($root['memberships'] ?? [], 'project'), 'gid')) && isset($root['parent']['gid']) && $depth++ < 20) {
            $root = $this->get($connection, 'tasks/'.$this->gid($root['parent']['gid']), ['opt_fields' => 'parent.gid,memberships.project.gid'])['data'];
        }
        $allowed = [];
        foreach ($root['memberships'] ?? [] as $membership) {
            $projectId = (string) ($membership['project']['gid'] ?? '');
            if (! array_key_exists($projectId, $connection->project_ids ?? [])) {
                continue;
            }
            try {
                $this->project($connection, $projectId);
                $allowed[] = $projectId;
            } catch (HttpException $error) {
                if ($error->getStatusCode() !== 403) {
                    throw $error;
                }
            }
        }
        abort_unless($allowed !== [], 403, 'This task is outside your accessible selected projects.');

        return $this->normalize($task, (string) $task['workspace']['gid'], $allowed) + ['connection_id' => $connection->id, 'fetched_at' => now()->toIso8601String()];
    }

    public function related(TeamUserConnection $connection, string $id, string $kind, ?string $cursor): array
    {
        $task = $this->task($connection, $id);
        abort_unless(in_array($kind, ['stories', 'subtasks', 'attachments'], true), 404);
        $fields = match ($kind) {
            'stories' => 'text,created_at,created_by.name,resource_subtype',
            'subtasks' => 'name,completed,due_on,assignee.name',
            default => 'name,permanent_url,resource_subtype',
        };
        $resource = $kind.':'.$id;
        $response = $this->get($connection, $kind === 'attachments' ? 'attachments' : 'tasks/'.$this->gid($id).'/'.$kind, array_filter(['parent' => $kind === 'attachments' ? $id : null, 'limit' => 100, 'offset' => $this->cursor($connection, $resource, $cursor), 'opt_fields' => $fields], fn ($value) => $value !== null));

        return $this->page($connection, $resource, $response, $response['data']);
    }
}
