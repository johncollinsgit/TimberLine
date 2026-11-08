<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Services\Team\Studio\EventCostTypes;
use App\Services\Team\Studio\ModernForestryPolicy;
use App\Services\Team\Studio\TeamsStudio;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Minimal tenant-owned setup. Draft storage is encrypted; no provider, payroll or invite action occurs. */
class TeamsSetup
{
    public function __construct(private OperationsAccess $access, private OperationsWorkspace $workspace, private EventTravelPlan $travel) {}

    public function status(Tenant $tenant, User $user): array
    {
        $this->access->financial($tenant, $user);
        $profile = TenantAccessProfile::where('tenant_id', $tenant->id)->first();
        $metadata = $profile?->metadata ?? [];
        $state = data_get($metadata, 'teams.setup', []);
        $areas = TeamArea::where('tenant_id', $tenant->id)->orderBy('name')->get()->filter(function ($area) use ($tenant, $user) {
            try {
                $this->access->area($tenant, $user, $area->id);

                return true;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }

                return false;
            }
        })->values();
        $primary = $areas->firstWhere('id', $state['area_id'] ?? '') ?? $areas->firstWhere('profile', 'events') ?? $areas->first();
        $home = $this->travel->homeBase($primary?->settings ?? [], $metadata);
        $eventDefaults = $primary?->settings['event_defaults'] ?? data_get($metadata, 'teams.event_defaults', []);
        $profiles = collect(config('team_operations.profiles'))->filter(fn ($value, $key) => $key !== 'wholesale' || $this->access->wholesaleEligible($tenant))->map(fn ($value, $key) => ['key' => $key, 'name' => $value['name']])->values()->all();
        $members = $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->whereNotNull('users.email_verified_at')->orderBy('users.name')->get()->map(fn ($member) => ['id' => (string) $member->id, 'name' => $member->name])->all();
        $templates = ['skip', 'blank'];
        if (config('team_operations.presets.'.$tenant->slug.'.events.compensation_template') === 'modern_forestry_criteria') {
            $templates[] = 'modern_forestry';
        }

