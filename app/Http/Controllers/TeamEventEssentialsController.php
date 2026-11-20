<?php

namespace App\Http\Controllers;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Services\Team\Operations\EventEssentials;
use App\Services\Team\Operations\EventTravelSettings;
use Illuminate\Http\Request;

class TeamEventEssentialsController extends Controller
{
    public function __invoke(Request $request, EventEssentials $essentials, EventTravelSettings $travel)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $event = TeamOperationRecord::where('tenant_id', $tenant->id)->findOrFail($request->route('id'));
        if ($request->isMethod('post')) {
            if ($request->is('api/*')) {
                abort_unless($request->header('Idempotency-Key') === $request->input('request_id'), 422, 'The command ID must match Idempotency-Key.');
            }
            $result = $travel->save($tenant, $request->user(), $event, $request->all());
        } else {
            $input = $request->validate(['include_sales' => 'sometimes|boolean']);
            $result = $essentials->read($tenant, $request->user(), $event, (bool) ($input['include_sales'] ?? true));
            if (\App\Services\Marketing\TeamShowTextCampaigns::enabledFor($tenant->id) && $event->kind === 'event'
                && ! in_array($event->status, ['withdrawn', 'cancelled'], true)
                && app(\App\Services\Team\Studio\EventSalesAccess::class)->manager($tenant, $request->user())
                && app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($tenant->id, 'sms')) {
                $result['show_text_url'] = route('team.events.show-text', ['id' => $event->id, 'tenant' => $tenant->slug], false);
            }
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
