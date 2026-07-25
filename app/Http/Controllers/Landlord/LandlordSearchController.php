<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Search\GlobalSearchRequest;
use App\Services\Search\LandlordSearchCoordinator;
use Illuminate\Http\JsonResponse;

class LandlordSearchController extends Controller
{
    public function __invoke(GlobalSearchRequest $request, LandlordSearchCoordinator $search): JsonResponse
    {
        return response()->json($search->search(
            (string) $request->query('q', ''),
            [
                'user' => $request->user(),
                'request' => $request,
                'limit' => $request->validated('limit', 12),
            ]
        ));
    }
}
