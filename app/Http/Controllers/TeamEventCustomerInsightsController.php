<?php

namespace App\Http\Controllers;

use App\Models\MarketingCampaign;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Services\Marketing\CustomerMarketingHistory;
use App\Services\Marketing\EventCampaignResults;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Studio\EventPerformance;
use Illuminate\Http\Request;

class TeamEventCustomerInsightsController extends Controller
{
    public function __invoke(Request $request, string $id, ?int $campaign = null)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $event = TeamOperationRecord::where('tenant_id', $tenant->id)->where('kind', 'event')->findOrFail($id);
        $access = app(OperationsAccess::class);
        $access->financial($tenant, $request->user());
        $access->record($tenant, $request->user(), $event);
        $area = TeamArea::where('tenant_id', $tenant->id)->findOrFail($event->area_id);
        $marketing = app(CustomerMarketingHistory::class)->allowed($tenant->id, $request->user());
        if ($campaign !== null) {
            abort_unless($marketing && in_array($campaign, app(EventCampaignResults::class)->campaignIds($tenant->id, $event->id), true), 404);
            $run = MarketingCampaign::forTenantId($tenant->id)->findOrFail($campaign);

            return response()->view('team.campaign-results', ['tenant' => $tenant, 'event' => $event,
                'results' => app(EventCampaignResults::class)->forCampaign($run)])->header('Cache-Control', 'private, no-store');
        }
        $input = $request->validate(['rank_basis' => 'sometimes|in:event,lifetime', 'page' => 'sometimes|integer|min:1|max:100000']);
        $report = app(EventPerformance::class)->read($tenant, $request->user(), $area, ['area' => $area->id, 'group_by' => 'customer',
            'event_id' => $event->id, 'from' => $event->starts_on->toDateString(), 'to' => $event->ends_on->toDateString(),
            'sort' => 'net_sales', 'rank_basis' => $input['rank_basis'] ?? 'event', 'page' => $input['page'] ?? 1]);
        $results = $marketing ? app(EventCampaignResults::class)->forEvent($tenant->id, $event->id) : null;
        if ($results) {
            foreach ($results['campaigns'] as &$row) {
                $row['url'] = route('team.events.campaign-results', ['id' => $event->id, 'campaign' => $row['id'], 'tenant' => $tenant->slug], false);
            }
        }

        return response()->json(['report' => $report, 'marketing' => $results])->header('Cache-Control', 'private, no-store');
    }
}
