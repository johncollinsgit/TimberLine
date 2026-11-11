<?php

namespace App\Http\Controllers;

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\CustomerProfileAccess;
use App\Services\Marketing\EventCustomerCohort;
use App\Services\Marketing\SquareCustomerIdentityService;
use App\Services\Marketing\SquareCustomerSource;
use App\Services\Marketing\TenantCustomerLookup;
use Illuminate\Http\Request;

/** The same canonical customer directory used by Pay, with independent source/Marketing permissions. */
class ConnectedCustomersController extends Controller
{
    public function __invoke(Request $request)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && app(CustomerProfileAccess::class)->allows($request->user(), $tenant), 403);
        $input = $request->validate(['query' => ['nullable', 'string', 'min:2', 'max:120'],
            'event_id' => ['nullable', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'purchase_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $canManage = false;
        try {
            app(SquareCustomerIdentityService::class)->authorize($tenant->id, $request->user());
            $canManage = true;
        } catch (\Symfony\Component\HttpKernel\Exception $error) {
            if ($error->getStatusCode() !== 403) {
                throw $error;
            }
        }
        abort_if(isset($input['event_id']) && ! $canManage, 403);
        $detail = null;
        $profile = null;
        $squareSource = null;
        $purchasePage = $input['purchase_page'] ?? 1;
        $purchaseHasMore = false;
        $lookup = app(TenantCustomerLookup::class);
        if ($id = $request->route('profile')) {
            $detail = $lookup->details($tenant->id, (int) $id);
            $profile = MarketingProfile::forTenantId($tenant->id)->findOrFail($detail['customer']['id']);
            if ($purchasePage > 1) {
                $history = app(\App\Services\Marketing\CustomerCommerceSnapshotService::class)->forReportingProfile($profile, $tenant->id);
                $purchases = collect($history['purchases'])->slice(($purchasePage - 1) * 50, 50)->values();
                $purchaseHasMore = count($history['purchases']) > $purchasePage * 50 || $history['has_more'];
                $detail['commerce'] = [...$history, 'purchases' => $purchases->all(), 'confirmed_purchase_count' => $purchases->where('confirmed', true)->count(),
                    'unverified_record_count' => $purchases->where('confirmed', false)->count(), 'has_more' => $purchaseHasMore];
            } else {
                $purchaseHasMore = $detail['commerce']['has_more'];
            }
            $squareSource = $canManage ? app(SquareCustomerSource::class)->forStaff($profile, $tenant->id) : null;
        }
        $cohort = isset($input['event_id']) ? app(EventCustomerCohort::class)->read($tenant->id, $input['event_id']) : null;
        $results = ! $profile && (filled($input['query'] ?? null) || $cohort)
            ? $lookup->search($tenant->id, $input['query'] ?? '', $cohort['profile_ids'] ?? null, $input['page'] ?? 1) : null;
        $data = ['tenant' => $tenant, 'query' => $input, 'results' => $results, 'detail' => $detail,
            'profile' => $profile, 'squareSource' => $squareSource, 'canManage' => $canManage, 'purchasePage' => $purchasePage, 'purchaseHasMore' => $purchaseHasMore,
            'events' => $canManage ? app(EventCustomerCohort::class)->options($tenant->id) : [], 'context' => $cohort];

        return ($request->expectsJson() ? response()->json(['tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug],
            'customer' => $detail['customer'] ?? null, 'commerce' => $detail['commerce'] ?? null, 'results' => $results,
            'event_context' => $cohort ? array_diff_key($cohort, ['profile_ids' => true, 'relations' => true]) : null,
            'purchase_page' => $purchasePage, 'purchase_has_more' => $purchaseHasMore])
            : response()->view('customers.connected', $data))->header('Cache-Control', 'private, no-store');
    }
}
