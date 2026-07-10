<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\MobileLandlordAccessService;
use App\Services\Mobile\TenantMobileLandlordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EverbranchMobileLandlordController extends Controller
{
    public function bootstrap(Request $request, MobileLandlordAccessService $access, TenantMobileLandlordService $landlord): JsonResponse
    {
        $access->authorize($request->user());

        return response()->json($landlord->bootstrap());
    }

    public function tenants(Request $request, MobileLandlordAccessService $access, TenantMobileLandlordService $landlord): JsonResponse
    {
        $access->authorize($request->user());
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:120']]);

        return response()->json($landlord->tenants((string) ($validated['q'] ?? '')));
    }

    public function tenant(Request $request, int $tenant, MobileLandlordAccessService $access, TenantMobileLandlordService $landlord): JsonResponse
    {
        $access->authorize($request->user());

        return response()->json($landlord->tenant($tenant));
    }

    public function decideAccess(Request $request, int $accessRequest, MobileLandlordAccessService $access, TenantMobileLandlordService $landlord): JsonResponse
    {
        $actor = $access->authorize($request->user());
        $validated = $request->validate(['action' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:1000']]);

        return response()->json($landlord->decideAccessRequest($accessRequest, $validated['action'], $actor, $validated['note'] ?? null));
    }

    public function updateInquiry(Request $request, int $inquiry, MobileLandlordAccessService $access, TenantMobileLandlordService $landlord): JsonResponse
    {
        $actor = $access->authorize($request->user());
        $validated = $request->validate(['status' => ['required', 'in:new,contacted,qualified,archived']]);

        return response()->json($landlord->updateInquiry($inquiry, $validated['status'], $actor));
    }
}
