<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Versioned Teams-side planning only. Never writes Asana or changes frozen budgets. */
class EventTravelSettings
{
    public function __construct(private OperationsAccess $access, private OperationsWorkspace $workspace, private EventEssentials $essentials, private EventTravelPlan $plan) {}

    public function save(Tenant $tenant, User $user, TeamOperationRecord $event, array $input): array
    {
        $this->access->financial($tenant, $user);
        $this->access->record($tenant, $user, $event);
        abort_unless($event->kind === 'event', 404);
        $input = Validator::make($input, ['request_id' => 'required|uuid', 'expected_event_version' => 'required|integer|min:1', 'expected_area_version' => 'required|integer|min:1',
            'reviewed' => 'required|accepted', 'home_base' => 'sometimes|array:address,timezone', 'home_base.address' => 'required_with:home_base|string|min:3|max:1000', 'home_base.timezone' => 'required_with:home_base|timezone:all',
            'outbound_minutes' => 'present|nullable|integer|min:1|max:2880', 'return_minutes' => 'present|nullable|integer|min:1|max:2880', 'reason' => 'sometimes|string|min:5|max:1000'])->validate();
        $hash = hash('sha256', json_encode([$event->id, $input], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenant, $user, $event, $input, $hash) {
            DB::table('team_work_locks')->insertOrIgnore(['tenant_id' => $tenant->id]);
            DB::table('team_work_locks')->where('tenant_id', $tenant->id)->lockForUpdate()->first();
            $event = TeamOperationRecord::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($event->id);
            $this->access->financial($tenant, $user);
            $this->access->record($tenant, $user, $event);
            $area = TeamArea::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($event->area_id);
            $receipt = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'event.travel' && hash_equals($receipt->input_hash, $hash), 409, 'This request ID belongs to different travel details.');

                return $this->current($tenant, $user, $event, $area) + ['replayed' => true];
            }
            $sourceCurrent = $this->plan->sourceCurrent($event, $this->access->admittedAsanaVersion($tenant, $user, $event));
            abort_unless($sourceCurrent, 409, 'The event changed at its source. Refresh connected data before reviewing travel.');
            abort_unless($event->version === (int) $input['expected_event_version'] && $area->version === (int) $input['expected_area_version'], 409, 'The event or home-base settings changed. Reload and review the current trip before saving.');
            $settings = $area->settings ?? [];
            $before = ['home_base' => $this->plan->homeBase($settings, $tenant->accessProfile?->metadata ?? []), 'travel' => $event->data['_teams_enrichment']['travel'] ?? null];
            if (isset($input['home_base'])) {
                $settings['travel']['home_base'] = ['address' => trim($input['home_base']['address']), 'timezone' => $input['home_base']['timezone']];
                $area->update(['settings' => $settings, 'version' => $area->version + 1]);
            }
            $home = $this->plan->homeBase($settings, $tenant->accessProfile?->metadata ?? []);
            abort_unless($home['address'] && $home['timezone'], 422, 'Add your home-base address and timezone before reviewing drive times.');
            $view = $this->workspace->view($event, false, true);
            $address = $this->essentials->fields($view['data'])['address'] ?? null;
            abort_unless($address, 422, 'Add the event address before reviewing drive times.');
            $data = $event->data;
            $metadata = \App\Models\TenantAccessProfile::where('tenant_id', $tenant->id)->value('metadata') ?? [];
            $defaults = $settings['event_defaults'] ?? data_get($metadata, 'teams.event_defaults', []);
            $data['_teams_enrichment']['travel'] = ['origin_address' => $home['address'], 'origin_timezone' => $home['timezone'], 'destination_address' => $address,
                'outbound_minutes' => $input['outbound_minutes'], 'return_minutes' => $input['return_minutes'], 'teardown_minutes' => $defaults['teardown_minutes'] ?? 60, 'arrival_buffer_minutes' => $defaults['arrival_buffer_minutes'] ?? 0, 'reviewed_by' => (string) $user->id, 'reviewed_at' => now()->toIso8601String(),
                'reason' => $input['reason'] ?? 'Drive times reviewed for this event.', 'source_version' => $data['source_version'] ?? null];
            $event->update(['data' => $data, 'version' => $event->version + 1]);
            $this->workspace->revision($event, $user->id);
            $result = $this->current($tenant, $user, $event, $area, $sourceCurrent);
            TeamOperationAttempt::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $user->id, 'record_id' => $event->id,
                'kind' => 'event.travel', 'status' => 'accepted', 'request_id' => $input['request_id'], 'input_hash' => $hash,
                'payload' => ['before' => $before, 'after' => ['home_base' => $home, 'travel' => $data['_teams_enrichment']['travel']]], 'result' => $result]);

            return $result + ['replayed' => false];
        });
    }

    private function current(Tenant $tenant, User $user, TeamOperationRecord $event, TeamArea $area, ?bool $sourceCurrent = null): array
    {
        $home = $this->plan->homeBase($area->settings ?? [], $tenant->accessProfile?->metadata ?? []);
        $view = $this->workspace->view($event, false, true);
        $address = $this->essentials->fields($view['data'])['address'] ?? null;

        return ['event_version' => $event->version, 'area_version' => $area->version,
            'travel' => $this->plan->calculate($home, $address, $view['data']['event_schedule'], $event->data['_teams_enrichment']['travel'] ?? [], $event->data['source_version'] ?? null, $sourceCurrent ?? $this->plan->sourceCurrent($event, $this->access->admittedAsanaVersion($tenant, $user, $event)))];
    }
}
