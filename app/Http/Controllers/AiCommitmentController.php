<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\AiBranch\AiCommitments;
use Illuminate\Http\Request;

class AiCommitmentController extends Controller
{
    private function workspace(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    public function sources(Request $request, string $tenant = '')
    {
        $data = $request->validate(['job_id' => 'required|integer|min:1']);

        return response()->json(app(AiCommitments::class)->sources($this->workspace($request), $request->user(), (int) $data['job_id']))->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request, string $tenant = '')
    {
        $data = $request->validate(['view' => 'sometimes|in:pending,overdue,completed', 'after' => 'sometimes|integer|min:0']);

        return response()->json(app(AiCommitments::class)->listing($this->workspace($request), $request->user(), $data['view'] ?? 'pending', (int) ($data['after'] ?? 0)))->header('Cache-Control', 'no-store, private');
    }

    public function propose(Request $request, string $tenant = '')
    {
        $action = app(AiCommitments::class)->propose($this->workspace($request), $request->user(), $request->all());

        return response()->json(['action_id' => $action->id, 'digest' => $action->digest, 'status' => $action->status], 201)->header('Cache-Control', 'no-store, private');
    }
}
