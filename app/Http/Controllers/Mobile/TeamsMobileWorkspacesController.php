<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Team\Operations\OperationsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TeamsMobileWorkspacesController extends Controller
{
    public function __invoke(Request $request, OperationsAccess $access): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->is_active === true && $user->email_verified_at !== null, 403);
        abort_unless(config('team_work.enabled') && config('team_work.premium_enabled') && config('team_operations.enabled'), 404);

        $workspaces = $user->tenants()->wherePivot('membership_active', true)
            ->whereIn('tenants.id', config('team_work.tenant_allowlist', []))
            ->orderBy('tenants.name')->get(['tenants.id', 'tenants.name', 'tenants.slug'])
            ->flatMap(function (Tenant $tenant) use ($user, $access): array {
                try {
                    $actor = $access->actor($tenant, $user);
                } catch (HttpException $exception) {
                    if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                        throw $exception;
                    }

                    return [];
                }

                return [['id' => (int) $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'role' => $actor['role']]];
            })->values();

        return response()->json(['workspaces' => $workspaces, 'landlord_access' => false])
            ->header('Cache-Control', 'no-store, private');
    }
}
