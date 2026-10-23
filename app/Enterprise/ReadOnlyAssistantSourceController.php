<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

/** Exact current evidence, independent of bounded workspace lists. No work or provider action. */
class ReadOnlyAssistantSourceController
{
    public function __invoke(Request $request, string $kind, string $id, WorkspaceAccess $access, AuthorizedEvidence $evidence)
    {
        abort_unless(config('enterprise.read_only_assistant_enabled'), 404);
        $areaId = $request->validate(['area_id' => ['required', 'uuid']])['area_id'];
        $tenant = $request->attributes->get('current_tenant');
        $actor = $access->actor($tenant, $request->user());
        $area = $access->area($tenant, $actor, $areaId);
        $source = $evidence->capture($tenant, $request->user(), $areaId, $kind, $id);

        return response()->view('enterprise.read-only-assistant-source', [
            'title' => $source['title'], 'kind' => $kind, 'sourceId' => $id, 'facts' => $source['facts'], 'areaName' => $area->name,
            'sampleData' => (bool) config('enterprise.sample_data'), 'checkedAt' => now()->toIso8601String(),
            'workspaceUrl' => $source['workspace_url'],
        ])->header('Cache-Control', 'private, no-store');
    }
}
