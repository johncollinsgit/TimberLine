<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Team\Operations\TeamsSetup;
use Illuminate\Http\Request;

class TeamSetupController extends Controller
{
    public function __invoke(Request $request, TeamsSetup $setup)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        if ($request->isMethod('post') && $request->is('api/*')) {
            abort_unless($request->header('Idempotency-Key') === $request->input('request_id'), 422, 'The command ID must match Idempotency-Key.');
        }
        $result = match ($request->route('action', 'status')) {
            'status' => $setup->status($tenant, $request->user()),
            'draft' => $setup->saveDraft($tenant, $request->user(), $request->all()),
            'finish' => $setup->finish($tenant, $request->user(), $request->all()),
            default => abort(404),
        };

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
