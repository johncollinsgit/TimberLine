<?php

namespace App\Http\Controllers;

use App\Http\Requests\Search\GlobalSearchRequest;
use App\Services\Search\GlobalSearchCoordinator;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use Illuminate\Http\JsonResponse;

class GlobalSearchController extends Controller
{
    public function __construct(
        protected AuthenticatedTenantContextResolver $tenantContextResolver,
        protected GlobalSearchCoordinator $searchCoordinator
    ) {
    }

    public function index(GlobalSearchRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $tenant = $this->tenantContextResolver->resolveForRequest($request, $user);

        return response()->json($this->searchCoordinator->search(
            (string) $request->query('q', ''),
            [
                'tenant_id' => $tenant?->id,
                'user' => $user,
                'request' => $request,
                'surface' => 'marketing',
                'limit' => $request->validated('limit', 10),
            ]
        ));
    }
}
