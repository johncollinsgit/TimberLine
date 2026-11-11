<?php

namespace App\Http\Controllers;

use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\CustomerCommerceLinkService;
use App\Services\Marketing\CustomerProfileAccess;
use App\Services\Marketing\CustomerSourceOwnershipReconciliation;
use App\Services\Marketing\LegacySystemAudienceTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerSourceReconciliationController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && app(CustomerProfileAccess::class)->allows($request->user(), $tenant)
            && app(CustomerCommerceLinkService::class)->canLink($request->user(), $tenant->id), 403);

        return $tenant;
    }

    public function index(Request $request, CustomerSourceOwnershipReconciliation $reconciliation, LegacySystemAudienceTemplates $templates)
    {
        $tenant = $this->tenant($request);
        $input = $request->validate(['after_id' => 'sometimes|integer|min:0']);
        $after = $input['after_id'] ?? 0;
        $batch = $reconciliation->batch($tenant->id, $request->user(), $after, 500);
        $sources = DB::table('marketing_profile_links')->where('tenant_id', $tenant->id)->select('source_type')->selectRaw('COUNT(*) AS linked')->groupBy('source_type')->orderBy('source_type')->get();
        $retained = DB::table('marketing_profile_links')->whereNull('tenant_id')->whereIn('marketing_profile_id', MarketingProfile::forTenantId($tenant->id)->select('id'))->count();
        $runs = MarketingImportRun::forTenantId($tenant->id)->whereIn('type', ['customer_source_ownership', 'legacy_audience_template', 'square_customers_sync', 'square_orders_sync', 'square_payments_sync', 'marketing_profiles_sync'])->latest('id')->limit(20)->get();

        return response()->view('customers.reconciliation', ['tenant' => $tenant, 'batch' => $batch, 'after' => $after, 'sources' => $sources,
            'retained' => $retained, 'runs' => $runs, 'templates' => $templates->available()])->header('Cache-Control', 'private, no-store');
    }

    public function restore(Request $request, CustomerSourceOwnershipReconciliation $service)
    {
        $tenant = $this->tenant($request);
        $input = $request->validate(['after_id' => 'required|integer|min:0', 'fingerprint' => 'required|string|size:64']);
        $result = $service->batch($tenant->id, $request->user(), $input['after_id'], 500, true, $input['fingerprint']);

        return redirect()->route('customers.reconciliation', ['tenant' => $tenant->slug, 'after_id' => $result['has_more'] ? $result['after_id'] : 0])
            ->with('toast', ['style' => 'success', 'message' => $result['restored'].' original links restored; '.$result['review_required'].' retained for review. Customers, permissions and rewards were preserved.']);
    }

    public function copyTemplate(Request $request, LegacySystemAudienceTemplates $service)
    {
        $tenant = $this->tenant($request);
        $input = $request->validate(['source_id' => 'required|integer|min:1', 'fingerprint' => 'required|string|size:64']);
        $service->copy($tenant->id, $request->user(), $input['source_id'], $input['fingerprint']);

        return redirect()->route('customers.reconciliation', ['tenant' => $tenant->slug])->with('toast', ['style' => 'success', 'message' => 'Starter audience copied as a workspace draft. The original definition is retained. No messages were sent.']);
    }
}
