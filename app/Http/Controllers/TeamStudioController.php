<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Studio\TeamsStudio;
use Illuminate\Http\Request;

class TeamStudioController extends Controller
{
    public function __invoke(Request $request)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $input = $request->validate(['area' => 'required|uuid']);
        $access = app(OperationsAccess::class);
        $area = $access->area($tenant, $request->user(), $input['area'], $request->isMethod('post'));
        $studio = app(TeamsStudio::class);
        $action = $request->route('action', 'workspace');
        if ($request->isMethod('post') && $request->is('api/*')) {
            abort_unless($request->header('Idempotency-Key') === $request->input('request_id') || $action === 'preview', 422, 'The command ID must match Idempotency-Key.');
        }
        $result = match ($action) {
            'preset' => $this->preset($tenant, $request, $area, $access),
            'workspace' => $studio->workspace($tenant, $request->user(), $area),
            'mapping' => $studio->reviewOccurrence($tenant, $request->user(), $area, $request->all()),
            'definition' => $studio->saveDefinition($tenant, $request->user(), $area, $request->all()),
            'draft' => $studio->saveDraft($tenant, $request->user(), $area, $request->all()),
            'preview' => $studio->preview($tenant, $request->user(), $area, $request->all()),
            'create' => $studio->createEvent($tenant, $request->user(), $area, $request->all()),
            default => abort(404),
        };

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    private function preset($tenant, Request $request, $area, OperationsAccess $access): array
    {
        $access->financial($tenant, $request->user());
        $configured = config('team_operations.presets.'.$tenant->slug.'.'.$area->profile.'.compensation_template') === 'modern_forestry_criteria';

        return ['policy' => $configured ? app(\App\Services\Team\Studio\ModernForestryPolicy::class)->draft() : ['name' => 'Event cost policy', 'currency' => 'USD', 'effective_on' => now()->toDateString(), 'required_categories' => [], 'requires_confirmation' => [], 'rules' => []]];
    }
}