        return ['can_manage' => true, 'version' => (int) ($state['version'] ?? 0), 'completed_at' => $state['completed_at'] ?? null,
            'required' => $areas->isEmpty(), 'suggested' => $areas->isEmpty() || ! $home['address'] || ! $home['timezone'],
            'draft' => $this->draft($state), 'defaults' => ['home_base' => $home, 'event_defaults' => ['teardown_minutes' => $eventDefaults['teardown_minutes'] ?? 60, 'arrival_buffer_minutes' => $eventDefaults['arrival_buffer_minutes'] ?? 0],
                'area_id' => $primary?->id, 'member_ids' => $primary?->settings['default_member_ids'] ?? []],
            'choices' => ['areas' => $areas->map(fn ($area) => ['id' => $area->id, 'name' => $area->name, 'profile' => $area->profile, 'version' => $area->version])->all(), 'members' => $members, 'profiles' => $profiles, 'cost_templates' => $templates],
            'connections_optional' => true, 'notice' => 'Choose what your team needs. Connections and cost rules can be completed later.'];
    }

    public function saveDraft(Tenant $tenant, User $user, array $input): array
    {
        $this->access->financial($tenant, $user);
        $input = Validator::make($input, ['request_id' => 'required|uuid', 'expected_version' => 'required|integer|min:0', 'step' => 'required|integer|min:0|max:3', 'values' => 'required|array'])->validate();
        $values = $this->values($tenant, $input['values']);
        $hash = hash('sha256', json_encode([$input, $values], JSON_THROW_ON_ERROR));
        $replayed = DB::transaction(function () use ($tenant, $user, $input, $values, $hash) {
            $profile = $this->lock($tenant, $user);
            $metadata = $profile->metadata ?? [];
            $state = data_get($metadata, 'teams.setup', []);
            if (($state['last_draft']['request_id'] ?? null) === $input['request_id']) {
                abort_unless(($state['last_draft']['user_id'] ?? null) === (string) $user->id && hash_equals($state['last_draft']['hash'], $hash), 409, 'This setup request belongs to different input.');

                return true;
            }
            abort_unless((int) ($state['version'] ?? 0) === (int) $input['expected_version'], 409, 'Setup changed. Reload its saved draft before continuing.');
            $prior = $this->draft($state);
            $draft = ['step' => $input['step'], 'values' => array_replace($prior['values'] ?? [], $values)];
            $state['draft'] = Crypt::encryptString(json_encode($draft, JSON_THROW_ON_ERROR));
            $state['version'] = (int) ($state['version'] ?? 0) + 1;
            $state['last_draft'] = ['request_id' => $input['request_id'], 'user_id' => (string) $user->id, 'hash' => $hash];
            $state['updated_by'] = (string) $user->id;
            $state['updated_at'] = now()->toIso8601String();
            $metadata['teams']['setup'] = $state;
            $profile->update(['metadata' => $metadata]);

            return false;
        }, 3);

        return $this->status($tenant, $user) + ['replayed' => $replayed];
    }

    public function finish(Tenant $tenant, User $user, array $input): array
    {
        $this->access->financial($tenant, $user);
        $input = Validator::make($input, ['request_id' => 'required|uuid', 'expected_version' => 'required|integer|min:0', 'reviewed' => 'required|accepted'])->validate();
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $completion = DB::transaction(function () use ($tenant, $user, $input, $hash) {
            $profile = $this->lock($tenant, $user);
            $prior = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($prior) {
                abort_unless($prior->kind === 'teams.setup' && hash_equals($prior->input_hash, $hash), 409, 'This command ID belongs to a different setup.');
                $this->access->area($tenant, $user, $prior->area_id);

                return $prior->result + ['replayed' => true];
            }
            $metadata = $profile->metadata ?? [];
            $state = data_get($metadata, 'teams.setup', []);
            abort_unless((int) ($state['version'] ?? 0) === (int) $input['expected_version'], 409, 'Setup changed. Reload the saved draft before finishing.');
            $values = $this->values($tenant, $this->draft($state)['values'] ?? [], true);
            $areaInput = $values['area'];
            $area = ! empty($areaInput['id']) ? $this->access->area($tenant, $user, $areaInput['id'], true) : $this->workspace->createArea($tenant, $user, $areaInput);
            $createdArea = $area->wasRecentlyCreated;
            $area = TeamArea::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($area->id);
            abort_unless(! empty($areaInput['id']) || $area->profile === $areaInput['profile'], 409, 'That area name already belongs to another work profile. Choose a different name.');
            abort_unless(! isset($areaInput['expected_version']) || $area->version === (int) $areaInput['expected_version'], 409, 'The selected work area changed. Review it before finishing setup.');
            $settings = $area->settings ?? [];
            $before = ['home_base' => data_get($metadata, 'teams.home_base'), 'area_version' => $area->version, 'settings' => $settings];
            if ($createdArea && empty($settings['timezone'])) {
                $settings['timezone'] = $values['home_base']['timezone'];
            }
            $settings['travel']['home_base'] = $values['home_base'];
            $settings['event_defaults'] = $values['event_defaults'] ?? ['teardown_minutes' => 60, 'arrival_buffer_minutes' => 0];
            $settings['default_member_ids'] = array_map('strval', $values['member_ids'] ?? []);
            $area->update(['settings' => $settings, 'version' => $area->version + 1]);
            $cost = $values['cost_policy'] ?? ['template' => 'skip'];
            $policy = null;
            if (($cost['template'] ?? 'skip') !== 'skip') {
                $definition = $this->policy($cost);
                $saved = app(TeamsStudio::class)->saveDefinition($tenant, $user, $area, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'kind' => 'cost_policy', 'publish' => false, 'definition' => $definition]);
                $policy = ['id' => $saved['id'], 'version' => $saved['version'], 'status' => 'draft'];
                $settings['setup_cost_policy_id'] = $saved['id'];
                $area->update(['settings' => $settings]);
            }
            $state['completed_at'] = now()->toIso8601String();
            $state['completed_by'] = (string) $user->id;
            $state['area_id'] = $area->id;
            $state['version'] = (int) ($state['version'] ?? 0) + 1;
            unset($state['draft'], $state['last_draft']);
            // saveDefinition may write operational data, never this metadata row.
            $metadata['teams']['setup'] = $state;
            $metadata['teams']['home_base'] = $values['home_base'];
            $metadata['teams']['event_defaults'] = $settings['event_defaults'];
            $profile->update(['metadata' => $metadata]);
            $result = ['area' => ['id' => $area->id, 'name' => $area->name, 'profile' => $area->profile, 'version' => $area->version], 'cost_policy' => $policy, 'connection_next' => $values['connection'] ?? 'later'];
            TeamOperationAttempt::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $user->id, 'kind' => 'teams.setup', 'status' => 'accepted', 'request_id' => $input['request_id'], 'input_hash' => $hash,
                'payload' => ['before' => $before, 'values' => $values, 'completed_at' => $state['completed_at']], 'result' => $result]);

            return $result + ['replayed' => false];
        }, 3);

        return $this->status($tenant, $user) + $completion;
    }

    private function lock(Tenant $tenant, User $user): TenantAccessProfile
    {
        DB::table('team_work_locks')->insertOrIgnore(['tenant_id' => $tenant->id]);
        DB::table('team_work_locks')->where('tenant_id', $tenant->id)->lockForUpdate()->first();
        $this->access->financial($tenant, $user);

        return TenantAccessProfile::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
    }

    private function draft(array $state): ?array
    {
        if (empty($state['draft'])) {
            return null;
        }
        try {
            return json_decode(Crypt::decryptString($state['draft']), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(503, 'The saved setup draft could not be opened. Refresh before changing it.');
        }
    }

    private function values(Tenant $tenant, array $input, bool $finishing = false): array
    {
        $values = Validator::make($input, ['home_base' => ($finishing ? 'required' : 'sometimes').'|array:address,timezone', 'home_base.address' => ($finishing ? 'required|string|min:3' : 'nullable|string').'|max:1000', 'home_base.timezone' => ($finishing ? 'required|timezone:all' : 'nullable|string|max:80'),
            'area' => ($finishing ? 'required' : 'sometimes').'|array:id,name,profile,expected_version', 'area.id' => 'nullable|uuid', 'area.name' => 'nullable|string|max:100', 'area.profile' => 'nullable|in:events,custom,wholesale', 'area.expected_version' => 'nullable'.($finishing ? '|required_with:area.id' : '').'|integer|min:1',
            'member_ids' => 'sometimes|array|list|max:100', 'member_ids.*' => 'integer|distinct', 'event_defaults' => 'sometimes|array:teardown_minutes,arrival_buffer_minutes', 'event_defaults.teardown_minutes' => 'sometimes|integer|min:0|max:1440', 'event_defaults.arrival_buffer_minutes' => 'sometimes|integer|min:0|max:1440',
            'cost_policy' => 'sometimes|array:template,name,currency,effective_on,hourly_rate_minor,mileage_rate_minor,per_diem_minor,commission_rate_bps', 'cost_policy.template' => 'required_with:cost_policy|in:skip,blank,modern_forestry', 'cost_policy.name' => 'nullable|string|max:100', 'cost_policy.currency' => 'nullable|regex:/^[A-Z]{3}$/D', 'cost_policy.effective_on' => 'nullable|date_format:Y-m-d',
            'cost_policy.hourly_rate_minor' => 'nullable|integer|min:0|max:10000000', 'cost_policy.mileage_rate_minor' => 'nullable|integer|min:0|max:10000000', 'cost_policy.per_diem_minor' => 'nullable|integer|min:0|max:10000000', 'cost_policy.commission_rate_bps' => 'nullable|integer|min:0|max:10000', 'connection' => 'sometimes|in:later,asana'])->validate();
        if (isset($values['area']['id'])) {
            TeamArea::where('tenant_id', $tenant->id)->findOrFail($values['area']['id']);
        } elseif ($finishing) {
            abort_unless(! empty($values['area']['name']) && ! empty($values['area']['profile']), 422, 'Choose an existing work area or give the new area a name and profile.');
            abort_unless($values['area']['profile'] !== 'wholesale' || $this->access->wholesaleEligible($tenant), 403, 'Enable the Teams Wholesale profile before creating that work area.');
        }
        $verified = $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->whereNotNull('users.email_verified_at')->pluck('users.id')->map(fn ($id) => (string) $id)->all();
        abort_unless(array_diff(array_map('strval', $values['member_ids'] ?? []), $verified) === [], 422, 'Choose current verified people from this company.');
        if (($values['cost_policy']['template'] ?? '') === 'modern_forestry') {
            abort_unless(config('team_operations.presets.'.$tenant->slug.'.events.compensation_template') === 'modern_forestry_criteria', 422, 'Choose a cost template configured for this company.');
        }
        if ($finishing && ($values['cost_policy']['template'] ?? 'skip') === 'blank') {
            abort_unless(! empty($values['cost_policy']['currency']) && ! empty($values['cost_policy']['effective_on']), 422, 'Choose the draft cost policy currency and effective date.');
        }
        if (isset($values['home_base']['address'])) {
            $values['home_base']['address'] = trim($values['home_base']['address']);
            abort_if($finishing && $values['home_base']['address'] === '', 422, 'Add the home-base address.');
        }
        if (isset($values['event_defaults'])) {
            $values['event_defaults'] = array_replace(['teardown_minutes' => 60, 'arrival_buffer_minutes' => 0], $values['event_defaults']);
        }
        abort_if(strlen(json_encode($values)) > 20000, 422, 'Keep setup to its essential details.');

        return $values;
    }

    private function policy(array $input): array
    {
        $policy = ($input['template'] ?? '') === 'modern_forestry' ? app(ModernForestryPolicy::class)->draft() : ['name' => 'Event cost policy — draft', 'currency' => $input['currency'], 'effective_on' => $input['effective_on'], 'required_categories' => [], 'requires_confirmation' => ['Review eligibility and minimum-pay rules before publishing.'], 'cost_types' => app(EventCostTypes::class)->starter(), 'rules' => []];
        foreach (['name', 'currency', 'effective_on'] as $key) {
            if (! empty($input[$key])) {
                $policy[$key] = $input[$key];
            }
        }
        $rates = ['hourly_rate_minor' => ['hourly', 'base_pay', 'staff.pay_type', 'eq', 'hourly'], 'commission_rate_bps' => ['commission', 'base_pay', 'staff.pay_type', 'eq', 'commission'], 'mileage_rate_minor' => ['mileage', 'mileage', 'trip.kind', 'in', ['studio_event', 'hotel_event', 'other']], 'per_diem_minor' => ['per_day', 'per_diem', 'day.event_minutes', 'exists', null]];
        foreach ($rates as $key => [$kind, $category, $field, $op, $value]) {
            if (! isset($input[$key])) {
                continue;
            }
            $matched = false;
            foreach ($policy['rules'] as &$rule) {
                if ($rule['effect']['kind'] === $kind) {
                    $rule['effect'][$kind === 'commission' ? 'rate_bps' : 'rate_minor'] = $input[$key];
                    $matched = true;
                }
            }
            unset($rule);
            if (! $matched) {
                $when = ['field' => $field, 'op' => $op];
                if ($op !== 'exists') {
                    $when['value'] = $value;
                }
                $policy['rules'][] = ['id' => 'setup_'.$kind, 'name' => 'Review '.str_replace('_', ' ', $kind).' eligibility', 'category' => $category, 'priority' => 10, 'when' => $when, 'effect' => ['kind' => $kind, $kind === 'commission' ? 'rate_bps' : 'rate_minor' => $input[$key]]];
            }
        }

        return $policy;
    }
}
