<?php

namespace App\Http\Controllers;

use App\Models\TeamUserConnection;
use App\Models\Tenant;
use App\Services\Team\AsanaReadProvider;
use App\Services\Team\TeamWorkAccess;
use Illuminate\Http\Request;

class TeamAsanaController extends Controller
{
    public function __construct(private AsanaReadProvider $provider) {}

    private function scope(Request $request): Tenant
    {
        abort_unless(config('team_work.asana_enabled'), 404);
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        app(TeamWorkAccess::class)->context($request->user(), $tenant);

        return $tenant;
    }

    private function connection(Request $request): TeamUserConnection
    {
        $tenant = $this->scope($request);
        $connection = $this->provider->connection($tenant->id, $request->user()->id);
        abort_unless($connection && $connection->status === 'connected', 409, 'Connect your Asana account first.');
        // Binds requests to the connection displayed by this page, never a grant.
        abort_if($request->hasHeader('X-Team-Connection') && $request->header('X-Team-Connection') !== $connection->id, 409, 'Your connection changed. Refresh this page.');

        return $connection;
    }

    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function status(Request $request)
    {
        $tenant = $this->scope($request);

        return $this->json($this->provider->status($this->provider->connection($tenant->id, $request->user()->id)));
    }

    public function connect(Request $request)
    {
        return $this->json(['url' => $this->provider->begin($request, $this->scope($request))]);
    }

    public function callback(Request $request)
    {
        $tenant = $this->provider->callback($request);

        return redirect()->route('team.app', ['tenant' => $tenant->slug, 'asana' => 'connected']);
    }

    public function disconnect(Request $request)
    {
        $tenant = $this->scope($request);
        $this->provider->connection($tenant->id, $request->user()->id)?->delete();

        return $this->json(['status' => 'disconnected']);
    }

    public function projects(Request $request)
    {
        $input = $request->validate(['workspace' => ['required', 'string', 'regex:/^\d{1,120}$/D'], 'cursor' => ['nullable', 'string', 'max:4096'], 'archived' => ['nullable', 'boolean']]);

        return $this->json($this->provider->projects($this->connection($request), $input['workspace'], $input['cursor'] ?? null, $request->boolean('archived')));
    }

    public function workspaces(Request $request)
    {
        $input = $request->validate(['cursor' => ['nullable', 'string', 'max:4096']]);

        return $this->json($this->provider->workspaces($this->connection($request), $input['cursor'] ?? null));
    }

    public function selection(Request $request)
    {
        $input = $request->validate(['projects' => ['present', 'array', 'max:50'], 'projects.*' => ['required', 'string', 'regex:/^\d{1,120}$/D']]);
        $connection = $this->connection($request);
        $this->provider->select($connection, $input['projects']);

        return $this->json($this->provider->status($connection));
    }

    public function tasks(Request $request)
    {
        $input = $request->validate(['project' => ['required', 'string', 'regex:/^\d{1,120}$/D'], 'cursor' => ['nullable', 'string', 'max:4096'], 'completed' => ['nullable', 'boolean']]);

        return $this->json($this->provider->tasks($this->connection($request), $input['project'], $input['cursor'] ?? null, $request->boolean('completed')));
    }

    public function task(Request $request, string $id)
    {
        return $this->json($this->provider->task($this->connection($request), $id));
    }

    public function related(Request $request, string $id, string $kind)
    {
        $input = $request->validate(['cursor' => ['nullable', 'string', 'max:4096']]);

        return $this->json($this->provider->related($this->connection($request), $id, $kind, $input['cursor'] ?? null));
    }

    public function sections(Request $request, string $id)
    {
        $input = $request->validate(['cursor' => ['nullable', 'string', 'max:4096']]);

        return $this->json($this->provider->sections($this->connection($request), $id, $input['cursor'] ?? null));
    }
}
