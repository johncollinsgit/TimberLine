<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Mobile\MobileProductVisualizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EverbranchMobileProductVisualizationController extends Controller
{
    public function index(Request $request, MobileProductVisualizationService $previews): JsonResponse
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 404);
        $validated = $request->validate(['cursor' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,17}$/']]);

        return response()->json($previews->page($tenant, $validated['cursor'] ?? null))->header('Cache-Control', 'private, no-store');
    }
}
