<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\MobileLandlordAccessService;
use App\Services\Mobile\TenantMobileLandlordService;
use App\Services\Mobile\TenantMobileSupportService;
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

    public function tickets(Request $request, MobileLandlordAccessService $access, TenantMobileSupportService $support): JsonResponse
    {
        $access->authorize($request->user());
        $validated = $request->validate(['status' => ['nullable', 'in:open,in_progress,waiting_on_tenant,resolved,closed,all']]);

        return response()->json($support->landlordIndex((string) ($validated['status'] ?? 'open')));
    }

    public function ticket(Request $request, int $ticket, MobileLandlordAccessService $access, TenantMobileSupportService $support): JsonResponse
    {
        $access->authorize($request->user());

        return response()->json($support->landlordShow($ticket));
    }

    public function triageTicket(Request $request, int $ticket, MobileLandlordAccessService $access, TenantMobileSupportService $support): JsonResponse
    {
        $actor = $access->authorize($request->user());
        $validated = $request->validate(['status' => ['sometimes', 'in:open,in_progress,waiting_on_tenant,resolved,closed'], 'priority' => ['sometimes', 'in:low,normal,high,urgent'], 'assign_to_me' => ['sometimes', 'boolean']]);

        return response()->json($support->triage($ticket, $actor, $validated));
    }

    public function replyTicket(Request $request, int $ticket, MobileLandlordAccessService $access, TenantMobileSupportService $support): JsonResponse
    {
        $actor = $access->authorize($request->user());
        $validated = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $detail = $support->landlordShow($ticket);

        return response()->json($support->reply((int) data_get($detail, 'ticket.tenant_id'), $ticket, $actor, $validated['body'], 'landlord'));
    }
}
