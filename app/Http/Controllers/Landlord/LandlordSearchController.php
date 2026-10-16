<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Search\GlobalSearchRequest;
use App\Services\Navigation\NavigationQuery;
use App\Services\Search\LandlordSearchCoordinator;
use Illuminate\Http\JsonResponse;

class LandlordSearchController extends Controller
{
    public function __invoke(GlobalSearchRequest $request, LandlordSearchCoordinator $search): JsonResponse
    {
        $navigationOnly = $request->validated('intent') === 'navigate';
        $navigation = app(NavigationQuery::class);
        $query = (string) $request->validated('q', '');
        if ($navigationOnly && ($control = $navigation->control($query))) {
            return response()->json(['query' => $query, 'results' => [], 'groups' => [], 'total' => 0, 'navigation' => ['status' => 'control', 'control' => $control]]);
        }
        $payload = $search->search(
            $navigationOnly ? $navigation->normalize($query) : $query,
            [
                'user' => $request->user(),
                'request' => $request,
                'limit' => $request->validated('limit', 12),
                'navigation_only' => $navigationOnly,
            ]
        );
        if ($navigationOnly) {
            $payload['total'] = count($payload['results']);
            $payload['groups'] = collect($payload['results'])->groupBy('type')->all();
            $payload['navigation'] ??= $navigation->resolution($payload['results']);
        }

        return response()->json($payload);
    }
}
