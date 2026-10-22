<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\AiBranch\AiWorkspaceMemory;
use App\Services\AiBranch\AiWorkspaceWorkflows;
use Illuminate\Http\Request;

/** The web session and native bearer adapter share the exact same commands. */
class AiWorkspaceWorkflowController extends Controller
{
    private function workspace(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 404);
        app(AiWorkspaceWorkflows::class)->authorize($tenant, $request->user());

        return $tenant;
    }

    public function customers(Request $request)
    {
        $tenant = $this->workspace($request);
        $data = $request->validate(['query' => 'required|string|min:2|max:100']);

        return response()->json(['customers' => app(AiWorkspaceWorkflows::class)->customers($tenant, $request->user(), $data['query'])])->header('Cache-Control', 'no-store, private');
    }

    public function memory(Request $request)
    {
        $tenant = $this->workspace($request);
        $data = $request->validate(['body' => 'required|string|max:500', 'confirmed' => 'required|accepted']);

        return response()->json(['workspace_memories' => app(AiWorkspaceMemory::class)->change($tenant, $request->user(), $data['body'])], 201)->header('Cache-Control', 'no-store');
    }

    public function forget(Request $request)
    {
        $tenant = $this->workspace($request);

        return response()->json(['workspace_memories' => app(AiWorkspaceMemory::class)->change($tenant, $request->user(), null, $request->route('memory'))])->header('Cache-Control', 'no-store');
    }

    public function propose(Request $request)
    {
        $tenant = $this->workspace($request);
        $data = $request->validate(['request_id' => 'required|uuid', 'draft' => 'required|array']);
        $service = app(AiWorkspaceWorkflows::class);

        return response()->json(['action' => $service->present($service->propose($tenant, $request->user(), $data['draft'], $data['request_id']))], 201)->header('Cache-Control', 'no-store');
    }

    public function decision(Request $request)
    {
        $tenant = $this->workspace($request);
        $data = $request->validate(['digest' => 'required|string|size:64', 'decision' => 'required|in:approve,reject', 'confirmed' => 'required|accepted']);
        $service = app(AiWorkspaceWorkflows::class);
        $action = $service->decide($tenant, $request->user(), (int) $request->route('action'), $data['digest'], $data['decision'] === 'approve');

        return response()->json(['action' => $service->present($action)])->header('Cache-Control', 'no-store');
    }

    public function runAgent(Request $request)
    {
        $tenant = $this->workspace($request);
        $data = $request->validate(['request_id' => 'required|uuid']);
        $run = app(\App\Services\AiBranch\AiWorkspaceAgents::class)->run($tenant, $request->user(), $request->route('agent'), $data['request_id']);

        return response()->json(['run_id' => $run->id, 'status' => $run->status, 'conversation_id' => $run->result_id], 202)->header('Cache-Control', 'no-store');
    }

    public function pauseAgent(Request $request)
    {
        $tenant = $this->workspace($request);
        app(\App\Services\AiBranch\AiWorkspaceAgents::class)->pause($tenant, $request->user(), $request->route('agent'));

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }
}
