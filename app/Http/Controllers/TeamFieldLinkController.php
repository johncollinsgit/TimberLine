<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Team\TeamFieldWorkAdapter;
use DomainException;
use Illuminate\Http\Request;

class TeamFieldLinkController extends Controller
{
    public function __construct(private TeamFieldWorkAdapter $adapter) {}

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    public function jobs(Request $request, string $id)
    {
        return response()->json(['jobs' => $this->adapter->jobsForTask($request->user(), $this->tenant($request), $id)])->header('Cache-Control', 'private, no-store');
    }

    public function tasks(Request $request, string $job)
    {
        $input = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->adapter->tasksForJob($request->user(), $this->tenant($request), (int) $job, (int) ($input['page'] ?? 1)))->header('Cache-Control', 'private, no-store');
    }

    public function link(Request $request, string $id, string $job)
    {
        $input = $request->validate(['expected_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid'], 'remove' => ['sometimes', 'boolean']]);
        try {
            return response()->json($this->adapter->link($request->user(), $this->tenant($request), $id, (int) $job, $input))->header('Cache-Control', 'private, no-store');
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage(), 'saved' => false], match ($e->getMessage()) {
                'conflict', 'idempotency_mismatch' => 409, 'access_denied' => 403, default => 422
            });
        }
    }
}
