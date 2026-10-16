<?php

namespace App\Http\Controllers;

use App\Http\Requests\Search\GlobalSearchRequest;
use App\Services\Navigation\NavigationQuery;
use App\Services\Search\GlobalSearchCoordinator;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use Illuminate\Http\JsonResponse;

class GlobalSearchController extends Controller
{
    public function __construct(
        protected AuthenticatedTenantContextResolver $tenantContextResolver,
        protected GlobalSearchCoordinator $searchCoordinator
    ) {}

    public function index(GlobalSearchRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $tenant = $this->tenantContextResolver->resolveForRequest($request, $user);
        $navigationOnly = $request->validated('intent') === 'navigate';
        $navigation = app(NavigationQuery::class);
        $query = (string) $request->query('q', '');
        if ($navigationOnly && ($control = $navigation->control($query))) {
            return response()->json(['query' => $query, 'results' => [], 'groups' => [], 'total' => 0, 'navigation' => ['status' => 'control', 'control' => $control]]);
        }

        $payload = $this->searchCoordinator->search(
            $navigationOnly ? $navigation->normalize($query) : $query,
            [
                'tenant_id' => $tenant?->id,
                'user' => $user,
                'request' => $request,
                'surface' => 'marketing',
                'limit' => $request->validated('limit', 10),
                'navigation_only' => $navigationOnly,
            ]
        );
        if ($navigationOnly) {
            $payload['navigation'] = $navigation->resolution($payload['results']);
        }

        return response()->json($payload);
    }
}
