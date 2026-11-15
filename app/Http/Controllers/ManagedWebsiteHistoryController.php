<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\TenantSitePage;
use App\Services\ManagedWebsite\ManagedWebsiteDraftHistory;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagedWebsiteHistoryController extends Controller
{
    public function index(Request $request, TenantSitePage $page, ManagedWebsiteDraftHistory $history, ManagedWebsiteService $websites): JsonResponse
    {
        $site = $this->site($request);
        abort_unless((int) $page->tenant_id === (int) $site->tenant_id && (int) $page->tenant_site_id === (int) $site->id, 404);
        abort_unless($websites->draftViewingEnabledFor($site->tenant), 423, 'Website preview is paused. Your saved history is retained.');

        return response()->json($history->payload($site, $page, $websites->editorEnabledFor($site->tenant)))->header('Cache-Control', 'private, no-store');
    }

    public function restorePage(Request $request, TenantSitePage $page, int $version, ManagedWebsiteDraftHistory $history, ManagedWebsiteService $websites): JsonResponse
    {
        $site = $this->site($request);
        abort_unless((int) $page->tenant_id === (int) $site->tenant_id && (int) $page->tenant_site_id === (int) $site->id, 404);
        abort_unless($websites->editorEnabledFor($site->tenant), 423, 'Private editing is paused. Your saved history is retained.');
        $data = $request->validate(['current_version_id' => ['required', 'integer', 'min:1']]);
        $restored = $history->restorePage($site, $page, $version, (int) $data['current_version_id'], $request->user());

        return response()->json(['status' => 'draft_restored', 'id' => $restored->id, 'version_number' => $restored->version_number])->header('Cache-Control', 'private, no-store');
    }

    public function restoreSite(Request $request, int $version, ManagedWebsiteDraftHistory $history, ManagedWebsiteService $websites): JsonResponse
    {
        $site = $this->site($request);
        abort_unless($websites->editorEnabledFor($site->tenant), 423, 'Private editing is paused. Your saved history is retained.');
        $data = $request->validate(['current_version_id' => ['required', 'integer', 'min:1']]);
        $restored = $history->restoreSite($site, $version, (int) $data['current_version_id'], $request->user());

        return response()->json(['status' => 'draft_restored', 'id' => $restored->id, 'version_number' => $restored->version_number])->header('Cache-Control', 'private, no-store');
    }

    private function site(Request $request): TenantSite
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();
        app(ManagedWebsiteDraftHistory::class)->assertActor($site, $request->user());
        abort_if(app(\App\Services\ManagedWebsite\ConnectedWebsiteService::class)->connected($site), 409, 'Use Website history for this connected site.');

        return $site;
    }
}
