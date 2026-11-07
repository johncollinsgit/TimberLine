<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeamOperationsController;
use App\Models\Tenant;
use App\Services\Team\Operations\OperationsAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\PersonalAccessToken;

/** Transport adapter: native Teams uses the same operations and source ACLs as web. */
class TeamsCompanionController extends Controller
{
    public function __invoke(Request $request, TeamOperationsController $operations)
    {
        $action = $request->route('action');
        if ($action === 'lease') {
            $tenant = $request->attributes->get('current_tenant');
            $token = $request->user()->currentAccessToken();
            abort_unless($tenant instanceof Tenant && $token instanceof PersonalAccessToken, 403);
            $input = $request->validate(['device_id' => 'required|uuid']);
            app(OperationsAccess::class)->actor($tenant, $request->user());
            $owner = ['tenant_id' => (string) $tenant->id, 'user_id' => (string) $request->user()->id, 'device_id' => $input['device_id'], 'session_id' => hash('sha256', $token->id.':'.$token->created_at?->toIso8601String())];
            $expires = min(now()->addDay()->timestamp, $token->expires_at?->timestamp ?? PHP_INT_MAX);
            $lease = [...$owner, 'issued_at' => now()->timestamp, 'expires_at' => $expires];

            return response()->json(['contract_version' => 2, 'owner' => $owner, 'issued_at' => $lease['issued_at'], 'expires_at' => $expires, 'lease' => Crypt::encrypt($lease), 'notice' => 'Offline reading lasts at most 24 hours. Every synchronized command rechecks current permissions.'])->header('Cache-Control', 'private, no-store');
        }

        return match ($action) {
            'areas' => $request->isMethod('post') ? $operations->createArea($request) : $operations->areas($request),
            'records' => $operations->records($request),
            'record' => $operations->record($request, $request->route('id')),
            'weather' => $operations->weather($request, $request->route('id')),
            'sales' => $operations->sales($request, $request->route('id')),
            'prefill' => $operations->prefill($request),
            default => abort(404),
        };
    }
}
